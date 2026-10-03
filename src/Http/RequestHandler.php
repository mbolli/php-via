<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\DevBar\DevBarController;
use Mbolli\PhpVia\Http\Adapter\PsrRequestFactory;
use Mbolli\PhpVia\Http\Adapter\PsrResponseEmitter;
use Mbolli\PhpVia\Http\Middleware\MiddlewareDispatcher;
use Mbolli\PhpVia\Http\Middleware\SseAwareMiddleware;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\ConditionalGet;
use Mbolli\PhpVia\Support\DatastarBundle;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Tracing\Tracer;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Runtime;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Handles incoming HTTP requests and routes them appropriately.
 */
class RequestHandler {
    /** Static files up to this size are served from memory; larger ones go out with sendfile(). */
    private const int STATIC_CACHE_FILE_BYTES = 2 << 20;

    /** Memory a worker spends on uncompressed static file bodies; files past it go out with sendfile(). */
    private const int STATIC_CACHE_TOTAL_BYTES = 16 << 20;

    /**
     * Content type, and whether Brotli pays off, by static file extension. Fonts other than ttf and otf, images other
     * than svg and ico, audio, video and PDF are compressed already.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const array STATIC_TYPES = [
        'css' => ['text/css; charset=utf-8', true],
        'js' => ['application/javascript', true],
        'mjs' => ['application/javascript', true],
        'json' => ['application/json', true],
        'map' => ['application/json', true],
        'webmanifest' => ['application/manifest+json', true],
        'wasm' => ['application/wasm', true],
        'svg' => ['image/svg+xml', true],
        'ico' => ['image/x-icon', true],
        'ttf' => ['font/ttf', true],
        'otf' => ['font/otf', true],
        'txt' => ['text/plain; charset=utf-8', true],
        'md' => ['text/markdown; charset=utf-8', true],
        'csv' => ['text/csv; charset=utf-8', true],
        'html' => ['text/html; charset=utf-8', true],
        'htm' => ['text/html; charset=utf-8', true],
        'xml' => ['application/xml', true],
        'rss' => ['application/rss+xml', true],
        'atom' => ['application/atom+xml', true],
        'png' => ['image/png', false],
        'jpg' => ['image/jpeg', false],
        'jpeg' => ['image/jpeg', false],
        'webp' => ['image/webp', false],
        'gif' => ['image/gif', false],
        'avif' => ['image/avif', false],
        'woff2' => ['font/woff2', false],
        'woff' => ['font/woff', false],
        'pdf' => ['application/pdf', false],
        'mp4' => ['video/mp4', false],
        'webm' => ['video/webm', false],
        'mp3' => ['audio/mpeg', false],
    ];

    /** Extensions never served from the static dir, so a PHP file put there by mistake does not leak its source. */
    private const string REFUSED_EXTENSIONS = '/^(?:php\d?|phps|phpt|pht|phtml|phar|inc)$/i';

    /** @var array<string, callable> */
    private array $routes = [];

    private Via $via;
    private SseHandler $sseHandler;
    private ActionHandler $actionHandler;
    private ?RequestLogger $requestLogger = null;
    private PsrRequestFactory $psrRequestFactory;
    private PsrResponseEmitter $psrResponseEmitter;
    private ?DevBarController $devBar = null;
    private StaticBrotli $staticBrotli;

    /** Uncompressed static file bodies, kept while the file's mtime and size match. */
    private StaticBodyCache $staticCache;

    /** @var null|array{0: string, 1: string} The configured static dir and its realpath, resolved once */
    private ?array $staticBase = null;

