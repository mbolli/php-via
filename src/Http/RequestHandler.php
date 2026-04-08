<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\Adapter\PsrRequestFactory;
use Mbolli\PhpVia\Http\Adapter\PsrResponseEmitter;
use Mbolli\PhpVia\Http\Middleware\MiddlewareDispatcher;
use Mbolli\PhpVia\Http\Middleware\SseAwareMiddleware;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
    private PsrRequestFactory $psrRequestFactory;
    private PsrResponseEmitter $psrResponseEmitter;

    /**
     * Lazy brotli-compressed cache for static assets.
     * Keyed by file path; populated on first request and reused for worker lifetime.
     *
     * @var array<string, string>
     */
    private array $brotliCache = [];

    public function __construct(Via $via, SseHandler $sseHandler, ActionHandler $actionHandler) {
        $this->via = $via;
        $this->sseHandler = $sseHandler;
        $this->actionHandler = $actionHandler;
        $this->psrRequestFactory = new PsrRequestFactory();
        $this->psrResponseEmitter = new PsrResponseEmitter();
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

        // Detect basePath from X-Base-Path header (set by reverse proxy like Caddy).
        // Only trusted when Config::withTrustProxy(true) is set — otherwise clients
        // could inject arbitrary base paths.
        if ($this->via->getConfig()->getTrustProxy()) {
            $basePathHeader = $request->header['x-base-path'] ?? null;
            $this->via->getApp()->getConfig()->detectBasePathFromRequest($basePathHeader);
        }
        $this->via->getApp()->getTwig()->addGlobal('basePath', $this->via->getApp()->getConfig()->getBasePath());

        // Note: $_GET/$_POST/$_FILES are intentionally NOT set here.
        // Superglobals are shared across coroutines in OpenSwoole and cause
        // race conditions. Use $c->input() in actions or $request->get in handlers.

        // Handle HEAD requests without logging or rendering
        if ($method === 'HEAD') {
            $this->handleHeadRequest($path, $response);

            return;
        }

        // Serve Datastar.js
        if ($path === '/datastar.js') {
            $this->serveDatastarJs($request, $response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // Serve Via CSS
        if ($path === '/via.css') {
            $this->serveViaCss($request, $response);
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
                $this->serveStaticFile($realFile, $request, $response);
                $this->logRequest($method, $path, 200, $requestStart);

                return;
            }
        }

        // Handle SSE connection (logged separately by SseHandler)
        if ($path === '/_sse') {
            $this->handleSseWithMiddleware($request, $response);

            return;
        }

        // Handle action triggers (logged separately by ActionHandler)
        if (preg_match('#^/_action/(.+)$#', $path, $matches)) {
            $this->handleActionWithMiddleware($request, $response, $matches[1]);

            return;
        }

        // Handle session close
        if ($path === '/_session/close' && $method === 'POST') {
            $this->handleSessionClose($request, $response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // Handle stats endpoint (devMode only — exposes client IPs and memory usage)
        if ($path === '/_stats' && $method === 'GET') {
            if (!$this->via->getConfig()->getDevMode()) {
                $response->status(404);
                $response->end('Not Found');

                return;
            }

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
                    $this->handlePageWithMiddleware($request, $response, $route, $handler, $params, $method, $path, $requestStart);

                    return;
                }
            }
        }

        // 404 Not Found
        $this->logRequest($method, $path, 404, $requestStart);
        $response->status(404);
        $response->end('Not Found');
    }

    /**
     * Handle page rendering.
     *
     * @internal called by middleware pipeline core handler
     *
     * @param array<string, string> $params            Route parameters
     * @param array<string, mixed>  $requestAttributes PSR-7 request attributes from middleware
     */
    public function handlePage(Request $request, Response $response, string $route, callable $handler, array $params, string $method, string $path, int $requestStart, array $requestAttributes = []): void {
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

        // Make request cookies available to the page handler via $c->cookie()
        $context->setRequestCookies($request->cookie ?? []);

        // Bridge PSR-7 request attributes from middleware into Context
        if ($requestAttributes !== []) {
            $context->setRequestAttributes($requestAttributes);
        }

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

        // Apply any cookies queued by the page handler
        foreach ($context->flushPendingCookies() as $cookie) {
            $response->cookie(
                $cookie['name'],
                $cookie['value'],
                $cookie['expires'],
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httpOnly'],
                $cookie['sameSite'],
            );
        }

        $response->header('Content-Type', 'text/html; charset=utf-8');
        $this->sendCompressedPage($requestAttributes, $response, $html);
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
    private function handlePageWithMiddleware(Request $request, Response $response, string $route, callable $handler, array $params, string $method, string $path, int $requestStart): void {
        $globalMiddleware = $this->via->getGlobalMiddleware();
        $routeMiddleware = $this->via->getRouteMiddleware($route);
        $stack = array_merge($globalMiddleware, $routeMiddleware);

        // Fast path: no middleware registered, skip PSR-7 conversion entirely
        if ($stack === []) {
            $this->handlePage($request, $response, $route, $handler, $params, $method, $path, $requestStart);

            return;
        }

        // Build PSR-7 request and wrap the page handler as the core handler
        $psrRequest = $this->psrRequestFactory->create($request, 'page');

        // Capture variables needed by the core handler closure
        $via = $this->via;
        $self = $this;
        $coreHandler = new class($self, $request, $response, $route, $handler, $params, $method, $path, $requestStart) implements RequestHandlerInterface {
            private bool $handled = false;

            /**
             * @param array<string, string> $params
             */
            public function __construct(
                private RequestHandler $requestHandler,
                private Request $swooleRequest,
                private Response $swooleResponse,
                private string $route,
                /** @var callable */
                private mixed $pageHandler,
                private array $params,
                private string $method,
                private string $path,
                private int $requestStart,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->handled = true;
                // Pass PSR-7 attributes into the regular page handler
                $this->requestHandler->handlePage(
                    $this->swooleRequest,
                    $this->swooleResponse,
                    $this->route,
                    $this->pageHandler,
                    $this->params,
                    $this->method,
                    $this->path,
                    $this->requestStart,
                    $request->getAttributes(),
                );

                // Return a dummy response — the real response was already sent via OpenSwoole
                return new Psr7Response(200);
            }

            public function wasHandled(): bool {
                return $this->handled;
            }
        };

        $dispatcher = new MiddlewareDispatcher($stack, $coreHandler);
        $psrResponse = $dispatcher->handle($psrRequest);

        // If middleware short-circuited (core handler was never called), emit the PSR-7 response
        if (!$coreHandler->wasHandled()) {
            $this->psrResponseEmitter->emit($psrResponse, $response);
            $this->logRequest($method, $path, $psrResponse->getStatusCode(), $requestStart);
        }
    }

    /**
     * Run global middleware around action handling.
     */
    private function handleActionWithMiddleware(Request $request, Response $response, string $actionId): void {
        $globalMiddleware = $this->via->getGlobalMiddleware();

        // Fast path: no middleware, forward directly
        if ($globalMiddleware === []) {
            $this->actionHandler->handleAction($request, $response, $actionId);

            return;
        }

        $psrRequest = $this->psrRequestFactory->create($request, 'action');

        $actionHandler = $this->actionHandler;
        $coreHandler = new class($actionHandler, $request, $response, $actionId) implements RequestHandlerInterface {
            private bool $handled = false;

            public function __construct(
                private ActionHandler $actionHandler,
                private Request $swooleRequest,
                private Response $swooleResponse,
                private string $actionId,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->handled = true;
                $this->actionHandler->handleAction($this->swooleRequest, $this->swooleResponse, $this->actionId);

                return new Psr7Response(200);
            }

            public function wasHandled(): bool {
                return $this->handled;
            }
        };

        $dispatcher = new MiddlewareDispatcher($globalMiddleware, $coreHandler);
        $psrResponse = $dispatcher->handle($psrRequest);

        if (!$coreHandler->wasHandled()) {
            $this->psrResponseEmitter->emit($psrResponse, $response);
        }
    }

    /**
     * Run SSE-aware middleware around SSE handshake.
     */
    private function handleSseWithMiddleware(Request $request, Response $response): void {
        // Filter global middleware: only SseAwareMiddleware runs on SSE
        $sseMiddleware = array_values(array_filter(
            $this->via->getGlobalMiddleware(),
            fn (MiddlewareInterface $mw): bool => $mw instanceof SseAwareMiddleware,
        ));

        // Fast path: no SSE middleware, forward directly
        if ($sseMiddleware === []) {
            $this->sseHandler->handleSSE($request, $response);

            return;
        }

        $psrRequest = $this->psrRequestFactory->create($request, 'sse');

        $sseHandler = $this->sseHandler;
        $coreHandler = new class($sseHandler, $request, $response) implements RequestHandlerInterface {
            private bool $handled = false;

            public function __construct(
                private SseHandler $sseHandler,
                private Request $swooleRequest,
                private Response $swooleResponse,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->handled = true;

                /** @var null|(callable(string): string|false) $brotliWrite */
                $brotliWrite = $request->getAttribute('brotli_write');

                /** @var null|(callable(): string|false) $brotliFinish */
                $brotliFinish = $request->getAttribute('brotli_finish');
                $this->sseHandler->handleSSE($this->swooleRequest, $this->swooleResponse, $brotliWrite, $brotliFinish);

                return new Psr7Response(200);
            }

            public function wasHandled(): bool {
                return $this->handled;
            }
        };

        $dispatcher = new MiddlewareDispatcher($sseMiddleware, $coreHandler);
        $psrResponse = $dispatcher->handle($psrRequest);

        if (!$coreHandler->wasHandled()) {
            $this->psrResponseEmitter->emit($psrResponse, $response);
        }
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

        $json = json_encode($stats, JSON_PRETTY_PRINT);
        $response->header('Content-Type', 'application/json');

        // handleStats() is directly routed, not inside a middleware coreHandler,
        // so no PSR-7 attributes are available. Use brotli_compress() inline.
        if ($this->via->getConfig()->getBrotli() && str_contains($request->header['accept-encoding'] ?? '', 'br')) {
            $compressed = brotli_compress($json, $this->via->getConfig()->getBrotliDynamicLevel(), BROTLI_TEXT);
            if ($compressed !== false) {
                $response->header('Content-Encoding', 'br');
                $response->header('Vary', 'Accept-Encoding');
                $response->end($compressed);

                return;
            }
        }

        if ($this->via->getConfig()->getBrotli()) {
            $response->header('Vary', 'Accept-Encoding');
        }
        $response->end($json);
    }

    /**
     * Serve Datastar JavaScript file.
     */
    private function serveDatastarJs(Request $request, Response $response): void {
        $datastarJs = file_get_contents(__DIR__ . '/../../public/datastar.js');

        $response->header('Content-Type', 'application/javascript');
        $this->sendCompressedStatic($request, $response, $datastarJs, 'datastar.js', true);
    }

    /**
     * Serve a static file with correct Content-Type.
     */
    private function serveStaticFile(string $filePath, Request $request, Response $response): void {
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

        $compressible = match ($ext) {
            'css', 'js', 'svg', 'json', 'txt', 'html', 'xml' => true,
            default => false,
        };

        $response->header('Content-Type', $contentType);
        // 1 hour cache for static assets
        $response->header('Cache-Control', 'public, max-age=3600');

        $body = file_get_contents($filePath);
        if ($compressible) {
            $this->sendCompressedStatic($request, $response, $body, $filePath, true);
        } else {
            $response->end($body);
        }
    }

    /**
     * Serve Via CSS file.
     */
    private function serveViaCss(Request $request, Response $response): void {
        $viaCss = file_get_contents(__DIR__ . '/../../public/via.css');

        $response->header('Content-Type', 'text/css; charset=utf-8');
        $this->sendCompressedStatic($request, $response, $viaCss, 'via.css', true);
    }

    /**
     * Send a static asset body, applying Brotli compression from the lazy cache.
     *
     * Compresses at level BROTLI_COMPRESS_LEVEL_MAX on first request per file, then
     * serves from the in-memory cache on all subsequent requests at zero CPU cost.
     *
     * @param string $cacheKey Unique key for the brotli cache (file path or logical name)
     * @param bool   $text     Use BROTLI_TEXT mode (UTF-8 text) vs BROTLI_GENERIC (binary)
     */
    private function sendCompressedStatic(Request $request, Response $response, string $body, string $cacheKey, bool $text): void {
        if (!$this->via->getConfig()->getBrotli()) {
            $response->end($body);

            return;
        }

        $response->header('Vary', 'Accept-Encoding');

        if (!str_contains($request->header['accept-encoding'] ?? '', 'br')) {
            $response->end($body);

            return;
        }

        if (!isset($this->brotliCache[$cacheKey])) {
            $mode = $text ? BROTLI_TEXT : BROTLI_GENERIC;
            $compressed = brotli_compress($body, $this->via->getConfig()->getBrotliStaticLevel(), $mode);
            if ($compressed === false) {
                $response->end($body);

                return;
            }
            $this->brotliCache[$cacheKey] = $compressed;
        }

        $response->header('Content-Encoding', 'br');
        $response->end($this->brotliCache[$cacheKey]);
    }

    /**
     * Send a page HTML response, applying Brotli compression from PSR-7 request attributes
     * set by BrotliMiddleware (if present).
     *
     * @param array<string, mixed> $requestAttributes PSR-7 attributes forwarded from the middleware coreHandler
     */
    private function sendCompressedPage(array $requestAttributes, Response $response, string $html): void {
        /** @var null|(callable(string): string|false) $write */
        $write = $requestAttributes['brotli_write'] ?? null;

        /** @var null|(callable(): string|false) $finish */
        $finish = $requestAttributes['brotli_finish'] ?? null;

        if ($write !== null && $finish !== null) {
            $chunk = $write($html);
            $last = $finish();
            $compressed = ($chunk ?: '') . ($last ?: '');

            if ($compressed !== '') {
                $response->header('Content-Encoding', 'br');
                $response->header('Vary', 'Accept-Encoding');
                $response->end($compressed);

                return;
            }
        }

        if ($this->via->getConfig()->getBrotli()) {
            $response->header('Vary', 'Accept-Encoding');
        }
        $response->end($html);
    }
}
