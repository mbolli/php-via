<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Mbolli\PhpVia\Core\Application;
use Mbolli\PhpVia\Core\Router;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Rendering\HtmlBuilder;
use Mbolli\PhpVia\Rendering\ViewCache;
use Mbolli\PhpVia\Rendering\ViewRenderer;
use Mbolli\PhpVia\State\ActionRegistry;
use Mbolli\PhpVia\State\ScopeRegistry;
use Mbolli\PhpVia\State\SignalManager;
use Mbolli\PhpVia\Support\IdGenerator;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Support\Stats;
use OpenSwoole\Event;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Process;
use OpenSwoole\Timer;
use Twig\Environment;

/**
 * Via - Real-time engine for building reactive web applications in PHP.
 *
 * Main application class that manages routing, contexts, and SSE connections.
 */
class Via {
    public const string VERSION = '0.2.0';

    // Legacy public properties for HTTP handlers (will be phased out)
    /** @var array<string, Context> */
    public array $contexts = [];

    /** @var array<string, int> Cleanup timer IDs for contexts */
    public array $cleanupTimers = [];

    /** @var array<string, array{id: string, identicon: string, connected_at: int, ip: string}> Client info by context ID */
    public array $clients = [];

    /** @var array<string, string> Session ID by context ID (contextId => sessionId) */
    public array $contextSessions = [];

    /** @var array<string, true> Contexts that already have a Via::$contexts unset callback registered */
    private array $viaUnsetCallbackRegistered = [];

    private ?Server $server = null;

    /** @var array<callable> Callbacks to run when server starts */
    private array $startCallbacks = [];

    /** @var array<callable> Callbacks to run on graceful shutdown */
    private array $shutdownCallbacks = [];

    /** @var array<callable(Context): void> Callbacks to run when a client connects via SSE */
    private array $clientConnectCallbacks = [];

    /** @var array<callable(Context): void> Callbacks to run when a client disconnects from SSE */
    private array $clientDisconnectCallbacks = [];

    private bool $shuttingDown = false;
    private bool $signalsRegistered = false;

    private Application $app;
    private Router $router;
    private SessionManager $sessionManager;
    private RequestHandler $requestHandler;
    private Logger $logger;
    private RequestLogger $requestLogger;
    private Stats $stats;
    private ViewCache $viewCache;
    private ViewRenderer $viewRenderer;
    private HtmlBuilder $htmlBuilder;
    private ScopeRegistry $scopeRegistry;
    private SignalManager $signalManager;
    private ActionRegistry $actionRegistry;

    public function __construct(private Config $config) {
        // Initialize support classes
        $this->logger = new Logger($this->config->getLogLevel());
        $this->requestLogger = new RequestLogger($this->config->getDevMode());
        $this->logger->setRequestLogger($this->requestLogger);
        $this->stats = new Stats();
        $this->viewCache = new ViewCache();
        $this->htmlBuilder = new HtmlBuilder($this->config->getShellTemplate());
        $this->scopeRegistry = new ScopeRegistry();
        $this->signalManager = new SignalManager();
        $this->actionRegistry = new ActionRegistry();

        // Initialize Core classes
        $this->app = new Application(
            $this->config,
            $this->logger,
            $this->stats,
            $this->scopeRegistry,
            $this->signalManager,
            $this->actionRegistry
        );
        $this->router = new Router();
        $this->sessionManager = new SessionManager($this->logger);

        // Initialize HTTP handlers
        $sseHandler = new SseHandler($this);
        $actionHandler = new ActionHandler($this);
        $this->requestHandler = new RequestHandler($this, $sseHandler, $actionHandler);

        // Share request logger with HTTP handlers
        $sseHandler->setRequestLogger($this->requestLogger);
        $actionHandler->setRequestLogger($this->requestLogger);
        $this->requestHandler->setRequestLogger($this->requestLogger);

        // Initialize ViewRenderer with Twig from Application
        $this->viewRenderer = new ViewRenderer($this->app->getTwig(), $this->viewCache, $this->stats, $this->logger);

        // Note: OpenSwoole server is created lazily in start() to allow testing without binding to a port
    }

