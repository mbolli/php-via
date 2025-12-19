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
use Mbolli\PhpVia\Support\Stats;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Twig\Environment;

/**
 * Via - Real-time engine for building reactive web applications in PHP.
 *
 * Main application class that manages routing, contexts, and SSE connections.
 */
class Via {
    // Legacy public properties for HTTP handlers (will be phased out)
    /** @var array<string, Context> */
    public array $contexts = [];

    /** @var array<string, int> Cleanup timer IDs for contexts */
    public array $cleanupTimers = [];

    /** @var array<string, array{id: string, identicon: string, connected_at: int, ip: string}> Client info by context ID */
    public array $clients = [];

    /** @var array<string, string> Session ID by context ID (contextId => sessionId) */
    public array $contextSessions = [];
    private ?Server $server = null;

    /** @var array<callable> Callbacks to run when server starts */
    private array $startCallbacks = [];

    private Application $app;
    private Router $router;
    private SessionManager $sessionManager;
    private RequestHandler $requestHandler;
    private Logger $logger;
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

        // Initialize ViewRenderer with Twig from Application
        $this->viewRenderer = new ViewRenderer($this->app->getTwig(), $this->viewCache, $this->stats, $this->logger);

        // Note: Swoole server is created lazily in start() to allow testing without binding to a port
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
     * Get the Router instance.
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
            $this->log('debug', 'Broadcasting to GLOBAL scope (all contexts)');
            $this->syncAllContexts();

            return;
        }

        // Handle ROUTE scope - sync all contexts on this route
        if (Scope::isRouteBased($scope)) {
            $parts = Scope::parse($scope);
            $route = $parts[1] ?? null;

            // If no specific route provided, broadcast to all routes
            if ($route === null) {
                $this->log('debug', 'Broadcasting to all ROUTE scopes (all routes)');
                // Find all route scopes and invalidate their caches
                foreach ($this->viewCache->getKeys() as $cachedScope) {
                    if (Scope::isRouteBased($cachedScope)) {
                        $this->invalidateViewCache($cachedScope);
                    }
                }
                // Sync all contexts
                $this->syncAllContexts();
            } else {
                $this->invalidateViewCache($scope);
                $this->log('debug', "Broadcasting to ROUTE scope: {$route}");
                $this->syncContextsOnRoute($route);
            }

            return;
        }

        // Handle custom scopes (with wildcard support)
        $matchedContexts = $this->scopeRegistry->getContextsByScopePattern($scope);

        if (str_contains($scope, '*')) {
            $this->log('debug', "Broadcasting to wildcard scope: {$scope} (" . \count($matchedContexts) . ' contexts)');
        } else {
            $this->log('debug', "Broadcasting to scope: {$scope} (" . \count($matchedContexts) . ' contexts)');
        }

        // Invalidate cache for this scope
        $this->invalidateViewCache($scope);

        // Sync all matched contexts
        foreach ($matchedContexts as $context) {
            $context->sync();
        }
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
     * @internal
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
            $this->server = new Server($this->config->getHost(), $this->config->getPort(), SWOOLE_BASE);

            // Configure Swoole for SSE streaming
            $defaultSettings = [
                'open_http2_protocol' => false,
                'http_compression' => false,
                'buffer_output_size' => 0,   // NO OUTPUT BUFFERING
                'socket_buffer_size' => 1024 * 1024,
                'max_coroutine' => 100000,
                'worker_num' => 1,   // Single worker = shared state (clients, render stats)
                'send_yield' => true,
            ];
            $this->server->set(array_merge($defaultSettings, $this->config->getSwooleSettings()));

            $this->requestHandler->setRoutes($this->router->getRoutes());

            $this->server->on('start', function (Server $server): void {
                $this->log('info', "Via server started on {$this->config->getHost()}:{$this->config->getPort()}");

                // Execute all registered start callbacks
                foreach ($this->startCallbacks as $callback) {
                    $callback();
                }
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
     * Read Datastar signals from a Swoole HTTP request.
     *
     * This is a replacement for ServerSentEventGenerator::readSignals() which only checks
     * $_GET['datastar'] and php://input, but doesn't handle $_POST.
     * In Swoole, POST requests need special handling since we use $request->post instead of $_POST.
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
        $this->app->scheduleContextCleanup($contextId, $delayMs);
    }

    /**
     * Build complete HTML document.
     *
     * @internal Used by HTTP handlers
     */
    public function buildHtmlDocument(Context $context): string {
        $content = $context->renderView();

        return $this->htmlBuilder->buildDocument($content, $context, $context->getId());
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