    public function __construct(Via $via, SseHandler $sseHandler, ActionHandler $actionHandler, ?StaticBrotli $staticBrotli = null) {
        $this->via = $via;
        $this->sseHandler = $sseHandler;
        $this->actionHandler = $actionHandler;
        $this->staticBrotli = $staticBrotli ?? new StaticBrotli($via->getConfig(), $via->log(...));
        $this->psrRequestFactory = new PsrRequestFactory();
        $this->psrResponseEmitter = new PsrResponseEmitter();
        $this->staticCache = new StaticBodyCache(self::STATIC_CACHE_TOTAL_BYTES, self::STATIC_CACHE_FILE_BYTES);
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
     * Handle incoming HTTP request. A throw that escapes routing answers 500 instead of
     * killing the worker and every context on it.
     */
    public function handleRequest(Request $request, Response $response): void {
        try {
            $this->dispatch($request, $response);
        } catch (\Throwable $e) {
            $path = (string) ($request->server['request_uri'] ?? '');
            $this->via->log('error', "Unhandled exception on {$path}: " . Logger::describe($e));

            if (!$response->isWritable()) {
                return;
            }

            try {
                // After a write OpenSwoole keeps the sent status, so this only matters before one.
                $response->status(500);
                $response->end($path === '/_sse' ? null : 'Internal Server Error');
            } catch (\Throwable) {
                // Connection already gone.
            }
        }
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
        // Open a Dev Bar trace for this page request (no-op when tracing is off).
        // render.regions spans nest under it automatically via the ambient tracer.
        $tracer = $this->via->getTracer();
        $traceStarted = $tracer !== null && $tracer->startTrace($method . ' ' . $route, 'request');
        if ($traceStarted) {
            $tracer->setAttribute('http.method', $method);
            $tracer->setAttribute('http.route', $route);
            $tracer->setAttribute('http.target', $path);
        }

        try {
            $this->doHandlePage($request, $response, $route, $handler, $params, $method, $path, $requestStart, $requestAttributes, $tracer);
        } finally {
            if ($traceStarted) {
                $tracer->endTrace();
            }
        }
    }

    /**
     * A static file's content type, and whether Brotli pays off for it.
     *
     * @return array{0: string, 1: bool}
     *
     * @internal
     */
    public static function staticType(string $filePath): array {
        return self::STATIC_TYPES[strtolower(pathinfo($filePath, PATHINFO_EXTENSION))] ?? ['application/octet-stream', false];
    }

    /**
     * The path of php-via's own stylesheet, served at /via.css.
     *
     * @internal
     */
    public static function viaCssPath(): string {
        return \dirname(__DIR__, 2) . '/public/via.css';
    }

    /**
     * Whether a path relative to the static dir may be served: no NUL byte, no segment that starts with a dot
     * (dotfiles and dot directories, '.' and '..') but a leading .well-known, and no PHP source.
     *
     * @internal
     */
    public static function servableStaticPath(string $relative): bool {
        if (str_contains($relative, "\0")) {
            return false;
        }
        foreach (explode('/', $relative) as $i => $segment) {
            if (str_starts_with($segment, '.') && !($i === 0 && $segment === '.well-known')) {
                return false;
            }
        }

        return preg_match(self::REFUSED_EXTENSIONS, pathinfo($relative, PATHINFO_EXTENSION)) !== 1;
    }

    /**
     * End a response with $body, or for HEAD with only its length.
     *
     * @internal also used by the Dev Bar's assets
     */
    public static function endWithBody(Request $request, Response $response, string $body): void {
        if (self::isHead($request)) {
            self::endHead($response, \strlen($body));

            return;
        }

        $response->end($body);
    }

    private function dispatch(Request $request, Response $response): void {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];
        $requestStart = hrtime(true);

        // Note: $_GET/$_POST/$_FILES are intentionally NOT set here.
        // Superglobals are shared across coroutines in OpenSwoole and cause
        // race conditions. Use $c->input() in actions or $request->get in handlers.

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

        // Serve static files from configured staticDir (if set). Only a path that looks like a file
        // is looked up before routing; any other only once no route matched.
        $staticDir = $this->via->getConfig()->getStaticDir();
        $staticFirst = $staticDir !== null && self::looksLikeStaticFile($path);
        if ($staticFirst && ($realFile = $this->resolveStaticFile($staticDir, $path)) !== null) {
            $this->serveStaticFile($realFile, $request, $response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // Anything else answers HEAD without rendering
        if ($method === 'HEAD') {
            $this->handleHeadRequest($path, $request, $response, $staticDir !== null && !$staticFirst ? $staticDir : null);

            return;
        }

        // Handle SSE connection (logged separately by SseHandler)
        if ($path === '/_sse') {
            $this->handleSseWithMiddleware($request, $response);

            return;
        }

        // Handle action triggers (logged separately by ActionHandler)
        if (preg_match('#^/_action/(.+)$#', $path, $matches)) {
            // State-changing actions must not be invocable via GET browser navigation
            // (top-level cross-site navigation CSRF).  Allow POST/PATCH/PUT/DELETE;
            // non-GET safe methods all trigger CORS preflight in browsers.
            // Note: HEAD is answered above and never reaches this point.
            if ($method === 'GET') {
                self::methodNotAllowed($request, $response, 'POST');

                return;
            }

            $this->handleActionWithMiddleware($request, $response, $matches[1]);

            return;
        }

        // Handle session close
        if ($path === '/_session/close' && $method === 'POST') {
            $status = $this->handleSessionClose($request, $response);
            $this->logRequest($method, $path, $status, $requestStart);

            return;
        }

        // Handle stats endpoint (devMode only: exposes client IPs and memory usage)
        if ($path === '/_stats' && $method === 'GET') {
            if (!$this->via->getConfig()->isDevMode()) {
                $response->status(404);
                $response->end('Not Found');

                return;
            }

            $this->handleStats($request, $response);

            return;
        }

        // Health endpoint: always available, no sensitive data
        if ($path === '/_health' && $method === 'GET') {
            $this->handleHealth($request, $response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // Dev Bar endpoints (/_via/*, /_traces), gated on tracing being enabled.
        // 404 when disabled so production never advertises the surface.
        if ($path === '/_via' || $path === '/_traces' || str_starts_with($path, '/_via/')) {
            if (!$this->via->getConfig()->isTracingEnabled()) {
                $response->status(404);
                $response->end('Not Found');

                return;
            }

            // Mutating endpoints are POST; everything else is GET.
            $expectsPost = $path === '/_via/signal' || $path === '/_via/reset';
            if ($expectsPost ? $method !== 'POST' : $method !== 'GET') {
                $response->status(405);
                $response->header('Allow', $expectsPost ? 'POST' : 'GET');
                $response->end('Method Not Allowed');

                return;
            }

            $this->devBar ??= new DevBarController($this->via, staticBrotli: $this->staticBrotli);
            $this->devBar->handle($path, $request, $response);
            // The SSE stream logs its own lifecycle; log the rest here.
            if ($path !== '/_via/stream') {
                $this->logRequest($method, $path, 200, $requestStart);
            }

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

        // An extension-less static file, such as an ACME challenge token
        if ($staticDir !== null && !$staticFirst && ($realFile = $this->resolveStaticFile($staticDir, $path)) !== null) {
            $this->serveStaticFile($realFile, $request, $response);
            $this->logRequest($method, $path, 200, $requestStart);

            return;
        }

        // 404 Not Found
        $this->logRequest($method, $path, 404, $requestStart);
        $notFoundHandler = $this->via->getNotFoundHandler();
        if ($notFoundHandler !== null) {
            ($notFoundHandler)($request, $response);

            return;
        }
        $response->status(404);
        $response->end('Not Found');
    }

    /**
     * Core page handling, wrapped by {@see handlePage()} for tracing.
     *
     * @param array<string, string> $params            Route parameters
     * @param array<string, mixed>  $requestAttributes PSR-7 request attributes from middleware
     */
    private function doHandlePage(Request $request, Response $response, string $route, callable $handler, array $params, string $method, string $path, int $requestStart, array $requestAttributes, ?Tracer $tracer): void {
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

        // Bridge PSR-7 request attributes from middleware into Context, minus the response's
        // Brotli writers, which belong to this response and not to the tab.
        $contextAttributes = array_diff_key($requestAttributes, ['brotli_write' => true, 'brotli_finish' => true]);
        if ($contextAttributes !== []) {
            $context->setRequestAttributes($contextAttributes);
        }

        try {
            $this->via->invokeHandlerWithParams($handler, $context, $params);
        } catch (\Throwable $e) {
            $this->discardContext($context);
            $this->failPage('Page handler exception on ', $route, $e, $tracer, $method, $path, $requestStart, $response);

            return;
        }

        // Store context (in both legacy array and Application)
        $this->via->contexts[$contextId] = $context;
        $this->via->getApp()->registerContext($context);
        $this->via->getApp()->setContextSession($contextId, $sessionId);

        // Register context in its default TAB scope
        $this->via->registerContextInScope($context, Scope::TAB);

        try {
            $html = $this->via->buildHtmlDocument($context);
        } catch (\Throwable $e) {
            $this->discardContext($context);
            $this->failPage('Page render exception on ', $route, $e, $tracer, $method, $path, $requestStart, $response);

            return;
        }

        // A page whose SSE stream never connects (a crawler, a prefetch) is freed after the connect timeout.
        $this->via->armConnectDeadline($contextId);

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

        $tracer?->setAttribute('http.status_code', 200);
        $tracer?->setAttribute('html.bytes', \strlen($html));

        $response->header('Content-Type', 'text/html; charset=utf-8');

        // Restrict who may frame this app, when configured via Config::withEmbeddable().
        // Document responses only, not SSE/action/static responses.
        $ancestors = $this->via->getConfig()->getFrameAncestors();
        if ($ancestors !== null) {
            $response->header('Content-Security-Policy', 'frame-ancestors ' . implode(' ', $ancestors));
        }

        $this->sendCompressedPage($requestAttributes, $response, $html);
    }

    /**
     * Tear down a context whose page failed. No SSE stream or close beacon will ever come for it,
     * so nothing else would clear its timers, scopes or registry entries.
     */
    private function discardContext(Context $context): void {
        $contextId = $context->getId();
        $this->via->getApp()->discardContext($context);
        unset($this->via->contexts[$contextId], $this->via->contextSessions[$contextId]);
    }

    private function failPage(string $what, string $route, \Throwable $e, ?Tracer $tracer, string $method, string $path, int $requestStart, Response $response): void {
        $this->via->log('error', $what . $route . ': ' . Logger::describe($e) . "\n" . $e->getTraceAsString());
        $tracer?->setAttribute('http.status_code', 500);
        $tracer?->markError(\get_class($e) . ': ' . $e->getMessage());
        $this->logRequest($method, $path, 500, $requestStart);
        $response->status(500);
        if (!$this->via->getConfig()->isDevMode()) {
            $response->end('Internal Server Error');

            return;
        }

        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end('<!DOCTYPE html><meta charset="utf-8"><title>Internal Server Error</title><h1>Internal Server Error</h1><pre>'
            . htmlspecialchars($e::class . ': ' . $e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</pre><p>Shown because dev mode is on.</p>');
    }

    private function logRequest(string $method, string $path, int $statusCode, int $hrtimeStart): void {
        $durationUs = (hrtime(true) - $hrtimeStart) / 1000;
        $this->requestLogger?->logRequest($method, $path, $statusCode, $durationUs);
    }

    /**
     * Answer a HEAD request that no static file took: /_health, an action URL and a Dev Bar asset as GET would, any
     * other framework endpoint 404, a page route with 200 and no body, an extension-less file in $staticDir as GET
     * would, anything else 404.
     */
    private function handleHeadRequest(string $path, Request $request, Response $response, ?string $staticDir): void {
        if ($path === '/_health') {
            $this->handleHealth($request, $response);

            return;
        }
        if (str_starts_with($path, '/_action/')) {
            self::methodNotAllowed($request, $response, 'POST');

            return;
        }
        if (($path === '/_via/devbar.css' || $path === '/_via/devbar.js') && $this->via->getConfig()->isTracingEnabled()) {
            $this->devBar ??= new DevBarController($this->via, staticBrotli: $this->staticBrotli);
            $this->devBar->handle($path, $request, $response);

            return;
        }
        // GET answers these before routing and never looks them up in the static dir.
        if ($path === '/_sse' || $path === '/_stats' || $path === '/_via' || $path === '/_traces' || str_starts_with($path, '/_via/')) {
            $response->status(404);
            $response->end();

            return;
        }

        $params = [];
        $handler = $this->via->getRouter()->matchRoute($path, $params);
        if ($handler !== null) {
            $response->status(200);
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->end();

            return;
        }

        if ($staticDir !== null && ($realFile = $this->resolveStaticFile($staticDir, $path)) !== null) {
            $this->serveStaticFile($realFile, $request, $response);

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

                // Return a dummy response: the real response was already sent via OpenSwoole
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
    private function handleSessionClose(Request $request, Response $response): int {
        // sendBeacon() sends Origin (literal "null" under Referrer-Policy: no-referrer, which is denied;
        // the SSE disconnect schedules the same cleanup).
        if (!OriginPolicy::allows($this->via->getConfig(), $request->header['origin'] ?? null, $request->header['host'] ?? null)) {
            $response->status(403);
            $response->end('Forbidden: untrusted origin');

            return 403;
        }

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

        return 200;
    }

    /**
     * Handle stats endpoint.
     */
    private function handleStats(Request $request, Response $response): void {
        $stats = [
            'contexts' => \count($this->via->contexts),
            'clients' => $this->via->getClients(),
            'render_stats' => $this->via->getStats()->getStats(),
            // Per worker: the worker that served this request.
            'broadcast_stats' => [
                'tick_ms' => $this->via->getConfig()->getBroadcastTickMs(),
                ...$this->via->getStats()->getBroadcastStats(),
            ],
            // Per worker: a call that blocks the worker shows up as event loop lag, hooked file I/O as AIO threads.
            'runtime' => [
                'hook_flags' => Runtime::getHookFlags(),
                ...array_intersect_key(Coroutine::stats(), array_flip(['aio_worker_num', 'aio_task_num'])),
                ...array_intersect_key(
                    $this->via->getServer()?->stats() ?: [],
                    array_flip(['event_loop_lag_ms', 'event_loop_lag_max_ms', 'event_loop_lag_avg_ms']),
                ),
            ],
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
     * Handle health endpoint.
     *
     * Returns 200 with status "ok" when all systems are nominal, or 503 with
     * status "degraded" when the broker has lost its backend connection.
     * No sensitive data (no IPs, no credentials, no per-user information).
     */
    private function handleHealth(Request $request, Response $response): void {
        $broker = $this->via->getBroker();
        $brokerConnected = $broker->isConnected();
        $brokerDriver = (new \ReflectionClass($broker))->getShortName();

        $sseCount = array_sum($this->via->activeSseCount);

        $payload = [
            'status' => $brokerConnected ? 'ok' : 'degraded',
            'version' => Via::VERSION,
            'broker' => [
                'driver' => $brokerDriver,
                'connected' => $brokerConnected,
            ],
            'connections' => [
                'contexts' => \count($this->via->contexts),
                'sse' => $sseCount,
            ],
        ];

        $httpStatus = $brokerConnected ? 200 : 503;
        $response->status($httpStatus);
        $response->header('Content-Type', 'application/json');
        $response->header('Cache-Control', 'no-store');
        self::endWithBody($request, $response, (string) json_encode($payload));
    }

    /**
     * Serve the Datastar bundle: the Rocket build with Config::withDatastarRocket(), else the plain one.
     */
    private function serveDatastarJs(Request $request, Response $response): void {
        $config = $this->via->getConfig();
        $path = DatastarBundle::path($config->isDatastarRocketEnabled());
        $version = $request->get['v'] ?? null;
        $versioned = \is_string($version) && DatastarBundle::url($config->getBasePath(), $version) === $config->getDatastarUrl();
        $this->sendStaticFile($path, 'application/javascript', true, $request, $response, $versioned);
    }

    /**
     * Serve a static file with correct Content-Type.
     */
    private function serveStaticFile(string $filePath, Request $request, Response $response): void {
        [$contentType, $compressible] = self::staticType($filePath);
        $this->sendStaticFile($filePath, $contentType, $compressible, $request, $response);
    }

    /**
     * Whether a request path is looked up in the static dir before routing: its last segment has
     * an extension, and it is not under a framework endpoint (action names may contain dots).
     */
    private static function looksLikeStaticFile(string $path): bool {
        if (str_starts_with($path, '/_action/') || str_starts_with($path, '/_via/') || str_starts_with($path, '/_session/')) {
            return false;
        }

        return str_contains(substr($path, (int) strrpos($path, '/') + 1), '.');
    }

    /**
     * The real path of the file a request path names in the static dir, or null when there is none, the path leads
     * outside the dir, or servableStaticPath() refuses the percent-decoded path or the file it leads to.
     */
    private function resolveStaticFile(string $staticDir, string $path): ?string {
        $query = strpos($path, '?');
        $relative = ltrim(rawurldecode($query === false ? $path : substr($path, 0, $query)), '/');
        if (!self::servableStaticPath($relative)) {
            return null;
        }

        if ($this->staticBase === null || $this->staticBase[0] !== $staticDir) {
            $realBase = realpath($staticDir);
            if ($realBase === false) {
                return null;
            }
            $this->staticBase = [$staticDir, $realBase];
        }

        // Prevent directory traversal. Joined to the resolved base, so a symlink switched by a deploy keeps
        // serving the old target until a reload instead of failing the prefix check.
        $realFile = realpath($this->staticBase[1] . '/' . $relative);
        if ($realFile === false || !str_starts_with($realFile, $this->staticBase[1] . '/') || !is_file($realFile)) {
            return null;
        }
        // A link inside the dir to a dotfile or a PHP file there.
        if (!self::servableStaticPath(substr($realFile, \strlen($this->staticBase[1]) + 1))) {
            return null;
        }

        return $realFile;
    }

    /**
     * Serve Via CSS file.
     */
    private function serveViaCss(Request $request, Response $response): void {
        $this->sendStaticFile(self::viaCssPath(), 'text/css; charset=utf-8', true, $request, $response);
    }

    /**
     * Serve a file-backed static response with ETag/Last-Modified conditional-GET
     * support and the configured Cache-Control policy.
     *
     * Shared by /datastar.js, /via.css, and files served via Config::withStaticDir().
     *
     * @param bool $versioned The URL carries the file's current content version
     */
    private function sendStaticFile(string $filePath, string $contentType, bool $compressible, Request $request, Response $response, bool $versioned = false): void {
        if ($this->via->getConfig()->isDevMode()) {
            // Under the file hooks PHP keeps stat() results across writes, which would hide an edit.
            clearstatcache(true, $filePath);
        }
        $mtime = filemtime($filePath);
        $size = filesize($filePath);
        // Weak, so it holds for the uncompressed body and each Brotli form of it alike.
        $etag = ConditionalGet::etag($mtime, $size);
        $mimeType = explode(';', $contentType, 2)[0];
        $brotli = $compressible && $this->staticBrotli->enabled() ? $this->staticBrotli : null;

        $response->header('Cache-Control', $this->via->getConfig()->getStaticCacheControl($filePath, $mimeType, $versioned));
        $response->header('ETag', $etag);
        $response->header('Last-Modified', ConditionalGet::lastModified($mtime));
        if ($brotli !== null) {
            $response->header('Vary', 'Accept-Encoding');
        }

        $ifNoneMatch = $request->header['if-none-match'] ?? null;
        $ifModifiedSince = $request->header['if-modified-since'] ?? null;

        if (ConditionalGet::isNotModified($ifNoneMatch, $ifModifiedSince, $etag, $mtime)) {
            $response->status(304);
            $response->end();

            return;
        }

        $response->header('Content-Type', $contentType);

        if ($brotli !== null && str_contains($request->header['accept-encoding'] ?? '', 'br')) {
            $compressed = $brotli->lookup($filePath, $mtime, $size);
            if ($brotli->pending($filePath, $mtime, $size)) {
                // A stand-in until the helper's level 11 arrives: a cache would keep it under the same ETag.
                $response->header('Cache-Control', 'no-store');
            }
            if ($compressed !== null) {
                $response->header('Content-Encoding', 'br');
                if (!isset($compressed['file'])) {
                    self::endWithBody($request, $response, $compressed['body']);
                } elseif (self::isHead($request)) {
                    self::endHead($response, (int) filesize($compressed['file']));
                } else {
                    $response->sendfile($compressed['file']);
                }

                return;
            }
        }

        if (self::isHead($request)) {
            self::endHead($response, $size);

            return;
        }

        $this->sendStaticBody($response, $filePath, $mtime, $size);
    }

    private static function isHead(Request $request): bool {
        return ($request->server['request_method'] ?? '') === 'HEAD';
    }

    /**
     * End a HEAD response with the length of the body GET would send. OpenSwoole 26.2 sends end()'s body and
     * sendfile()'s file on HEAD too, which a client reads as the start of the next response. Over HTTP/2 it drops
     * this Content-Length.
     */
    private static function endHead(Response $response, int $length): void {
        $response->header('Content-Length', (string) $length);
        $response->end();
    }

    private static function methodNotAllowed(Request $request, Response $response, string $allow): void {
        $response->status(405);
        $response->header('Allow', $allow);
        self::endWithBody($request, $response, 'Method Not Allowed');
    }

    /**
     * Send a static file's uncompressed body from memory, reading it only on a miss. A file over
     * STATIC_CACHE_FILE_BYTES, or one the cache has no room for, goes out with sendfile(), so the
     * worker never reads it.
     */
    private function sendStaticBody(Response $response, string $filePath, int $mtime, int $size): void {
        $cached = $this->staticCache->get($filePath, $mtime, $size);
        if ($cached !== null) {
            $response->end($cached['body']);

            return;
        }

        $devMode = $this->via->getConfig()->isDevMode();
        if (!$this->staticCache->fits($size, $devMode, $filePath)) {
            $response->sendfile($filePath);

            return;
        }

        $body = file_get_contents($filePath);
        if ($body === false) {
            $response->header('Cache-Control', 'no-store');
            $response->status(404);
            $response->end('Not Found');

            return;
        }

        $this->staticCache->put($filePath, $mtime, $size, $body, true, $devMode);
        $response->end($body);
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