    /**
     * Get session ID for a context.
     *
     * @internal Used by HTTP handlers
     */
    public function getContextSessionId(string $contextId): ?string {
        return $this->contextSessions[$contextId] ?? null;
    }

    /**
     * Get the configuration instance for fluent configuration.
     */
    public function config(): Config {
        return $this->config;
    }

    /**
     * Get the Application instance.
     *
     * @internal Used by HTTP handlers
     */
    public function getApp(): Application {
        return $this->app;
    }

    /**
     * Get configuration.
     */
    public function getConfig(): Config {
        return $this->config;
    }

    /**     * Get the Router instance.
     *
     * @internal Used by HTTP handlers
     */
    public function getRouter(): Router {
        return $this->router;
    }

    /**
     * Apply configuration changes (called internally after fluent config).
     *
     * @internal
     */
    public function applyConfig(): void {
        $this->app->applyConfig();
    }

    /**
     * Get global state value.
     */
    public function globalState(string $key, mixed $default = null): mixed {
        return $this->app->getGlobalState($key, $default);
    }

    /**
     * Set global state value.
     */
    public function setGlobalState(string $key, mixed $value): void {
        $this->app->setGlobalState($key, $value);
    }

    /**
     * Get the global view cache.
     *
     * @internal Used by Context for global scope caching
     */
    /**
     * Get cached view for a scope.
     *
     * @internal Used by Context to get cached view
     */
    public function getViewCache(string $scope): ?string {
        return $this->viewCache->get($scope);
    }

    /**
     * Set cached view for a scope.
     *
     * @internal Used by Context to cache view
     */
    public function setViewCache(string $scope, string $html): void {
        $this->viewCache->set($scope, $html);
    }

    /**
     * Register a page route with its handler.
     *
     * @param string   $route   The route pattern (e.g., '/')
     * @param callable $handler Function that receives a Context instance
     */
    public function page(string $route, callable $handler): void {
        $this->router->registerRoute($route, $handler);
    }

    /**
     * Unified broadcast method supporting built-in and custom scopes.
     *
     * Examples:
     * - $app->broadcast(Scope::GLOBAL) - All contexts everywhere
     * - $app->broadcast(Scope::routeScope('/game')) - All on /game route
     * - $app->broadcast("room:lobby") - All in lobby chat room
     * - $app->broadcast("user:123") - All tabs for user 123
     * - $app->broadcast("room:*") - All rooms (wildcard)
     *
     * @param string $scope Scope to broadcast to
     */
    public function broadcast(string $scope): void {
        // Handle GLOBAL scope - sync all contexts
        if ($scope === Scope::GLOBAL) {
            $this->invalidateViewCache($scope);
            $this->syncAllContexts();
            $this->requestLogger->logBroadcast($scope, \count($this->contexts));

            return;
        }

        // Handle ROUTE scope - sync all contexts on this route
        if (Scope::isRouteBased($scope)) {
            $parts = Scope::parse($scope);
            $route = $parts[1] ?? null;

            // If no specific route provided, broadcast to all routes
            if ($route === null) {
                // Find all route scopes and invalidate their caches
                // Note: getKeys() returns cache keys with :initial or :update suffix
                // We need to extract the base scope before invalidating
                $seenScopes = [];
                foreach ($this->viewCache->getKeys() as $cacheKey) {
                    // Extract base scope by removing :initial or :update suffix
                    $baseScope = preg_replace('/:(?:initial|update)$/', '', $cacheKey);

                    // Only invalidate each base scope once
                    if (Scope::isRouteBased($baseScope) && !isset($seenScopes[$baseScope])) {
                        $this->invalidateViewCache($baseScope);
                        $seenScopes[$baseScope] = true;
                    }
                }
                // Sync all contexts
                $this->syncAllContexts();
                $this->requestLogger->logBroadcast($scope, \count($this->contexts));
            } else {
                // Important: Invalidate cache using the full scope string (route:/path)
                // The context's primary scope is "route:/path", not just "route"
                $this->invalidateViewCache($scope);
                $this->syncContextsOnRoute($route);
                $this->requestLogger->logBroadcast($scope, 0);
            }

            return;
        }

        // Handle custom scopes (with wildcard support)
        $matchedContexts = $this->scopeRegistry->getContextsByScopePattern($scope);

        // Invalidate cache for this scope
        $this->invalidateViewCache($scope);

        // Sync all matched contexts
        foreach ($matchedContexts as $context) {
            $context->sync();
        }

        $this->requestLogger->logBroadcast($scope, \count($matchedContexts));
    }

