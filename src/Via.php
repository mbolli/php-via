<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Via - Real-time engine for building reactive web applications in PHP.
 *
 * Main application class that manages routing, contexts, and SSE connections.
 */
class Via {
    private Server $server;

    /** @var array<string, callable> */
    private array $routes = [];

    /** @var array<string, Context> */
    private array $contexts = [];

    /** @var array<int, string> */
    private array $headIncludes = [];

    /** @var array<int, string> */
    private array $footIncludes = [];
    private Environment $twig;

    public function __construct(private Config $config) {
        $this->server = new Server($this->config->getHost(), $this->config->getPort());

        // Initialize Twig with appropriate loader
        if ($this->config->getTemplateDir()) {
            $loader = new FilesystemLoader($this->config->getTemplateDir());
        } else {
            $loader = new ArrayLoader([]);
        }
        
        $this->twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);

        // Add custom Twig functions for Via
        $this->addTwigFunctions();

        $this->server->on('start', function (Server $server): void {
            $this->log('info', "Via server started on {$this->config->getHost()}:{$this->config->getPort()}");
        });

        $this->server->on('request', function (Request $request, Response $response): void {
            $this->handleRequest($request, $response);
        });
    }

    /**
     * Get the configuration instance for fluent configuration.
     */
    public function config(): Config {
        return $this->config;
    }

    /**
     * Apply configuration changes (called internally after fluent config).
     */
    public function applyConfig(): void {
        // Update Twig loader if template directory is set
        if ($this->config->getTemplateDir()) {
            $loader = new FilesystemLoader($this->config->getTemplateDir());
            $this->twig->setLoader($loader);
        }
    }

    /**
     * Register a page route with its handler.
     *
     * @param string   $route   The route pattern (e.g., '/')
     * @param callable $handler Function that receives a Context instance
     */
    public function page(string $route, callable $handler): void {
        $this->routes[$route] = $handler;
    }

    /**
     * Broadcast sync to all contexts on a specific route.
     */
    public function broadcast(string $route): void {
        foreach ($this->contexts as $context) {
            if ($context->getRoute() === $route) {
                $context->sync();
            }
        }
    }

    /**
     * Register a custom HTTP handler.
     */
    public function handleFunc(string $pattern, callable $handler): void {
        $this->routes[$pattern] = $handler;
    }

    /**
     * Add elements to the document head.
     */
    public function appendToHead(string ...$elements): void {
        $this->headIncludes = array_merge($this->headIncludes, $elements);
    }

    /**
     * Add elements to the document footer.
     */
    public function appendToFoot(string ...$elements): void {
        $this->footIncludes = array_merge($this->footIncludes, $elements);
    }

    /**
     * Start the Via server.
     */
    public function start(): void {
        $this->server->start();
    }

    /**
     * Log message.
     */
    public function log(string $level, string $message, ?Context $context = null): void {
        $levels = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
        $configLevel = $levels[$this->config->getLogLevel()] ?? 1;

        if ($levels[$level] >= $configLevel) {
            $prefix = $context ? "[{$context->getId()}] " : '';
            echo '[' . mb_strtoupper($level) . "] {$prefix}{$message}\n";
        }
    }

    /**
     * Get context by ID.
     */
    public function getContext(string $id): ?Context {
        return $this->contexts[$id] ?? null;
    }

    /**
     * Get Twig environment.
     */
    public function getTwig(): Environment {
        return $this->twig;
    }

    /**
     * Handle incoming HTTP requests.
     */
    private function handleRequest(Request $request, Response $response): void {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];

        // Serve Datastar.js
        if ($path === '/_datastar.js') {
            $this->serveDatastarJs($response);

            return;
        }

        // Handle SSE connection
        if ($path === '/_sse') {
            $this->handleSSE($request, $response);

            return;
        }

        // Handle action triggers
        if (preg_match('#^/_action/(.+)$#', $path, $matches)) {
            $this->handleAction($request, $response, $matches[1]);

            return;
        }

        // Handle session close
        if ($path === '/_session/close' && $method === 'POST') {
            $this->handleSessionClose($request, $response);

            return;
        }

        // Handle page routes
        foreach ($this->routes as $route => $handler) {
            if ($this->matchRoute($route, $path)) {
                $this->handlePage($request, $response, $route, $handler);

                return;
            }
        }

        // 404 Not Found
        $response->status(404);
        $response->end('Not Found');
    }

    /**
     * Handle page rendering.
     */
    private function handlePage(Request $request, Response $response, string $route, callable $handler): void {
        // Generate unique context ID
        $contextId = $route . '_/' . $this->generateId();

        // Create context
        $context = new Context($contextId, $route, $this);

        // Execute the page handler
        $handler($context);

        // Store context
        $this->contexts[$contextId] = $context;

        // Build HTML document
        $html = $this->buildHtmlDocument($context);

        $response->header('Content-Type', 'text/html');
        $response->end($html);
    }

    /**
     * Handle SSE connection for real-time updates.
     */
    private function handleSSE(Request $request, Response $response): void {
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];

        // Get context ID from signals
        $signals = ServerSentEventGenerator::readSignals();
        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId || !isset($this->contexts[$contextId])) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $context = $this->contexts[$contextId];

        // Set SSE headers using Datastar SDK
        foreach (ServerSentEventGenerator::headers() as $name => $value) {
            $response->header($name, $value);
        }

        $this->log('debug', "SSE connection established for context: {$contextId}");

        // Create SSE generator
        $sse = new ServerSentEventGenerator();

        // Send initial signals
        $initialSignals = $context->prepareSignalsForPatch();
        if (!empty($initialSignals)) {
            $output = $sse->patchSignals($initialSignals);
            $response->write($output);
        }

        // Keep connection alive and listen for patches
        while (true) {
            if (!$response->isWritable()) {
                break;
            }

            // Check for patches from the context
            $patch = $context->getPatch();
            if ($patch) {
                error_log("SSE: Got patch of type: " . ($patch['type'] ?? 'unknown'));
                try {
                    $output = $this->sendSSEPatch($sse, $patch);
                    error_log("SSE: About to write " . strlen($output) . " bytes");
                    $response->write($output);
                    error_log("SSE: Write completed");
                } catch (\Throwable $e) {
                    error_log("SSE: Error sending patch: " . $e->getMessage());
                }
            }

            Coroutine::sleep(0.1);
        }

        $this->log('debug', "SSE connection closed for context: {$context->getId()}");
    }

    /**
     * Handle action triggers from the client.
     */
    private function handleAction(Request $request, Response $response, string $actionId): void {
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];
        
        // Read signals from request using Datastar SDK
        $signals = ServerSentEventGenerator::readSignals();

        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId || !isset($this->contexts[$contextId])) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $context = $this->contexts[$contextId];

        try {
            // Inject signals into context
            $context->injectSignals($signals);

            // Execute the action
            $context->executeAction($actionId);

            $response->status(200);
            $response->end();
        } catch (\Exception $e) {
            $this->log('error', "Action {$actionId} failed: " . $e->getMessage());
            $response->status(500);
            $response->end('Action failed');
        }
    }

    /**
     * Handle session close.
     */
    private function handleSessionClose(Request $request, Response $response): void {
        $contextId = $request->rawContent();

        if (isset($this->contexts[$contextId])) {
            unset($this->contexts[$contextId]);
            $this->log('debug', "Context closed: {$contextId}");
        }

        $response->status(200);
        $response->end();
    }

    /**
     * Serve Datastar JavaScript file.
     */
    private function serveDatastarJs(Response $response): void {
        // In a real implementation, embed the datastar.js file
        $datastarJs = file_get_contents(__DIR__ . '/../datastar.js');

        $response->header('Content-Type', 'application/javascript');
        $response->end($datastarJs);
    }

    /**
     * Send SSE patch to client using Datastar SDK.
     *
     * @param array{type: string, content: mixed, selector?: string} $patch
     */
    private function sendSSEPatch(ServerSentEventGenerator $sse, array $patch): string {
        $type = $patch['type'];
        $content = $patch['content'];
        $selector = $patch['selector'] ?? null;

        $contentInfo = is_string($content) ? "length=" . strlen($content) : "array_keys=" . implode(',', array_keys($content));
        error_log("sendSSEPatch: type=$type, selector=" . ($selector ?? 'null') . ", content_$contentInfo");

        $result = match ($type) {
            'elements' => $sse->patchElements($content, $selector ? ['selector' => $selector] : []),
            'signals' => $sse->patchSignals($content),
            'script' => $sse->executeScript($content),
            default => ''
        };

        error_log("sendSSEPatch: result_length=" . strlen($result));
        return $result;
    }

    /**
     * Build complete HTML document.
     */
    private function buildHtmlDocument(Context $context): string {
        $contextId = $context->getId();
        $title = $this->config->getDocumentTitle();

        $headContent = implode("\n", $this->headIncludes);
        $footContent = implode("\n", $this->footIncludes);

        return <<<HTML
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <script type="module" src="/_datastar.js"></script>
    <meta data-signals='{"via_ctx":"{$contextId}"}'>
    <meta data-init="@get('/_sse')">
    <meta data-init="window.addEventListener('beforeunload', (evt) => { navigator.sendBeacon('/_session/close', '{$contextId}'); });">
    {$headContent}
</head>
<body>
    {$context->renderView()}
    {$footContent}
</body>
</html>
HTML;
    }

    /**
     * Match route pattern.
     */
    private function matchRoute(string $route, string $path): bool {
        return $route === $path;
    }

    /**
     * Generate random ID.
     */
    private function generateId(): string {
        return bin2hex(random_bytes(8));
    }

    /**
     * Add custom Twig functions for Via.
     */
    private function addTwigFunctions(): void {
        // Add signal binding function
        $this->twig->addFunction(new TwigFunction('bind', fn (Signal $signal) => new Markup($signal->bind(), 'html')));
    }
}
