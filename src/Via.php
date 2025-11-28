<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Timer;
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

    /** @var array<string, array<string, callable>> Route-level actions */
    private array $routeActions = [];

    /** @var array<string, Context> */
    private array $contexts = [];

    /** @var array<string, int> Cleanup timer IDs for contexts */
    private array $cleanupTimers = [];

    /** @var array<string, array{id: string, identicon: string, connected_at: int, ip: string}> Client info by context ID */
    private array $clients = [];

    /** @var array{render_count: int, total_time: float, min_time: float, max_time: float} */
    private array $renderStats = ['render_count' => 0, 'total_time' => 0.0, 'min_time' => PHP_FLOAT_MAX, 'max_time' => 0.0];

    /** @var array<string, string> Route-based view cache (route -> html) */
    private array $viewCache = [];

    /** @var array<string, bool> Tracks if route is currently rendering (prevents race condition) */
    private array $rendering = [];

    /** @var array<int, string> */
    private array $headIncludes = [];

    /** @var array<int, string> */
    private array $footIncludes = [];
    private Environment $twig;

    public function __construct(private Config $config) {
        $this->server = new Server($this->config->getHost(), $this->config->getPort(), SWOOLE_BASE);

        // Configure Swoole for SSE streaming
        $this->server->set([
            'open_http2_protocol' => false,
            'http_compression' => false,
            'buffer_output_size' => 0,   // NO OUTPUT BUFFERING
            'socket_buffer_size' => 1024 * 1024,
            'max_coroutine' => 100000,
            'worker_num' => 1,   // Single worker = shared state (clients, render stats)
            'send_yield' => true,
        ]);

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
     * Check if view caching is enabled.
     */
    public function isViewCacheEnabled(): bool {
        return $this->config->getViewCache();
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
        // Invalidate cache so next render is fresh
        $this->invalidateViewCache($route);

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
     * Get all connected clients.
     *
     * @return array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}>
     */
    public function getClients(): array {
        $clients = [];
        foreach ($this->clients as $contextId => $client) {
            $clients[$contextId] = array_merge($client, ['context_id' => $contextId]);
        }

        return $clients;
    }

    /**
     * Get render statistics.
     *
     * @return array{render_count: int, total_time: float, min_time: float, max_time: float, avg_time: float}
     */
    public function getRenderStats(): array {
        $stats = $this->renderStats;
        $stats['avg_time'] = $stats['render_count'] > 0 ? $stats['total_time'] / $stats['render_count'] : 0.0;
        if ($stats['min_time'] === PHP_FLOAT_MAX) {
            $stats['min_time'] = 0.0;
        }

        return $stats;
    }

    /**
     * Track view render time.
     */
    public function trackRender(float $duration): void {
        $this->renderStats['render_count']++;
        $this->renderStats['total_time'] += $duration;
        $this->renderStats['min_time'] = min($this->renderStats['min_time'], $duration);
        $this->renderStats['max_time'] = max($this->renderStats['max_time'], $duration);
    }

    /**
     * Get cached view HTML for a route if available and fresh.
     */
    public function getCachedView(string $route): ?string {
        return $this->viewCache[$route] ?? null;
    }

    /**
     * Cache rendered view HTML for a route.
     */
    public function cacheView(string $route, string $html): void {
        $this->viewCache[$route] = $html;
    }

    /**
     * Check if route is currently rendering.
     */
    public function isRendering(string $route): bool {
        return $this->rendering[$route] ?? false;
    }

    /**
     * Set rendering status for route.
     */
    public function setRendering(string $route, bool $status): void {
        if ($status) {
            $this->rendering[$route] = true;
        } else {
            unset($this->rendering[$route]);
        }
    }

    /**
     * Invalidate view cache for a route (called on broadcast).
     */
    public function invalidateViewCache(string $route): void {
        unset($this->viewCache[$route]);
    }

    /**
     * Get Twig environment.
     */
    public function getTwig(): Environment {
        return $this->twig;
    }

    /**
     * Register a route-level action (shared across all contexts on this route).
     */
    public function registerRouteAction(string $route, string $actionId, callable $handler): void {
        if (!isset($this->routeActions[$route])) {
            $this->routeActions[$route] = [];
        }
        $this->routeActions[$route][$actionId] = $handler;
    }

    /**
     * Get route action handler.
     */
    public function getRouteAction(string $route, string $actionId): ?callable {
        return $this->routeActions[$route][$actionId] ?? null;
    }

    /**
     * Read Datastar signals from a Swoole HTTP request.
     *
     * This is a replacement for ServerSentEventGenerator::readSignals() which only checks
     * $_GET['datastar'] and php://input, but doesn't handle $_POST.
     * In Swoole, POST requests need special handling since we use $request->post instead of $_POST.
     *
     * @return array<string, mixed> The decoded signals array
     */
    private static function readSignals(Request $request): array {
        // Check GET parameters first
        if (isset($request->get['datastar'])) {
            $signals = json_decode($request->get['datastar'], true);

            return \is_array($signals) ? $signals : [];
        }

        // Fall back to raw request body
        $rawContent = $request->getContent();
        if ($rawContent) {
            $signals = json_decode($rawContent, true);

            return \is_array($signals) ? $signals : [];
        }

        return [];
    }

    /**
     * Handle incoming HTTP requests.
     */
    private function handleRequest(Request $request, Response $response): void {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];

        // Populate superglobals for compatibility
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];

        $this->log('debug', "Request: {$method} {$path}");

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

        // Handle stats endpoint
        if ($path === '/_stats' && $method === 'GET') {
            $this->handleStats($request, $response);

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
        // Get context ID from signals
        $signals = self::readSignals($request);
        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $sse = new ServerSentEventGenerator();
        // Set SSE headers using Datastar SDK
        foreach (ServerSentEventGenerator::headers() as $name => $value) {
            $response->header($name, $value);
        }

        // If context doesn't exist, it was cleaned up
        // Use Datastar SDK to send reload script
        if (!isset($this->contexts[$contextId])) {
            $this->log('info', "Context expired, sending reload: {$contextId}");

            // Use Datastar's executeScript to reload
            $output = $sse->executeScript('window.location.reload()');
            $response->write($output);
            $response->end();

            return;
        }

        $context = $this->contexts[$contextId];

        // Track client info when SSE connects (not at page load)
        if (!isset($this->clients[$contextId])) {
            $clientId = $this->generateClientId();
            $this->clients[$contextId] = [
                'id' => $clientId,
                'identicon' => $this->generateIdenticon($clientId),
                'connected_at' => time(),
                'ip' => $request->server['remote_addr'] ?? 'unknown',
            ];
        }

        $this->log('debug', "SSE connection established for context: {$contextId}");

        // Cancel any pending cleanup timer for this context (reconnection)
        if (isset($this->cleanupTimers[$contextId])) {
            Timer::clear($this->cleanupTimers[$contextId]);
            unset($this->cleanupTimers[$contextId]);
            $this->log('debug', "Cancelled cleanup timer for reconnected context: {$contextId}");
        }

        // Send initial sync (view + signals) on connection/reconnection
        // Do this synchronously to ensure patches are ready before the loop starts
        $context->sync();

        // Keep connection alive and listen for patches
        $lastKeepalive = time();
        while (true) {
            if (!$response->isWritable()) {
                break;
            }

            // Check for patches from the context
            $patch = $context->getPatch();
            if ($patch) {
                try {
                    $output = $this->sendSSEPatch($sse, $patch);
                    if (!$response->write($output)) {
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->log('debug', 'Patch write exception, client disconnected: ' . $e->getMessage(), $context);

                    break;
                }
            }

            // Send keepalive comment every 30 seconds to prevent timeout
            if (time() - $lastKeepalive >= 30) {
                try {
                    if (!$response->write(": keepalive\n\n")) {
                        break;
                    }
                    $lastKeepalive = time();
                } catch (\Throwable $e) {
                    break;
                }
            }

            Coroutine::sleep(0.1);
        }

        $this->log('debug', "SSE connection closed for context: {$context->getId()}");

        // Don't immediately cleanup - give client time to reconnect (e.g., tab switching)
        // Schedule cleanup after 60 seconds of inactivity
        $timerId = Timer::after(60000, function () use ($contextId): void {
            if (isset($this->contexts[$contextId])) {
                $this->log('debug', "Cleaning up inactive context: {$contextId}");
                $this->contexts[$contextId]->cleanup();
                unset($this->contexts[$contextId], $this->clients[$contextId], $this->cleanupTimers[$contextId]);
            }
        });

        $this->cleanupTimers[$contextId] = $timerId;
    }

    /**
     * Handle action triggers from the client.
     */
    private function handleAction(Request $request, Response $response, string $actionId): void {
        // Read signals from request
        $signals = self::readSignals($request);

        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId || !isset($this->contexts[$contextId])) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $context = $this->contexts[$contextId];
        $route = $context->getRoute();

        // Check for route-level action first
        $routeHandler = $this->getRouteAction($route, $actionId);
        if ($routeHandler !== null) {
            try {
                // Inject signals into context
                $context->injectSignals($signals);

                $this->log('debug', "Executing route action {$actionId} for route {$route}");

                // Execute the route-level action with context
                $routeHandler($context);

                $this->log('debug', "Route action {$actionId} completed successfully");

                $response->status(200);
                $response->end();

                return;
            } catch (\Exception $e) {
                $this->log('error', "Route action {$actionId} failed: " . $e->getMessage());
                $response->status(500);
                $response->end('Action failed');

                return;
            }
        }

        try {
            // Inject signals into context
            $context->injectSignals($signals);

            $this->log('debug', "Executing action {$actionId} for context {$contextId}");

            // Execute the context-level action
            $context->executeAction($actionId);

            $this->log('debug', "Action {$actionId} completed successfully");

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
            unset($this->contexts[$contextId], $this->clients[$contextId]);

            $this->log('debug', "Context closed: {$contextId}");
        }

        $response->status(200);
        $response->end();
    }

    /**
     * Handle stats endpoint.
     */
    private function handleStats(Request $request, Response $response): void {
        $stats = [
            'contexts' => \count($this->contexts),
            'clients' => $this->getClients(),
            'render_stats' => $this->getRenderStats(),
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
            ],
            'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
        ];

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($stats, JSON_PRETTY_PRINT));
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
     * @param array{type: string, content: mixed, selector?: string, mode?: ElementPatchMode} $patch
     */
    private function sendSSEPatch(ServerSentEventGenerator $sse, array $patch): string {
        $type = $patch['type'];
        $content = $patch['content'];
        $selector = $patch['selector'] ?? null;
        $mode = $patch['mode'] ?? null;

        return match ($type) {
            'elements' => $sse->patchElements($content, array_filter([
                'selector' => $selector,
                'mode' => $mode,
            ])),
            'signals' => $sse->patchSignals($content),
            'script' => $sse->executeScript($content),
            default => ''
        };
    }

    /**
     * Build complete HTML document.
     */
    private function buildHtmlDocument(Context $context): string {
        $contextId = $context->getId();
        $title = $this->config->getDocumentTitle();

        $headContent = implode("\n", $this->headIncludes);
        $footContent = implode("\n", $this->footIncludes);

        $content = $context->renderView();

        // If it's a full page (already processed by processView), return it
        if (stripos($content, '<html') !== false) {
            return $content;
        }

        // Use the shell template for fragments
        $signalsJson = json_encode([
            'via_ctx' => $contextId,
            '_disconnected' => false,
        ]);

        // Simple template replacement for the shell
        $shellPath = $this->config->getShellTemplate() ?? __DIR__ . '/../shell.html';
        $shell = file_get_contents($shellPath);
        
        return str_replace(
            [
                '{{ title }}',
                '{{ signals_json }}',
                '{{ context_id }}',
                '{{ head_content }}',
                '{{ content }}',
                '{{ foot_content }}'
            ],
            [
                $title,
                $signalsJson,
                $contextId,
                $headContent,
                $content,
                $footContent
            ],
            $shell
        );
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
     * Generate unique client ID.
     */
    private function generateClientId(): string {
        return bin2hex(random_bytes(4)); // 8 char hex
    }

    /**
     * Generate SVG identicon based on client ID.
     * Creates a 5x5 symmetric pattern.
     */
    private function generateIdenticon(string $clientId): string {
        // Use client ID to seed colors and pattern
        $hash = hash('sha256', $clientId);

        // Extract color from hash
        $hue = hexdec(substr($hash, 0, 2)) / 255 * 360;
        $color = "hsl({$hue}, 70%, 50%)";
        $bgColor = "hsl({$hue}, 70%, 90%)";

        // Generate 5x5 pattern (symmetric, so only need 3 columns)
        $size = 5;
        $cells = [];
        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < 3; ++$x) {
                $index = $y * 3 + $x;
                $cells[$y][$x] = (bool) (hexdec($hash[$index % 64]) % 2);
            }
        }

        // Build SVG
        $cellSize = 20;
        $svgSize = $size * $cellSize;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $svgSize . '" height="' . $svgSize . '" viewBox="0 0 ' . $svgSize . ' ' . $svgSize . '">';
        $svg .= '<rect width="' . $svgSize . '" height="' . $svgSize . '" fill="' . $bgColor . '"/>';

        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                // Mirror pattern
                $cellX = $x < 3 ? $x : 4 - $x;
                if ($cells[$y][$cellX]) {
                    $posX = $x * $cellSize;
                    $posY = $y * $cellSize;
                    $svg .= '<rect x="' . $posX . '" y="' . $posY . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="' . $color . '"/>';
                }
            }
        }

        $svg .= '</svg>';

        // Return base64 data URI
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Add custom Twig functions for Via.
     */
    private function addTwigFunctions(): void {
        // Add signal binding function
        $this->twig->addFunction(new TwigFunction('bind', fn (Signal $signal) => new Markup($signal->bind(), 'html')));
    }
}