    /**
     * Register a context under a specific scope.
     *
     * @internal Called by Context::scope() and Context::addScope()
     */
    public function registerContextInScope(Context $context, string $scope): void {
        $this->scopeRegistry->registerContext($context, $scope);
    }

    /**
     * Get all contexts registered under a specific scope.
     *
     * @return array<Context>
     */
    public function getContextsByScope(string $scope): array {
        return $this->scopeRegistry->getContextsByScope($scope);
    }

    /**
     * Register a scoped signal.
     *
     * @internal Called by Context when creating scoped signals
     */
    public function registerScopedSignal(string $scope, Signal $signal): void {
        $this->signalManager->registerSignal($scope, $signal);
    }

    /**
     * Get a scoped signal by scope and ID.
     *
     * Used by Context to retrieve scoped signals
     */
    public function getScopedSignal(string $scope, string $signalId): ?Signal {
        return $this->signalManager->getSignal($scope, $signalId);
    }

    /**
     * Get all scoped signals for a scope.
     *
     * @internal
     *
     * @return array<string, Signal>
     */
    public function getScopedSignals(string $scope): array {
        return $this->signalManager->getSignals($scope);
    }

    /**
     * Register a scoped action (shared across contexts in the same scope).
     *
     * @internal
     */
    public function registerScopedAction(string $scope, string $actionId, callable $action): void {
        $this->actionRegistry->registerAction($scope, $actionId, $action);
    }

    /**
     * Get a scoped action by ID.
     *
     * @internal
     */
    public function getScopedAction(string $scope, string $actionId): ?callable {
        return $this->actionRegistry->getAction($scope, $actionId);
    }

    /**
     * Get all scoped actions for a scope.
     *
     * @internal
     *
     * @return array<string, callable>
     */
    public function getScopedActions(string $scope): array {
        return $this->actionRegistry->getActions($scope);
    }

    /**
     * Get the scope registry.
     *
     * @internal Used by SSE handler to manage context registration
     */
    public function getScopeRegistry(): ScopeRegistry {
        return $this->scopeRegistry;
    }

    /**
     * Add elements to the document head.
     */
    public function appendToHead(string ...$elements): void {
        $this->htmlBuilder->appendToHead(...$elements);
    }

    /**
     * Add elements to the document footer.
     */
    public function appendToFoot(string ...$elements): void {
        $this->htmlBuilder->appendToFoot(...$elements);
    }

