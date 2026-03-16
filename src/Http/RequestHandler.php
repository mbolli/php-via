<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/**
 * Handles incoming HTTP requests and routes them appropriately.
 */
class RequestHandler {
    /** @var array<string, callable> */
    private array $routes = [];

    private Via $via;
    private SseHandler $sseHandler;
    private ActionHandler $actionHandler;
    private ?RequestLogger $requestLogger = null;

    public function __construct(Via $via, SseHandler $sseHandler, ActionHandler $actionHandler) {
        $this->via = $via;
        $this->sseHandler = $sseHandler;
        $this->actionHandler = $actionHandler;
    }

    public function setRequestLogger(RequestLogger $logger): void {
        $this->requestLogger = $logger;
    }

    /**
     * @param array<string, callable> $routes
     */
    public function setRoutes(array $routes): void {
        $this->routes = $routes;
    }

    /**
     * Handle incoming HTTP request.
     */
    public function handleRequest(Request $request, Response $response): void {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];
        $requestStart = hrtime(true);

        // Detect basePath from X-Base-Path header (set by reverse proxy like Caddy)
        $basePathHeader = $request->header['x-base-path'] ?? null;
        $this->via->getApp()->getConfig()->detectBasePathFromRequest($basePathHeader);
        $this->via->getApp()->getTwig()->addGlobal('basePath', $this->via->getApp()->getConfig()->getBasePath());

        // Populate superglobals for compatibility
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];
        $_FILES = $request->files ?? [];

        // Handle HEAD requests without logging or rendering
        if ($method === 'HEAD') {
            $this->handleHeadRequest($path, $response);

            return;
        }

        // Serve Datastar.js
        if ($path === '/datastar.js') {
            $this->serveDatastarJs($response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // Serve Via CSS
        if ($path === '/via.css') {
            $this->serveViaCss($response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // Serve static files from configured staticDir (if set)
        $staticDir = $this->via->getConfig()->getStaticDir();
        if ($staticDir !== null) {
            // Prevent directory traversal
            $relPath = ltrim(parse_url($path, PHP_URL_PATH) ?? '', '/');
            $filePath = $staticDir . '/' . $relPath;
            $realBase = realpath($staticDir);
            $realFile = realpath($filePath);

            if ($realBase !== false && $realFile !== false
                && str_starts_with($realFile, $realBase . '/')
                && is_file($realFile)) {
                $this->serveStaticFile($realFile, $response);
                $this->logRequest($method, $path, 200, $requestStart);

                return;
            }
        }

        // Handle SSE connection (logged separately by SseHandler)
        if ($path === '/_sse') {
            $this->sseHandler->handleSSE($request, $response);

            return;
        }

        // Handle action triggers (logged separately by ActionHandler)
        if (preg_match('#^/_action/(.+)$#', $path, $matches)) {
            $this->actionHandler->handleAction($request, $response, $matches[1]);

            return;
        }

        // Handle session close
        if ($path === '/_session/close' && $method === 'POST') {
            $this->handleSessionClose($request, $response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // Handle stats endpoint
        if ($path === '/_stats' && $method === 'GET') {
            $this->handleStats($request, $response);

            return;
        }

        // Handle page routes
        $params = [];
        $handler = $this->via->getRouter()->matchRoute($path, $params);
        if ($handler !== null) {
            // Extract route pattern from matched route
            foreach ($this->routes as $route => $h) {
                if ($h === $handler) {
                    $this->handlePage($request, $response, $route, $handler, $params, $method, $path, $requestStart);

                    return;
                }
            }
        }

        // 404 Not Found
        $this->logRequest($method, $path, 404, $requestStart);
        $response->status(404);
        $response->end('Not Found');
    }

    private function logRequest(string $method, string $path, int $statusCode, int $hrtimeStart): void {
        $durationUs = (hrtime(true) - $hrtimeStart) / 1000;
        $this->requestLogger?->logRequest($method, $path, $statusCode, $durationUs);
    }

    /**
     * Handle HEAD requests for route checking.
     */
    private function handleHeadRequest(string $path, Response $response): void {
        $params = [];
        $handler = $this->via->getRouter()->matchRoute($path, $params);
        if ($handler !== null) {
            $response->status(200);
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->end();

            return;
        }
        // Route not found
        $response->status(404);
        $response->end();
    }

    /**
     * Handle page rendering.
     *
     * @param array<string, string> $params Route parameters
     */
    private function handlePage(Request $request, Response $response, string $route, callable $handler, array $params, string $method, string $path, int $requestStart): void {
        // Get or create session ID
        $sessionId = $this->via->getSessionId($request);

        // Generate unique context ID
        $contextId = $route . '_/' . $this->via->generateId();

        // Create context with session ID
        $context = new Context($contextId, $route, $this->via, null, $sessionId);

        // Track session for this context
        $this->via->contextSessions[$contextId] = $sessionId;

        // Inject route parameters
        $context->injectRouteParams($params);

        // Execute the page handler with automatic parameter injection
        try {
            $this->via->invokeHandlerWithParams($handler, $context, $params);
        } catch (\Throwable $e) {
            $this->via->log(
                'error',
                'Page handler exception on ' . $route . ': ' . \get_class($e) . ': ' . $e->getMessage()
                . "\n" . $e->getTraceAsString()
            );
            $this->logRequest($method, $path, 500, $requestStart);
            $response->status(500);
            $response->end('Internal Server Error');

            return;
        }

        // Store context (in both legacy array and Application)
        $this->via->contexts[$contextId] = $context;
        $this->via->getApp()->registerContext($context);
        $this->via->getApp()->setContextSession($contextId, $sessionId);

        // Register context in its default TAB scope
        $this->via->registerContextInScope($context, Scope::TAB);

        // Build HTML document
        $html = $this->via->buildHtmlDocument($context);

        // Set session cookie
        $this->via->setSessionCookie($response, $sessionId);

        $this->logRequest($method, $path, 200, $requestStart);

        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end($html);
    }

    /**
     * Handle session close.
     */
    private function handleSessionClose(Request $request, Response $response): void {
        $contextId = $request->rawContent();

        if (isset($this->via->contexts[$contextId])) {
            // Don't immediately delete context - delay to allow page navigation
            // If SSE reconnects within timeout, context survives; otherwise it's cleaned up
            // This prevents reload loops during navigation
            $this->via->scheduleContextCleanup($contextId);

            $this->via->log('debug', "Context cleanup scheduled: {$contextId}");
        }

        $response->status(200);
        $response->end();
    }

    /**
     * Handle stats endpoint.
     */
    private function handleStats(Request $request, Response $response): void {
        $stats = [
            'contexts' => \count($this->via->contexts),
            'clients' => $this->via->getClients(),
            'render_stats' => $this->via->getRenderStats(),
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
        $datastarJs = file_get_contents(__DIR__ . '/../../public/datastar.js');

        $response->header('Content-Type', 'application/javascript');
        $response->end($datastarJs);
    }

    /**
     * Serve a static file with correct Content-Type.
     */
    private function serveStaticFile(string $filePath, Response $response): void {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $contentType = match ($ext) {
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            default => 'application/octet-stream',
        };

        $response->header('Content-Type', $contentType);
        // 1 hour cache for static assets
        $response->header('Cache-Control', 'public, max-age=3600');
        $response->end(file_get_contents($filePath));
    }

    /**
     * Serve Via CSS file.
     */
    private function serveViaCss(Response $response): void {
        $viaCss = file_get_contents(__DIR__ . '/../../public/via.css');

        $response->header('Content-Type', 'text/css; charset=utf-8');
        $response->end($viaCss);
    }
}