    /**
     * Start the Via server.
     */
    public function start(): void {
        // Lazy initialization: create server only when starting
        if ($this->server === null) {
            $this->server = new Server($this->config->getHost(), $this->config->getPort(), Server::SIMPLE_MODE);

            // Configure OpenSwoole for SSE streaming
            $defaultSettings = [
                'open_http2_protocol' => false,
                'http_compression' => false,
                'buffer_output_size' => 0,   // NO OUTPUT BUFFERING
                'socket_buffer_size' => 1024 * 1024,
                'max_coroutine' => 100000,
                'worker_num' => 1,   // Single worker = shared state (clients, render stats)
                'send_yield' => true,
                'max_wait_time' => 1,  // Max 1 second to wait for worker to exit
                'reload_async' => true,  // Enable async reload
                'enable_reuse_port' => true,  // Allow immediate rebind on restart
                'hook_flags' => SWOOLE_HOOK_ALL,  // Enable coroutine hooks for native functions (sleep, usleep, etc.)
                'log_level' => 4,  // SWOOLE_LOG_WARNING — suppress NOTICE about sending to closed connections
            ];
            $this->server->set(array_merge($defaultSettings, $this->config->getSwooleSettings()));

            $this->requestHandler->setRoutes($this->router->getRoutes());

            $this->server->on('start', function (Server $server): void {
                $this->log('info', "Via server started on {$this->config->getHost()}:{$this->config->getPort()}");
            });

            $this->server->on('workerStart', function (Server $server, int $workerId): void {
                // Register signal handlers in worker process (where timers run)
                $this->registerSignalHandlers();

                // Catch PHP fatal errors (OOM, stack overflow, parse errors in eval, etc.).
                // error_get_last() returns the last E_ERROR/E_PARSE that killed the worker.
                register_shutdown_function(function () use ($workerId): void {
                    $e = error_get_last();
                    if ($e !== null && \in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                        $this->logger->fatal(
                            "Worker {$workerId} PHP fatal: [{$e['type']}] {$e['message']} in {$e['file']}:{$e['line']}"
                        );
                    }
                });

                // Catch any exception that escapes all coroutines/handlers.
                set_exception_handler(function (\Throwable $e) use ($workerId): void {
                    $this->logger->fatal(
                        "Worker {$workerId} uncaught " . \get_class($e) . ": {$e->getMessage()}\n"
                        . "  in {$e->getFile()}:{$e->getLine()}\n"
                        . $e->getTraceAsString()
                    );
                });

                // Execute all registered start callbacks
                foreach ($this->startCallbacks as $callback) {
                    $callback();
                }
            });

            // Fires when a worker process exits abnormally (crash, OOM kill, fatal).
            // exitCode is the PHP exit code; signal is the OS signal that killed it (e.g. 9 = SIGKILL).
            $this->server->on('workerError', function (Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void {
                $reason = $signal > 0 ? "signal={$signal}" : "exit_code={$exitCode}";
                $this->logger->fatal("Worker {$workerId} (pid {$workerPid}) crashed: {$reason}");
            });

            $this->server->on('workerExit', function (Server $server, int $workerId): void {
                // Prevent worker exit timeout by clearing all timers and exiting event loop
                Timer::clearAll();
                Event::exit();
            });

            $this->server->on('request', function (Request $request, Response $response): void {
                $this->requestHandler->handleRequest($request, $response);
            });
        }

        $this->server->start();
    }

    /**
     * Register a callback to run when the server starts.
     * Use this to initialize timers or background tasks.
     */
    public function onStart(callable $callback): void {
        $this->startCallbacks[] = $callback;
    }

    /**
     * Register a callback to run on graceful shutdown.
     * Use this to clean up timers, close connections, or save state.
     */
    public function onShutdown(callable $callback): void {
        $this->shutdownCallbacks[] = $callback;
    }

    /**
     * Register a callback to run when a client connects via SSE.
     * The callback receives the Context of the connecting client.
     *
     * @param callable(Context): void $callback
     */
    public function onClientConnect(callable $callback): void {
        $this->clientConnectCallbacks[] = $callback;
    }

    /**
     * Register a callback to run when a client disconnects from SSE.
     * The client has already been removed from getClients() when the callback fires.
     *
     * @param callable(Context): void $callback
     */
    public function onClientDisconnect(callable $callback): void {
        $this->clientDisconnectCallbacks[] = $callback;
    }

    /**
     * @internal called by SseHandler when a client SSE connection is established
     */
    public function triggerClientConnect(Context $context): void {
        foreach ($this->clientConnectCallbacks as $callback) {
            try {
                $callback($context);
            } catch (\Throwable $e) {
                $this->log('error', 'onClientConnect callback error: ' . $e->getMessage(), $context);
            }
        }
    }

    /**
     * @internal called by SseHandler just before a client SSE connection is torn down
     */
    public function triggerClientDisconnect(Context $context): void {
        foreach ($this->clientDisconnectCallbacks as $callback) {
            try {
                $callback($context);
            } catch (\Throwable $e) {
                $this->log('error', 'onClientDisconnect callback error: ' . $e->getMessage(), $context);
            }
        }
    }

    /**
     * Check if server is shutting down.
     *
     * @internal Used by SSE handler to exit gracefully
     */
    public function isShuttingDown(): bool {
        return $this->shuttingDown;
    }

    /**
     * Log message.
     */
    public function log(string $level, string $message, ?Context $context = null): void {
        $this->logger->log($level, $message, $context);
    }

    /**
     * Get all connected clients.
     *
     * @return array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}>
     */
    public function getClients(): array {
        return $this->app->getClients();
    }

    /**
     * Get render statistics.
     *
     * @return array{render_count: int, total_time: float, min_time: float, max_time: float, avg_time: float}
     */
    public function getRenderStats(): array {
        return $this->app->getRenderStats();
    }

    /**
     * Get the Stats instance for metrics tracking.
     */
    public function getStats(): Stats {
        return $this->stats;
    }

    /**
     * Track view render time.
     *
     * @internal Called by Context during rendering
     */
    public function trackRender(float $duration): void {
        $this->app->trackRender($duration);
    }

    /**
     * Get cached view HTML for a route if available and fresh.
     *
     * @internal Used by Context for scope-based caching
     */
    public function getCachedView(string $route): ?string {
        return $this->viewCache->get($route);
    }

    /**
     * Cache rendered view HTML for a route.
     *
     * @internal Used by Context for scope-based caching
     */
    public function cacheView(string $route, string $html): void {
        $this->viewCache->set($route, $html);
    }

    /**
     * Get Twig environment.
     *
     * @internal Used by Context for template rendering
     */
    public function getTwig(): Environment {
        return $this->app->getTwig();
    }

    /**
     * Get ViewRenderer.
     *
     * @internal Used by Context for rendering
     */
    public function getViewRenderer(): ViewRenderer {
        return $this->viewRenderer;
    }

    /**
     * Check if a route is currently rendering.
     *
     * @internal Used by Context for render locking
     */
    public function isRendering(string $route): bool {
        return $this->viewCache->isRendering($route);
    }

    /**
     * Set rendering status for a route.
     *
     * @internal Used by Context for render locking
     */
    public function setRendering(string $route, bool $status): void {
        $this->viewCache->setRendering($route, $status);
    }

    /**
     * Get or create session ID from request cookies.
     *
     * @internal Used by HTTP handlers
     */
    public function getSessionId(Request $request): string {
        return $this->sessionManager->getOrCreateSessionId($request);
    }

    /**
     * Set session cookie in response.
     *
     * @internal Used by HTTP handlers
     */
    public function setSessionCookie(Response $response, string $sessionId): void {
        $this->sessionManager->setSessionCookie($response, $sessionId);
    }

    /**
     * Read Datastar signals from an OpenSwoole HTTP request.
     *
     * This is a replacement for ServerSentEventGenerator::readSignals() which only checks
     * $_GET['datastar'] and php://input, but doesn't handle $_POST.
     * In OpenSwoole, POST requests need special handling since we use $request->post instead of $_POST.
     *
     * @internal Used by HTTP handlers
     *
     * @return array<string, mixed> The decoded signals array
     */
    public static function readSignals(Request $request): array {
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
     * Schedule context cleanup after a delay.
     * Allows time for reconnection or navigation between pages.
     *
     * @internal Used by HTTP handlers
     */
    public function scheduleContextCleanup(string $contextId, int $delayMs = 5000): void {
        // Register a cleanup callback so Via::$contexts is also cleared when Application fires the cleanup.
        // Application::unregisterContext only removes from its own map; Via::$contexts is separate and must
        // be cleared here, otherwise zombie contexts (no viewFn) survive and break SSE reconnection.
        // Guard: register at most once per context — this method is called on every SSE disconnect, so
        // repeated reconnections would otherwise accumulate unbounded closures in cleanupCallbacks.
        if (isset($this->contexts[$contextId]) && !isset($this->viaUnsetCallbackRegistered[$contextId])) {
            $this->viaUnsetCallbackRegistered[$contextId] = true;
            $this->contexts[$contextId]->onCleanup(function () use ($contextId): void {
                unset($this->contexts[$contextId], $this->viaUnsetCallbackRegistered[$contextId]);
            });
        }

        $this->app->scheduleContextCleanup($contextId, $delayMs);
    }

    /**
     * Build complete HTML document.
     *
     * @internal Used by HTTP handlers
     */
    public function buildHtmlDocument(Context $context): string {
        $content = $context->renderView();

        return $this->htmlBuilder->buildDocument($content, $context, $context->getId(), $this->config->getBasePath());
    }

    /**
     * Invoke a handler with automatic path parameter injection.
     *
     * Inspects the callable's parameters and automatically injects path parameters
     * matching the parameter names, along with the Context as the first parameter.
     * Automatically casts route parameters to the expected type (int, float, bool, string).
     *
     * @internal Used by HTTP handlers
     *
     * @param callable              $handler     Handler callable
     * @param Context               $context     Context instance
     * @param array<string, string> $routeParams Available route parameters
     */
    public function invokeHandlerWithParams(callable $handler, Context $context, array $routeParams): void {
        $this->router->invokeHandler($handler, $context, $routeParams);
    }

    /**
     * Generate random ID.
     *
     * @internal Used by HTTP handlers
     */
    public function generateId(): string {
        return IdGenerator::generate();
    }

    /**
     * Generate unique client ID.
     *
     * @internal Used by HTTP handlers
     */
    public function generateClientId(): string {
        return IdGenerator::generateClientId();
    }

    /**
     * Generate SVG identicon based on client ID.
     * Creates a 5x5 symmetric pattern.
     *
     * @internal Used by HTTP handlers
     */
    public function generateIdenticon(string $clientId): string {
        return IdGenerator::generateIdenticon($clientId);
    }

    /**
     * Register signal handlers for graceful shutdown.
     */
    private function registerSignalHandlers(): void {
        // Prevent duplicate registration
        if ($this->signalsRegistered) {
            return;
        }
        $this->signalsRegistered = true;

        // SIGTERM - systemd stop/restart
        Process::signal(SIGTERM, function (): void {
            $this->log('info', 'Received SIGTERM, shutting down gracefully...');
            $this->shuttingDown = true;
            $this->executeShutdownCallbacks();

            // Force kill the server to stop all coroutines immediately
            if ($this->server !== null) {
                $this->server->shutdown();
            } else {
                exit(0);
            }
        });

        // SIGINT - Ctrl+C
        Process::signal(SIGINT, function (): void {
            $this->log('info', 'Received SIGINT (Ctrl+C), shutting down gracefully...');
            $this->shuttingDown = true;
            $this->executeShutdownCallbacks();

            if ($this->server !== null) {
                $this->server->shutdown();
            } else {
                exit(0);
            }
        });

        // SIGHUP - systemd reload (just log, don't exit)
        Process::signal(SIGHUP, function (): void {
            $this->log('info', 'Received SIGHUP signal, ignoring');
        });
    }

    /**
     * Execute all registered shutdown callbacks.
     */
    private function executeShutdownCallbacks(): void {
        $this->shuttingDown = true;
        foreach ($this->shutdownCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->log('error', 'Error in shutdown callback: ' . $e->getMessage());
            }
        }
    }

    /**
     * Sync all contexts on a specific route.
     */
    private function syncContextsOnRoute(string $route): void {
        foreach ($this->contexts as $context) {
            if ($context->getRoute() === $route) {
                $context->sync();
            }
        }
    }

    /**
     * Sync all contexts across all routes.
     */
    private function syncAllContexts(): void {
        foreach ($this->contexts as $context) {
            $context->sync();
        }
    }

    /**
     * Invalidate view cache for a scope (called on broadcast).
     */
    private function invalidateViewCache(string $scope): void {
        $this->viewCache->invalidate($scope);
    }
}
