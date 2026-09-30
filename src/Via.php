<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Mbolli\PhpVia\Broker\InMemoryBroker;
use Mbolli\PhpVia\Broker\MessageBroker;
use Mbolli\PhpVia\Broker\ServerAwareBroker;
use Mbolli\PhpVia\Composition\ClassMetadata;
use Mbolli\PhpVia\Composition\PageMount;
use Mbolli\PhpVia\Core\Application;
use Mbolli\PhpVia\Core\Router;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\DevBar\Injector;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\Middleware\BrotliMiddleware;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\RouteDefinition;
use Mbolli\PhpVia\Http\RouteGroup;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Rendering\HtmlBuilder;
use Mbolli\PhpVia\Rendering\ViewCache;
use Mbolli\PhpVia\Rendering\ViewRenderer;
use Mbolli\PhpVia\State\ActionRegistry;
use Mbolli\PhpVia\State\ReadEpochs;
use Mbolli\PhpVia\State\ScopeRegistry;
use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\State\SharedContextDirectory;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\State\SharedTable;
use Mbolli\PhpVia\State\SignalManager;
use Mbolli\PhpVia\State\SqliteSnapshot;
use Mbolli\PhpVia\Support\IdGenerator;
use Mbolli\PhpVia\Support\LogBuffer;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Support\Stats;
use Mbolli\PhpVia\Tracing\Tracer;
use Mbolli\PhpVia\Tracing\TraceStore;
use OpenSwoole\Coroutine;
use OpenSwoole\Event;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Process;
use OpenSwoole\Timer;
use Psr\Http\Server\MiddlewareInterface;
use Twig\Environment;

/**
 * Via - Real-time engine for building reactive web applications in PHP.
 *
 * Main application class that manages routing, contexts, and SSE connections.
 */
class Via {
    public const string VERSION = '0.13.0';

    /**
     * The worker that runs server-wide singleton work (see setInterval()).
     *
     * Worker 0 always exists and OpenSwoole restarts it under the same id if it dies.
     */
    private const int LEADER_WORKER_ID = 0;

    /** How often the leader drops registry rows of worker processes that no longer exist. */
    private const int DEAD_CLIENT_SWEEP_MS = 60_000;

    /** Passes one fan-out of a scope runs in a row; a broadcast still owed then goes to the next flush. */
    private const int MAX_SYNC_PASSES = 8;

    /** Safety bound on flushes in a row caused by views broadcasting other scopes. */
    private const int MAX_BROADCAST_HOPS = self::MAX_SYNC_PASSES;

    /**
     * How long flushBroadcasts() waits for a running fan-out of its scopes, and the shutdown drain
     * for the publisher. A fan-out running longer than this is logged.
     */
    private const int FLUSH_WAIT_MS = 1000;

    // Legacy public properties for HTTP handlers (will be phased out)
    /** @var array<string, Context> */
    public array $contexts = [];

    /** @var array<string, int> Cleanup timer IDs for contexts */
    public array $cleanupTimers = [];

    /** @var array<string, int> Number of active SSE coroutines per context ID */
    public array $activeSseCount = [];

    /** SSE handlers still running, including their exit path and onClientDisconnect hooks */
    public int $runningSseStreams = 0;

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

    /** @var list<array{callable, int, bool}> Server intervals from setInterval(): callback, period, every-worker flag */
    private array $serverIntervals = [];

    /** @var list<int> Timer IDs for running server intervals (populated in workerStart) */
    private array $serverIntervalIds = [];

    /** @var array<callable(Context): void> Callbacks to run when a client connects via SSE */
    private array $clientConnectCallbacks = [];

    /** @var array<callable(Context): void> Callbacks to run when a client disconnects from SSE */
    private array $clientDisconnectCallbacks = [];

    /** @var null|callable(Request, Response): void Handler for unmatched routes (404) */
    private $notFoundHandler;

    private bool $shuttingDown = false;
    private bool $shutdownStarted = false;
    private bool $signalsRegistered = false;

    /** Final GlobalState drain, run once in the master after every worker has stopped */
    private ?\Closure $finalGlobalStateDrain = null;

    /** @var array<string, array{cid: int, lastCid: int, since: int, warned: bool}> Scopes whose fan-out is running => its coroutine, the highest coroutine id when it started, hrtime start, whether it was reported as slow */
    private array $syncInFlight = [];

    /** @var array<string, array<string, array{0: \Throwable, 1: Context, 2: int}>> Per scope: failure signature => first throwable, its context, count */
    private array $syncFailures = [];

    /** @var array<string, true> Scopes broadcast by another coroutine on the synchronous path while their fan-out was running */
    private array $syncPending = [];

    /** @var array<string, true> Scopes broadcast by the views of their own running fan-out, or by coroutines those views start */
    private array $syncReentered = [];

    /** @var array<string, int> Scopes waiting for a flush => hops of the render chain that marked them */
    private array $dirtyScopes = [];

    /** @var array<string, true> Scopes still to be published to the broker */
    private array $unpublishedScopes = [];

    private bool $flushScheduled = false;

    /** Set while the scheduled flush waits on a timer (the tick gap) rather than Event::defer. */
    private ?int $flushTimerId = null;

    /** Bumped on every (re)schedule and cancel, so a superseded callback does nothing. */
    private int $flushGeneration = 0;

    /**
     * Flushes run side by side, each on scopes no other one is rendering, so a view that waits on
     * I/O holds up only its own scope.
     *
     * @var array<int, int> Coroutine running a flush => hops of the scope it renders
     */
    private array $runningFlushes = [];

    private bool $publishing = false;

    /** hrtime(true) when the worker's latest flush started; null before the first one */
    private ?int $lastFlushStartNs = null;

    /** hrtime(true) when the worker's latest flush ended; null before the first one */
    private ?int $lastFlushEndNs = null;

    /** Set in workerStart: from then on the reactor can run a deferred flush even outside a coroutine. */
    private bool $workerStarted = false;

    private Application $app;
    private Router $router;
    private SessionManager $sessionManager;
    private RequestHandler $requestHandler;
    private SseHandler $sseHandler;
    private Logger $logger;
    private RequestLogger $requestLogger;
    private Stats $stats;
    private ?TraceStore $traceStore = null;
    private ?Tracer $tracer = null;
    private ?LogBuffer $logBuffer = null;
    private ?Injector $devBarInjector = null;
    private ViewCache $viewCache;
    private ViewRenderer $viewRenderer;
    private HtmlBuilder $htmlBuilder;
    private ScopeRegistry $scopeRegistry;
    private SignalManager $signalManager;

    /** Cross-worker backing for scoped signal values; null when running single-worker. */
    private ?SharedSignalStore $sharedSignalStore = null;

    /** Read epochs of this worker's fan-outs: the shared store's own, when there is one. */
    private ReadEpochs $readEpochs;

    /** @var array<string, int> Scope => when it was last marked for a flush, from ReadEpochs::next() */
    private array $scopeMarks = [];
    private ActionRegistry $actionRegistry;
    private MessageBroker $broker;

    /** @var list<MiddlewareInterface> Global middleware applied to all page/action requests */
    private array $globalMiddleware = [];

    /** @var array<string, RouteDefinition> Route definitions indexed by route pattern */
    private array $routeDefinitions = [];

    /** Active URL prefix set by the currently executing group() closure */
    private string $groupPrefix = '';

    public function __construct(private Config $config) {
        // Initialize support classes
        $this->logger = new Logger($this->config->getLogLevel());
        $this->requestLogger = new RequestLogger($this->config->getDevMode());
        $this->logger->setRequestLogger($this->requestLogger);
        $this->stats = new Stats();

        // Dev Bar tracing substrate. Allocated here (master process, before fork)
        // so the per-worker tracer + buffer are inherited cleanly. When tracing
        // is off, Tracer::current() stays null and span call sites are no-ops.
        if ($this->config->isTracingEnabled()) {
            $this->traceStore = new TraceStore($this->config->getTraceBufferSize());
            $this->tracer = new Tracer($this->traceStore);
            Tracer::setCurrent($this->tracer);
            $this->logBuffer = new LogBuffer();
            $this->logger->setBuffer($this->logBuffer);
            $this->devBarInjector = new Injector($this->config);
        }

        $this->viewCache = new ViewCache();
        $this->htmlBuilder = new HtmlBuilder($this->config->getShellTemplate(), $this->log(...));
        $this->scopeRegistry = new ScopeRegistry();
        $this->signalManager = new SignalManager();
        $this->actionRegistry = new ActionRegistry();
        $this->readEpochs = new ReadEpochs();

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
        $this->sseHandler = new SseHandler($this);
        $actionHandler = new ActionHandler($this);
        $this->requestHandler = new RequestHandler($this, $this->sseHandler, $actionHandler);

        // Share request logger with HTTP handlers
        $this->sseHandler->setRequestLogger($this->requestLogger);
        $actionHandler->setRequestLogger($this->requestLogger);
        $this->requestHandler->setRequestLogger($this->requestLogger);

        // Initialize ViewRenderer with Twig from Application
        $this->viewRenderer = new ViewRenderer($this->app->getTwig(), $this->viewCache, $this->stats, $this->logger);

        // Broker: default to no-op InMemoryBroker; replaced via Config::withBroker().
        // Subscribe immediately so the handler is wired before connect() spawns the read loop.
        $this->broker = $this->config->getBroker();
        $this->broker->subscribe(function (string $scope): void {
            if (!Scope::isValidWireScope($scope)) {
                $this->log('warning', "Broker: rejected invalid scope \"{$scope}\" from wire");

                return;
            }

            // The broker's own catch only reaches an opt-in error handler, so log here.
            try {
                $this->receiveBroadcast($scope);
            } catch (\Throwable $e) {
                $this->log('error', "Broker sync failed for scope \"{$scope}\": " . Logger::describe($e));
            }
        });

        // Wire optional error handler (supported by RedisBroker and NatsBroker).
        $brokerErrorHandler = $this->config->getBrokerErrorHandler();

        if ($brokerErrorHandler !== null && method_exists($this->broker, 'setErrorHandler')) {
            $this->broker->setErrorHandler($brokerErrorHandler);
        }
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
     * Get the active broker instance.
     *
     * @internal Used by /_health endpoint
     */
    public function getBroker(): MessageBroker {
        return $this->broker;
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
     *
     * Last-write-wins. For a counter use {@see incrementGlobalState()} and for any other
     * read-modify-write use {@see mutateGlobalState()} — with more than one worker, reading a
     * value here and writing back a result computed from it loses concurrent updates.
     */
    public function setGlobalState(string $key, mixed $value): void {
        $this->app->setGlobalState($key, $value);
    }

    /**
     * Add to an integer global-state value atomically, returning the new value.
     *
     * The race-free alternative to `setGlobalState($k, globalState($k) + 1)`, which loses
     * updates once worker_num > 1.
     *
     * @throws \LogicException if the key is currently holding a non-integer
     */
    public function incrementGlobalState(string $key, int $by = 1): int {
        return $this->app->incrementGlobalState($key, $by);
    }

    /**
     * Read, transform and write a global-state value as one indivisible step.
     *
     * The race-free way to do read-modify-write on a non-integer — appending to a list,
     * updating one key of a map. The mutator receives null for a key nothing has written yet,
     * runs on this worker, and must not block: it holds a lock on the key.
     *
     * @template T
     *
     * @param callable(mixed): T $mutator
     *
     * @return T the value written
     */
    public function mutateGlobalState(string $key, callable $mutator): mixed {
        return $this->app->mutateGlobalState($key, $mutator);
    }

    /**
     * Get a per-session data value.
     *
     * Session data persists for the server process lifetime and is shared across
     * all browser tabs belonging to the same session.
     *
     * @param string $sessionId Session ID from $c->getSessionId()
     * @param string $key       Data key
     * @param mixed  $default   Value returned if key is not set
     */
    public function getSessionData(string $sessionId, string $key, mixed $default = null): mixed {
        return $this->app->getSessionData($sessionId, $key, $default);
    }

    /**
     * Set a per-session data value.
     */
    public function setSessionData(string $sessionId, string $key, mixed $value): void {
        $this->app->setSessionData($sessionId, $key, $value);
    }

    /**
     * Clear one key or all data for a session.
     *
     * @param string      $sessionId Session ID from $c->getSessionId()
     * @param null|string $key       Key to remove, or null to clear all session data
     */
    public function clearSessionData(string $sessionId, ?string $key = null): void {
        $this->app->clearSessionData($sessionId, $key);
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
     * Register global middleware applied to all page and action requests.
     *
     * Middleware implementing SseAwareMiddleware will additionally run on SSE
     * handshake requests.
     *
     * WARNING: Middleware instances are long-lived in Swoole — they persist across
     * all requests in the worker process. Do NOT store per-request state on
     * middleware properties. Use $request->withAttribute() to pass data downstream.
     */
    public function middleware(MiddlewareInterface ...$middleware): void {
        foreach ($middleware as $mw) {
            $this->globalMiddleware[] = $mw;
        }
    }

    /**
     * Get all registered global middleware.
     *
     * @internal used by HTTP handlers to build the middleware pipeline
     *
     * @return list<MiddlewareInterface>
     */
    public function getGlobalMiddleware(): array {
        return $this->globalMiddleware;
    }

    /**
     * Get route-specific middleware for a given route pattern.
     *
     * @internal used by RequestHandler to build per-route middleware pipeline
     *
     * @return list<MiddlewareInterface>
     */
    public function getRouteMiddleware(string $route): array {
        if (!isset($this->routeDefinitions[$route])) {
            return [];
        }

        return $this->routeDefinitions[$route]->getMiddleware();
    }

    /**
     * Register a page route with its handler.
     *
     * Returns a RouteDefinition for optional fluent middleware registration:
     * ```php
     * $app->page('/admin', fn(Context $c) => ...)->middleware(new AuthMiddleware());
     * ```
     *
     * @param string $route The route pattern (e.g., '/')
     */
    /**
     * Mount a composition-pattern page class at a route.
     *
     * The class must have a public `view(Context $ctx)` method and may declare
     * reactive properties with #[Signal] (optionally scoped, e.g. #[Signal(Scope::SESSION)]),
     * server-only state with #[Persist], a primary scope with #[Broadcast], action
     * methods with #[Action], and lifecycle hooks with #[OnDisconnect] / #[OnCleanup].
     *
     * @param class-string  $class   Page class name
     * @param string        $route   URL pattern (may contain {params})
     * @param null|callable $factory Optional factory — called instead of `new $class()` per connection.
     *                               Use this to inject constructor dependencies.
     *                               The factory should return an instance of $class.
     *
     * @throws \InvalidArgumentException if $class has no public view(Context) method
     */
    public function mount(string $class, string $route, ?callable $factory = null): RouteDefinition {
        $meta = ClassMetadata::analyze($class);

        return $this->page($route, PageMount::buildClosure($meta, $this, $factory));
    }

    public function page(string $route, callable $handler): RouteDefinition {
        if ($this->groupPrefix !== '') {
            $base = rtrim($this->groupPrefix, '/');
            $route = ($route === '' || $route === '/') ? $base : $base . '/' . ltrim($route, '/');
        }

        $definition = new RouteDefinition($route, $handler);
        $this->routeDefinitions[$route] = $definition;
        $this->router->registerRoute($route, $handler);

        return $definition;
    }

    /**
     * Register a group of routes that share a URL prefix and/or middleware.
     *
     * Optionally pass a URL prefix as the first argument — every `page()` call inside
     * the closure will have the prefix prepended to its route. Call `->middleware()` on
     * the returned RouteGroup to apply shared middleware to all routes in the group.
     *
     * ```php
     * // With prefix + middleware
     * $app->group('/admin', function (Via $app): void {
     *     $app->page('/', fn(Context $c) => $c->view('admin.html.twig'));      // → /admin
     *     $app->page('/users', fn(Context $c) => $c->view('users.html.twig')); // → /admin/users
     * })->middleware(new AuthMiddleware());
     *
     * // Middleware-only (no prefix, backward-compatible)
     * $app->group(function (Via $app): void {
     *     $app->page('/login/dashboard', fn(Context $c) => ...);
     *     $app->page('/login/profile', fn(Context $c) => ...);
     * })->middleware(new AuthMiddleware());
     * ```
     *
     * @param callable|string          $prefixOrFn URL prefix string, or the route closure for prefix-less grouping
     * @param null|callable(Via): void $fn         Route closure; omit when $prefixOrFn is the closure
     */
    public function group(callable|string $prefixOrFn, ?callable $fn = null): RouteGroup {
        if (\is_callable($prefixOrFn)) {
            $prefix = '';
            $fn = $prefixOrFn;
        } else {
            $prefix = $prefixOrFn;
        }

        $before = array_keys($this->routeDefinitions);
        $this->groupPrefix = $prefix;

        try {
            $fn($this);
        } finally {
            $this->groupPrefix = '';
        }
        $after = array_keys($this->routeDefinitions);

        // Collect only route definitions added inside the closure
        $newRoutes = array_diff($after, $before);
        $definitions = array_values(array_map(fn (string $r) => $this->routeDefinitions[$r], $newRoutes));

        return new RouteGroup($definitions);
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
     * Inside a coroutine this only marks the scope. The worker's next flush renders it once and
     * publishes it once, however often it was broadcast: at the end of the current event-loop
     * turn when the worker's last flush started at least Config::getBroadcastTickMs() ago and
     * ended at least half that ago, otherwise as soon as both have passed. Views render the state
     * as it is then; call flushBroadcasts() to force it. Outside a coroutine, during shutdown, or
     * with Config::withBroadcastCoalescing(false), it renders before returning, and the publish is
     * sent by this call or, when another coroutine is publishing, by that one. When the scope's
     * fan-out is already running in another coroutine, that fan-out runs once more for it instead,
     * and after 8 passes in a row the next flush renders it, except during shutdown, which drops it.
     *
     * @param string $scope Scope to broadcast to
     */
    public function broadcast(string $scope): void {
        // TAB scope is per-connection: no cross-node recipients exist.
        $publish = $scope !== Scope::TAB;

        if ($this->shouldCoalesce()) {
            $this->tracer?->span('broadcast.schedule', static fn () => null, ['scope' => $scope], 'sse');
            $this->markDirty($scope, $publish);
            $this->rememberCallerMark($scope);
            $this->scheduleFlush();

            return;
        }

        $this->syncLocally($scope);

        if (!$publish) {
            return;
        }

        if ($this->publishing) {
            // Another coroutine owns the broker connection; it sends this before it stops.
            $this->unpublishedScopes[$scope] = true;

            return;
        }

        $this->publishing = true;

        try {
            $this->broker->publish($scope);
        } finally {
            $this->publishing = false;

            if ($this->unpublishedScopes !== []) {
                $this->publishPending();
            }
        }
    }

    /**
     * Run the broadcasts scheduled so far in this worker now, in the calling coroutine.
     *
     * Use it when the fan-out has to reach clients before something the action queues next,
     * such as `broadcast(); flushBroadcasts(); execScript(...)`, or before shared state is
     * changed back. It ignores the broadcast tick. When a scope this coroutine broadcast is
     * being rendered by another flush, it waits for that fan-out first, up to 1 s; past that it
     * logs a warning, and that scope's frame follows on a later flush. Publishing to other
     * workers or nodes is done here too, unless a publish is already running, which then sends
     * these as well. A no-op when nothing is pending.
     */
    public function flushBroadcasts(): void {
        if (isset($this->runningFlushes[Coroutine::getCid()])) {
            // Called from a view during a flush: its marks go to the next one.
            return;
        }

        $mine = $this->takeCallerMarks();
        $this->waitWhile(fn (): bool => array_intersect_key($mine, $this->syncInFlight) !== []);
        foreach (array_intersect_key($mine, $this->syncInFlight) as $scope => $_) {
            $this->log('warning', "flushBroadcasts() stopped waiting for the running fan-out of \"{$scope}\"; its frame follows on a later flush");
        }

        $this->cancelScheduledFlush();
        $this->runTickFlush(inlinePublish: true);
        $this->scheduleFlush();
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
     * Unregister a context from a specific scope.
     *
     * @internal Called by Context::removeScope()
     */
    public function unregisterContextInScope(Context $context, string $scope): void {
        $this->scopeRegistry->unregisterContext($context, $scope);
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
        // Back the value with shared memory before anything reads it, so a worker mounting a
        // route another worker already serves adopts the live value instead of resetting the
        // scope to its own declared default.
        $this->sharedSignalStore?->attachTo($signal);

        $this->signalManager->registerSignal($scope, $signal);
    }

    /**
     * Install cross-worker backing for scoped signal values.
     *
     * @internal called from start() in the master process, and by tests that stand two Via
     *           instances in for two workers
     */
    public function setSharedSignalStore(?SharedSignalStore $store): void {
        $this->sharedSignalStore = $store;

        // Signals backed by the store read under its epochs, so fan-outs take theirs from it.
        // Contexts keep the epoch of their newest frame, so the new counter continues past the old one.
        $previous = $this->readEpochs;
        $this->readEpochs = $store?->readEpochs() ?? new ReadEpochs();
        $this->readEpochs->skipPast($previous->next());
        $this->scopeMarks = [];
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
            // Multi-worker guard: InMemoryBroker is a no-op — cross-worker broadcasts
            // will be silently lost. Fail loudly so operators don't run with broken config.
            if ($this->config->getWorkerNum() > 1 && $this->broker instanceof InMemoryBroker) {
                throw new \RuntimeException(
                    'worker_num > 1 requires a multi-worker broker. '
                    . 'Use SwooleBroker (same machine), RedisBroker, or NatsBroker. '
                    . 'Example: (new Config())->withWorkerNum(4)->withBroker(new SwooleBroker())'
                );
            }

            // Actions and scoped signal values now cross workers, but four things still do not,
            // and they fail quietly enough that an operator would not connect them to worker_num.
            if ($this->config->getWorkerNum() > 1) {
                $this->log(
                    'warn',
                    'worker_num > 1: actions, scoped signal values and the client list are shared across '
                    . 'workers. Four things are not. (1) Mutating a scoped signal by reading it and calling '
                    . 'setValue() loses updates — use Signal::increment() for counters and Signal::mutate() '
                    . 'for anything else. (2) PHP statics in your own handlers are per-process, so a '
                    . 'simulation kept in one diverges per worker. (3) A server-owned TAB signal '
                    . '(clientWritable: false, or any TAB signal without clientWritable: true under '
                    . 'withStrictTabSignals()) lives in one worker: an action another worker takes rebuilds '
                    . 'it from its initial value, so keep that state in a scoped signal or use worker_num = 1. '
                    . '(4) Session data (sessionData()/setSessionData()) is per worker; use a Scope::SESSION '
                    . 'signal or worker_num = 1. See https://via.zweiundeins.gmbh/docs/deployment#same-machine'
                );
            }

            // Validate Brotli requirements before binding any socket
            if ($this->config->getBrotli()) {
                if (!\function_exists('brotli_compress_init')) {
                    throw new \RuntimeException(
                        'withBrotli() requires the ext-brotli PHP extension. Install it with: pecl install brotli'
                    );
                }
                if (!$this->config->isHttps() && !$this->config->isH2c()) {
                    throw new \RuntimeException(
                        'withBrotli() requires HTTP/2. Call withCertificate($certFile, $keyFile) for direct HTTPS, '
                        . 'or withH2c() when behind a TLS-terminating reverse proxy (Caddy, Nginx).'
                    );
                }
                // Auto-register BrotliMiddleware as the outermost global middleware
                array_unshift($this->globalMiddleware, new BrotliMiddleware($this->config->getBrotliDynamicLevel()));
            }

            // Validate embeddable (SameSite=None) requirements before binding any socket.
            // A SameSite=None cookie without Secure is silently dropped by browsers, and Secure
            // cookies are only honoured over HTTPS (direct TLS or TLS-terminating proxy via h2c).
            if ($this->config->getSessionCookieSameSite() === 'None') {
                if (!$this->config->getSecureCookie()) {
                    throw new \RuntimeException(
                        'withEmbeddable() requires Secure cookies; do not call withSecureCookie(false) after it.'
                    );
                }
                if (!$this->config->isHttps() && !$this->config->isH2c()) {
                    throw new \RuntimeException(
                        'withEmbeddable() sets SameSite=None, which requires Secure cookies: '
                        . 'enable withCertificate() (HTTPS) or withH2c() (TLS-terminating proxy).'
                    );
                }
            }

            $socketType = $this->config->isHttps()
                ? (SWOOLE_SOCK_TCP | SWOOLE_SSL)
                : SWOOLE_SOCK_TCP;
            $this->server = new Server($this->config->getHost(), $this->config->getPort(), Server::POOL_MODE, $socketType);

            // Configure OpenSwoole for SSE streaming
            $this->server->set(self::serverSettings($this->config));

            $this->requestHandler->setRoutes($this->router->getRoutes());

            // SharedTable: allocate in master process so it is mmap'd into all workers
            // on fork. Only needed when worker_num > 1 — single-worker uses a plain PHP array.
            // The shared table is needed whenever GlobalState has to be visible beyond one
            // process OR dirty-tracked for persistence, so persistence pulls it in even when
            // running single-worker.
            $persistPath = $this->config->getGlobalStatePath();

            if ($this->config->getWorkerNum() > 1 || $persistPath !== null) {
                $sharedTable = new SharedTable(
                    $this->config->getGlobalStateTableRows(),
                    $this->config->getGlobalStateTableValueBytes(),
                );
                $this->app->setSharedTable($sharedTable);

                if ($persistPath !== null) {
                    $this->installGlobalStatePersistence($sharedTable, $persistPath);
                }
            }

            if ($this->config->getWorkerNum() > 1) {
                // Same reason, same timing: scoped signal VALUES have to be visible across
                // workers or every worker runs its own divergent copy of the scope.
                $this->setSharedSignalStore(new SharedSignalStore(
                    $this->config->getScopedSignalTableRows(),
                    $this->config->getScopedSignalTableValueBytes(),
                ));

                // Lets any worker rebuild a context created by any other, which is what turns
                // an action landing on the "wrong" worker from a 400 into a served request.
                $this->app->setContextDirectory(new SharedContextDirectory(
                    $this->config->getContextDirectoryRows(),
                    $this->config->getContextDirectoryRecordBytes(),
                ));

                // So getClients() and the connect/disconnect hooks see the whole server rather
                // than whichever streams this worker happened to serve.
                $this->app->setClientRegistry(new SharedClientRegistry(
                    $this->config->getContextDirectoryRows(),
                ));
            }

            // SwooleBroker receive path: decode inter-worker pipe messages and apply
            // them locally. Registered here (master-process context) so it is active
            // before any worker starts. The nodeId filter prevents double-syncs when
            // a worker receives its own publish (shouldn't happen with the self-skip
            // in SwooleBroker::publish(), but kept as a belt-and-suspenders guard).
            $this->server->on('pipeMessage', function (Server $server, int $srcWorkerId, string $data): void {
                try {
                    $this->handlePipeMessage($srcWorkerId, $data);
                } catch (\Throwable $e) {
                    $this->log('error', "pipeMessage from worker {$srcWorkerId} failed: " . Logger::describe($e));
                }
            });

            $this->server->on('start', function (Server $server): void {
                $scheme = $this->config->isHttps() ? 'https' : 'http';
                $this->log('info', "Via server started on {$scheme}://{$this->config->getHost()}:{$this->config->getPort()}");

                // Write master PID so external tools (e.g. scripts/dev.sh) can send
                // SIGUSR1 to the correct process for hot worker reload.
                if ($this->config->getDevMode()) {
                    $pidFile = sys_get_temp_dir() . '/php-via-master.pid';
                    file_put_contents($pidFile, (string) $server->master_pid);
                }

                // Not SIGTERM: OpenSwoole owns it in server processes and shuts down on it itself.
                $this->registerSignal(SIGINT, function () use ($server): void {
                    $server->shutdown();
                });
            });

            // OpenSwoole's manager keeps the default SIGINT action, so Ctrl-C killed it and
            // orphaned the workers. The master gets the same SIGINT and drives the stop.
            $this->server->on('managerStart', function (Server $server): void {
                $this->registerSignal(SIGINT, function (): void {});
            });

            // Master only, after every worker has stopped, so exactly one process drains.
            $this->server->on('shutdown', function (Server $server): void {
                if ($this->finalGlobalStateDrain !== null) {
                    ($this->finalGlobalStateDrain)();
                }
            });

            $this->server->on('workerStart', function (Server $server, int $workerId): void {
                $this->workerStarted = true;
                $this->app->claimWorker($workerId);

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

                // Refresh route table: startCallbacks may have registered new routes (e.g. via
                // the thin-bootstrap pattern where route registration is deferred to onWorkerStart
                // so that USR1 hot reload picks up fresh class definitions from disk).
                $this->requestHandler->setRoutes($this->router->getRoutes());

                // Register server intervals (registered via setInterval()).
                //
                // Armed on the leader worker only unless the caller opted into every worker.
                // These are registered inside workerStart, so without the gate each of the N
                // workers armed its own Timer::tick and a "once per server" job ran N times —
                // and, if it broadcasts, delivered N^2 times.
                foreach ($this->serverIntervals as [$callback, $ms, $everyWorker]) {
                    if (!$everyWorker && $workerId !== self::LEADER_WORKER_ID) {
                        continue;
                    }

                    $id = Timer::tick($ms, function () use ($callback): void {
                        try {
                            $callback();
                        } catch (\Throwable $e) {
                            $this->log('error', 'Interval callback failed: ' . Logger::describe($e));
                        }
                    });

                    if ($id !== false) {
                        $this->serverIntervalIds[] = $id;
                    }
                }

                // Proactive GC timer: call gc_collect_cycles() on a fixed schedule
                // to prevent unpredictable mid-request pauses. PHP's automatic cycle
                // collector fires when its root buffer fills (~10,000 roots); calling
                // it periodically spreads the work out during idle gaps between requests.
                $gcIntervalMs = $this->config->getGcIntervalMs();
                if ($gcIntervalMs > 0) {
                    $id = Timer::tick($gcIntervalMs, fn () => $this->runGcCycle());

                    if ($id !== false) {
                        $this->serverIntervalIds[] = $id;
                    }
                }

                if ($workerId === self::LEADER_WORKER_ID) {
                    $id = Timer::tick(self::DEAD_CLIENT_SWEEP_MS, fn () => $this->app->removeDeadClients());

                    if ($id !== false) {
                        $this->serverIntervalIds[] = $id;
                    }
                }

                if ($this->app->getContextDirectory() !== null) {
                    $id = Timer::tick(self::sseHeartbeatIntervalMs($this->config), function (): void {
                        try {
                            $this->sseHandler->heartbeatStreams();
                        } catch (\Throwable $e) {
                            $this->log('error', 'SSE heartbeat failed: ' . Logger::describe($e));
                        }
                    });

                    if ($id !== false) {
                        $this->serverIntervalIds[] = $id;
                    }
                }

                // A browser that cancels one HTTP/2 stream keeps the connection, so no close event tells the worker.
                if ((self::serverSettings($this->config)['open_http2_protocol'] ?? false) === true) {
                    $id = Timer::tick(SseHandler::RESET_CHECK_MS, function (): void {
                        try {
                            $this->sseHandler->endResetStreams();
                        } catch (\Throwable $e) {
                            $this->log('error', 'SSE reset check failed: ' . Logger::describe($e));
                        }
                    });

                    if ($id !== false) {
                        $this->serverIntervalIds[] = $id;
                    }
                }

                // OpenSwoole only calls workerExit while the reactor has something alive. Without
                // this an idle worker skipped it and ran the shutdown outside any coroutine.
                Timer::tick(60_000, static function (): void {});

                // Connect broker and subscribe to foreign invalidations.
                // Must run inside workerStart (coroutine context) so that async
                // brokers (Redis, NATS) can spawn their receive-loop coroutines.
                try {
                    $this->broker->connect();

                    // Inject server reference into ServerAwareBroker implementations (e.g. SwooleBroker).
                    // Must happen after connect() so the worker is fully initialised.
                    if ($this->broker instanceof ServerAwareBroker) {
                        $this->broker->setServer($server, $workerId, self::resolveWorkerNum($server));
                    }

                    // Log connection so operators know each worker opens its own broker
                    // connection and can plan backend limits accordingly.
                    if (!$this->broker instanceof InMemoryBroker) {
                        $this->log('info', 'Broker ' . \get_class($this->broker) . " connected in worker {$workerId}");
                    }
                } catch (\Throwable $e) {
                    $this->log('error', "Broker connect failed in worker {$workerId}: " . $e->getMessage() . ' — running without multi-node broadcast');
                }
            });

            // Fires when a worker process exits abnormally (crash, OOM kill, fatal).
            // exitCode is the PHP exit code; signal is the OS signal that killed it (e.g. 9 = SIGKILL).
            $this->server->on('workerError', function (Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void {
                $reason = $signal > 0 ? "signal={$signal}" : "exit_code={$exitCode}";
                $this->logger->fatal("Worker {$workerId} (pid {$workerPid}) crashed: {$reason}");
            });

            // Called on every reactor pass while coroutines are still alive. Cleanup runs in a
            // coroutine so the SSE loops and hooked I/O in callbacks can finish before max_wait_time.
            $this->server->on('workerExit', function (Server $server, int $workerId): void {
                if (!$this->shutdownStarted) {
                    Coroutine::create(fn () => $this->runWorkerShutdown());
                }
                Timer::clearAll();
            });

            // No-op after workerExit; covers a worker that stops without workerExit, in which
            // case the callbacks run outside a coroutine.
            $this->server->on('workerStop', function (Server $server, int $workerId): void {
                $this->runWorkerShutdown();
            });

            $this->server->on('request', function (Request $request, Response $response): void {
                $this->requestHandler->handleRequest($request, $response);
            });

            // Runs in the worker that owns the connection, about 1 ms after the client's FIN. OpenSwoole
            // rejects it under dispatch_mode 1, 3 and 7; the SSE keep-alive wake covers those.
            if (self::deliversCloseEvents(self::serverSettings($this->config))) {
                $this->server->on('close', function (Server $server, int $fd): void {
                    try {
                        $this->sseHandler->onConnectionClose($fd);
                    } catch (\Throwable $e) {
                        $this->log('error', "Close handling failed for connection {$fd}: " . Logger::describe($e));
                    }
                });
            }
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
     *
     * Callbacks run once in each worker process when that worker stops: on SIGTERM or SIGINT to
     * the master, `$server->shutdown()`, and also on a worker reload (SIGUSR1) or a `max_request`
     * recycle. A callback cannot tell a reload from a stop. They run inside a coroutine, each in its
     * own try/catch, after waiting up to half the stop budget for open SSE streams to finish
     * (no wait when `max_wait_time` is below 2). OpenSwoole counts `max_wait_time` in whole seconds,
     * so the budget for the stop is roughly `max_wait_time` minus up to one second.
     *
     * End long-lived coroutines, sockets and `Event::add` fds here (or check isShuttingDown() in
     * the loop): anything still alive holds the worker until `max_wait_time`, then it is killed.
     */
    public function onShutdown(callable $callback): void {
        $this->shutdownCallbacks[] = $callback;
    }

    /**
     * Register a recurring server timer that fires every $ms milliseconds.
     *
     * Unlike Context::setInterval() (per-tab), this timer is not tied to a connection. Use it
     * for background jobs: periodic broadcasts, cache refreshes, cleanup tasks, leaderboard ticks.
     *
     * By default the timer is armed on the leader worker only, so the job runs once per server
     * however many workers are configured. Pass `everyWorker: true` for work that is genuinely
     * per-process — trimming a per-worker cache, reporting per-worker metrics.
     *
     * Note that "once per server" governs the TIMER, not the state it touches. A job that mutates
     * a PHP static or a per-worker signal still only mutates the leader's copy, and the other
     * workers render from their own. Simulations that keep state that way need `worker_num = 1`
     * until that state is shared.
     *
     * The callback is wrapped in a try/catch — errors are logged and the timer continues.
     * All registered intervals are automatically cleared on graceful shutdown.
     *
     * Example:
     * ```php
     * $app->setInterval(function () use ($app): void {
     *     $app->broadcast(Scope::GLOBAL);
     * }, 5000); // every 5 seconds, once for the whole server
     * ```
     *
     * @param callable(): void $callback    Called every $ms milliseconds
     * @param int              $ms          Interval in milliseconds (must be > 0)
     * @param bool             $everyWorker Arm the timer in every worker instead of the leader
     */
    public function setInterval(callable $callback, int $ms, bool $everyWorker = false): void {
        $this->serverIntervals[] = [$callback, $ms, $everyWorker];
    }

    /**
     * Register a handler to call when no route matches (404).
     * The handler receives the raw OpenSwoole Request and Response.
     * It is responsible for setting the status code and ending the response.
     *
     * @param callable(Request, Response): void $handler
     */
    public function notFound(callable $handler): self {
        $this->notFoundHandler = $handler;

        return $this;
    }

    /**
     * @return null|callable(Request, Response): void
     */
    public function getNotFoundHandler(): ?callable {
        return $this->notFoundHandler;
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
                $this->log('error', 'onClientConnect callback failed: ' . Logger::describe($e), $context);
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
                $this->log('error', 'onClientDisconnect callback failed: ' . Logger::describe($e), $context);
            }
        }
    }

    /**
     * Whether this worker has begun stopping (stop, reload or recycle).
     *
     * Background loops can check it to end on their own before `max_wait_time` runs out.
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
     * Get all connected clients, across all workers.
     *
     * A broadcast reads the list once for all the views it renders. It reads it again when a
     * client connects or leaves on this worker meanwhile, or when other code on this worker reads
     * the list while a view in the broadcast waits on I/O.
     *
     * @return array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}>
     */
    public function getClients(): array {
        return $this->app->getClients($this->readEpochs->current());
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
     * Get the trace ring buffer, or null when tracing is disabled.
     *
     * @internal Used by the Dev Bar endpoints
     */
    public function getTraceStore(): ?TraceStore {
        return $this->traceStore;
    }

    /**
     * Get the ambient tracer, or null when tracing is disabled.
     *
     * @internal Used by HTTP handlers to open request/action traces
     */
    public function getTracer(): ?Tracer {
        return $this->tracer;
    }

    /**
     * Get the Dev Bar log buffer, or null when tracing is disabled.
     *
     * @internal Used by the Dev Bar stream
     */
    public function getLogBuffer(): ?LogBuffer {
        return $this->logBuffer;
    }

    /**
     * Run one GC cycle: collect circular references, log memory usage, update stats.
     *
     * Called by the GC timer (configurable via Config::withGcInterval()) and
     * exposed publicly so it can be invoked directly in tests or from user code.
     */
    public function runGcCycle(): void {
        $cycles = gc_collect_cycles();
        $memMb = round(memory_get_usage(true) / 1_048_576, 1);
        $peakMb = round(memory_get_peak_usage(true) / 1_048_576, 1);
        $this->log('debug', "GC: {$cycles} cycles freed, mem={$memMb}MB peak={$peakMb}MB");
        $this->stats->trackGc($cycles);
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
        return $this->sessionManager->getOrCreateSessionId($request, $this->config->getSecureCookie());
    }

    /**
     * Set session cookie in response.
     *
     * @internal Used by HTTP handlers
     */
    public function setSessionCookie(Response $response, string $sessionId): void {
        $config = $this->app->getConfig();
        $this->sessionManager->setSessionCookie(
            $response,
            $sessionId,
            $config->getSecureCookie(),
            $config->getSessionCookieSameSite(),
            $config->isSessionCookiePartitioned(),
        );
    }

    /**
     * Read Datastar signals from an OpenSwoole HTTP request.
     *
     * Delegates to parseSignals() with the request's raw parts.
     *
     * @internal Used by HTTP handlers
     *
     * @return array<string, mixed> The decoded signals array
     */
    public static function readSignals(Request $request): array {
        return self::parseSignals(
            $request->get ?? [],
            $request->post ?? [],
            $request->getContent(),
        );
    }

    /**
     * Parse Datastar signals from raw request parts.
     *
     * Signal source priority:
     *  1. GET  ?datastar=<json>          — Datastar GET actions
     *  2. Raw JSON body                  — Datastar POST/PATCH actions (application/json)
     *  3. POST datastar=<json> field     — Datastar POST via multipart/form-data or
     *                                      application/x-www-form-urlencoded
     *
     * Exposed as a public static method so it can be tested without an OpenSwoole
     * Request instance (which is a final extension class).
     *
     * @param array<string, mixed> $get  Parsed GET parameters
     * @param array<string, mixed> $post Parsed POST parameters
     * @param false|string         $body Raw request body
     *
     * @return array<string, mixed> The decoded signals array
     */
    public static function parseSignals(array $get, array $post, false|string $body): array {
        // 1. GET ?datastar=<json>
        if (isset($get['datastar'])) {
            $signals = json_decode((string) $get['datastar'], true);

            return \is_array($signals) ? $signals : [];
        }

        // 2. Raw JSON body (standard Datastar POST/PATCH action)
        if ($body) {
            $signals = json_decode($body, true);
            if (\is_array($signals)) {
                return $signals;
            }
        }

        // 3. POST field datastar=<json> (multipart/form-data or urlencoded form submission)
        if (isset($post['datastar'])) {
            $signals = json_decode((string) $post['datastar'], true);

            return \is_array($signals) ? $signals : [];
        }

        return [];
    }

    /**
     * Schedule context cleanup after a delay.
     * Allows time for reconnection or navigation between pages.
     *
     * @param null|int $delayMs Grace period in milliseconds. Null uses
     *                          Config::getContextCleanupDelayMs().
     *
     * @internal Used by HTTP handlers
     */
    public function scheduleContextCleanup(string $contextId, ?int $delayMs = null): void {
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

        // Pass an active-SSE guard so the timer won't destroy a context that has
        // a live SSE connection (can happen under load when the cleanup timer fires
        // before the next SSE reconnection completes its handshake).
        $this->app->scheduleContextCleanup(
            $contextId,
            $delayMs,
            fn (): bool => ($this->activeSseCount[$contextId] ?? 0) > 0,
        );
    }

    /**
     * Rebuild a destroyed context so a returning tab keeps its view instead of hard-reloading.
     *
     * When an SSE reconnect names a context that was already cleaned up, this re-creates it with
     * the *same* ID (so signal IDs regenerate byte-identical and the already-loaded DOM — bindings,
     * action URLs, via_ctx — keeps working), re-runs the page handler, and re-seeds TAB signal
     * values from what the client still holds (sent with the reconnect). Returns null — and the
     * caller falls back to a full reload — when revival is disabled, no record exists, it expired,
     * the requester's session doesn't own the context, or the route is no longer registered.
     *
     * @internal used by SseHandler on reconnect to a missing context
     */
    public function reviveContext(string $contextId, Request $request): ?Context {
        return $this->reviveContextFromClient(
            $contextId,
            $this->getSessionId($request),
            self::readSignals($request),
            $request->cookie ?? [],
        );
    }

    /**
     * Testable core of {@see reviveContext()}, free of OpenSwoole Request types (mirrors the
     * readSignals/parseSignals split so it can be exercised without a live server).
     *
     * @param string                $requesterSession Session ID of the reconnecting client
     * @param array<string, mixed>  $clientSignals    Signal values the client still holds
     * @param array<string, string> $cookies          Request cookies (forwarded to the context)
     *
     * @internal
     */
    public function reviveContextFromClient(string $contextId, string $requesterSession, array $clientSignals, array $cookies = []): ?Context {
        if ($this->config->getContextRevivalWindowMs() <= 0) {
            return null;
        }

        $record = $this->app->getRevivable($contextId);
        if ($record === null) {
            return null;
        }

        // Session-ownership gate: only the session that owned the context may revive it.
        if ($record['sessionId'] !== null && $record['sessionId'] !== $requesterSession) {
            $this->log('warning', "Revival denied (session mismatch) for context {$contextId}");

            return null;
        }

        $route = $record['route'];
        $handler = $this->router->getRoutes()[$route] ?? null;
        if ($handler === null) {
            // Route no longer registered (e.g. after a code change) — fall back to reload.
            $this->app->forgetRevivable($contextId);

            return null;
        }

        $sessionId = $record['sessionId'] ?? $requesterSession;

        // Rebuild with the SAME id so the DOM's existing signal/action/via_ctx references resolve.
        $context = new Context($contextId, $route, $this, null, $sessionId);
        $this->contextSessions[$contextId] = $sessionId;
        $context->injectRouteParams($record['params']);
        $context->setRequestCookies($cookies);

        try {
            $this->invokeHandlerWithParams($handler, $context, $record['params']);
        } catch (\Throwable $e) {
            $this->log('error', "Revival handler exception on {$route}: " . Logger::describe($e));

            return null;
        }

        // Register the rebuilt context exactly as an initial page load would.
        $this->contexts[$contextId] = $context;
        $this->app->registerContext($context);
        $this->app->setContextSession($contextId, $sessionId);
        $this->registerContextInScope($context, Scope::TAB);

        // Seed signal values the client still holds (sent with the /_sse reconnect), matched by
        // signal ID since the context ID was reused. Only client-writable signals take them.
        $context->injectSignals($clientSignals);

        // Local record consumed — drop it so this worker's map never holds already-rebuilt
        // contexts. The SHARED directory entry deliberately survives: it is how every other
        // worker rebuilds this same context, and registerContext() above has just refreshed it.
        $this->app->forgetLocalRevivable($contextId);

        $this->log('info', "Revived context {$contextId} on route {$route}", $context);

        return $context;
    }

    /**
     * Build complete HTML document.
     *
     * @internal Used by HTTP handlers
     */
    public function buildHtmlDocument(Context $context): string {
        $content = $context->renderView();

        $html = $this->htmlBuilder->buildDocument($content, $context, $context->getId(), $this->config->getBasePath());

        // Inject the Dev Bar overlay before </body> when tracing is enabled.
        if ($this->devBarInjector !== null) {
            $html = $this->devBarInjector->inject($html, $context);
        }

        return $html;
    }

    /**
     * Decorate an SSE update render of a page.
     *
     * Datastar morphs the whole document, `<head>` included, so head/foot includes missing from a
     * full `<html>` update would be removed on the first morph, and the Dev Bar overlay from any
     * update that carries `</body>`. Both are re-added (idempotently); components are left untouched.
     *
     * @internal Used by PatchManager during sync
     */
    public function decorateUpdate(string $html, Context $context): string {
        if ($context->getComponentManager()->isComponent()) {
            return $html;
        }

        if (stripos($html, '<html') !== false) {
            $html = $this->htmlBuilder->injectIntoDocument($html, $context, initial: false);
        }

        if ($this->devBarInjector === null || stripos($html, '</body>') === false) {
            return $html;
        }

        return $this->devBarInjector->inject($html, $context);
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
     * Build the default OpenSwoole server settings for a config.
     *
     * Extracted from start() so the values are reachable from tests. The
     * dispatch settings that used to live here were wrong and untestable, which is
     * precisely how they survived: `SessionManager::workerForRequest()` was well
     * covered, but nothing ever asserted that it was wired up correctly.
     *
     * NOTE — no `dispatch_mode` / `dispatch_func` is set, deliberately.
     * Multi-worker previously set `dispatch_mode = 7` with a `dispatch_func`, but
     * `SW_DISPATCH_USERFUNC` is 6; 7 is stream mode and ignores `dispatch_func`
     * entirely, so session affinity never ran — and mode 7 scatters per REQUEST
     * where OpenSwoole's default is sticky per CONNECTION, making the feature
     * measurably worse than its absence (56.5% vs 100% OK for a keep-alive client
     * at 16 workers).
     *
     * Correcting it to 6 is not shippable either: on PHP 8.3+ a `dispatch_func`
     * runs on the master reactor thread, where the stack-limit check mis-detects
     * the stack base and fatals on every dispatch ("Maximum call stack size ...
     * reached. Infinite recursion?"). Only `zend.max_allowed_stack_size=-1` clears
     * it, and that ini is not settable at runtime — so this cannot be fixed from
     * PHP, and PHP 8.4 is this project's minimum.
     *
     * OpenSwoole's default dispatch is therefore left in place; it is sticky per
     * connection, which is what browser clients need. Cross-connection session
     * affinity belongs at the L7 proxy, as the deployment docs already require.
     * An operator who has set the ini can still opt in via
     * Config::withSwooleSettings(), which is merged over these defaults.
     *
     * @return array<string, mixed>
     *
     * @internal
     */
    public static function serverSettings(Config $config): array {
        $defaults = [
            'open_http2_protocol' => $config->isHttps() || $config->isH2c(),
            'http_compression' => false,
            // buffer_output_size: per-connection TCP send-buffer cap before send_yield kicks in.
            // In POOL_MODE all sends go through the master reactor pipe, so 0 would cause
            // ERRNO 1203 on every send. 2MB is the OpenSwoole default; send_yield=true
            // handles backpressure without stalling. SSE events are flushed per-chunk by
            // OpenSwoole's HTTP chunked-transfer encoding, not held in this buffer.
            'socket_buffer_size' => 1024 * 1024,
            'max_coroutine' => 100000,
            'worker_num' => $config->getWorkerNum(),  // POOL_MODE enables USR1 graceful worker reload
            'send_yield' => true,
            'max_wait_time' => 3,  // Seconds a stopping worker gets for SSE exits and onShutdown
            'reload_async' => true,  // Enable async reload
            'enable_reuse_port' => true,  // Allow immediate rebind on restart
            'hook_flags' => SWOOLE_HOOK_ALL,  // Enable coroutine hooks for native functions (sleep, usleep, etc.)
            'log_level' => 4,  // SWOOLE_LOG_WARNING — suppress NOTICE about sending to closed connections
            // Connection limits: prevent a burst of SSE connections from exhausting the
            // accept queue and making the server unresponsive. Callers can override via
            // Config::withSwooleSettings(). 10k connections is generous for single-worker.
            'max_conn' => 10000,
            'backlog' => 4096,  // OS accept queue depth (needs net.core.somaxconn ≥ this)
        ];

        // Caller overrides win over the defaults; explicit SSL paths win over both.
        return array_merge($defaults, $config->getSwooleSettings(), array_filter([
            'ssl_cert_file' => $config->getSslCertFile(),
            'ssl_key_file' => $config->getSslKeyFile(),
        ]));
    }

    /**
     * Whether OpenSwoole delivers the close event under these settings: not in dispatch_mode 1, 3 or 7.
     *
     * @param array<string, mixed> $settings
     *
     * @internal
     */
    public static function deliversCloseEvents(array $settings): bool {
        return !\in_array((int) ($settings['dispatch_mode'] ?? 2), [1, 3, 7], true);
    }

    /**
     * How often each worker rewrites the directory records of the contexts it streams to: 150 s by default.
     * A quarter of the TTL or of the revival window, which a worker that destroys its copy cuts the record to.
     *
     * @internal
     */
    public static function sseHeartbeatIntervalMs(Config $config): int {
        $seconds = $config->getContextDirectoryTtlSeconds();
        $windowMs = $config->getContextRevivalWindowMs();
        if ($windowMs > 0) {
            $seconds = min($seconds, (int) ceil($windowMs / 1000));
        }

        return max(1000, intdiv($seconds * 1000, 4));
    }

    /**
     * The running OpenSwoole server, or null before start() / in test mode.
     *
     * @internal Used by SseHandler to read per-connection send backlog
     */
    public function getServer(): ?Server {
        return $this->server;
    }

    /**
     * Resolve a running server's worker count.
     *
     * ext-openswoole 26 exposes this as `$server->setting['worker_num']` and has no
     * `worker_num` property. Reading the property directly emitted an "Undefined
     * property" warning and passed null into ServerAwareBroker::setServer(), whose
     * TypeError was swallowed by the surrounding catch — leaving the broker without a
     * server reference and silently disabling all cross-worker broadcast.
     *
     * The legacy property is still honoured so older builds keep working.
     *
     * @internal Used by workerStart when wiring ServerAwareBroker implementations
     */
    public static function resolveWorkerNum(object $server): int {
        /** @var array<string, mixed> $setting */
        $setting = property_exists($server, 'setting') && \is_array($server->setting) ? $server->setting : [];

        if (isset($setting['worker_num']) && is_numeric($setting['worker_num'])) {
            return max(1, (int) $setting['worker_num']);
        }

        if (property_exists($server, 'worker_num') && is_numeric($server->worker_num)) {
            return max(1, (int) $server->worker_num);
        }

        return 1;
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
     * Seed GlobalState from its durable snapshot and arm the write-behind flush.
     *
     * Called from start() in the master process, before the fork, so the seeded table is the one
     * every worker inherits. The boot connection is closed before the fork: the leader worker's
     * flushes and the master's final drain each open their own, and never run at the same time.
     */
    private function installGlobalStatePersistence(SharedTable $table, string $path): void {
        $snapshot = new SqliteSnapshot($path);

        $loaded = 0;
        foreach ($snapshot->load() as $key => $serialized) {
            try {
                // seed(), not set(): these came FROM storage, and marking them dirty would
                // write the entire set straight back on the first tick.
                $table->seed($key, $serialized);
                ++$loaded;
            } catch (\Throwable $e) {
                // A key or value that no longer fits the configured table is not a reason to
                // refuse to boot; the rest of the snapshot is still usable.
                $this->log('warn', "GlobalState snapshot: skipped \"{$key}\" — " . $e->getMessage());
            }
        }

        $snapshot->close();

        if ($loaded > 0) {
            $this->log('info', "GlobalState restored {$loaded} keys from {$path}");
        }

        // Leader-only by default (see setInterval()), which is exactly the single-writer
        // property this needs: one process draining the dirty set into one transaction.
        $flush = function () use ($table, $snapshot): void {
            $dirty = $table->takeDirty();
            if ($dirty === []) {
                return;
            }

            try {
                $snapshot->save($dirty);
            } catch (\Throwable $e) {
                $this->log('error', 'GlobalState flush failed: ' . $e->getMessage());
            }
        };
        $this->setInterval($flush, $this->config->getGlobalStateFlushMs());

        // The leader flushes once more when it stops: a second SIGTERM to the master can end it
        // before its shutdown event, and then only this flush saves the last window.
        $this->onShutdown(function () use ($flush): void {
            if ($this->server?->getWorkerId() === self::LEADER_WORKER_ID) {
                $flush();
            }
        });

        // Final drain on the way down, so a graceful stop does not discard the last window.
        $this->finalGlobalStateDrain = function () use ($table, $snapshot): void {
            try {
                $snapshot->save($table->takeDirty());
                $snapshot->checkpoint();
                $snapshot->close();
            } catch (\Throwable $e) {
                $this->log('error', 'GlobalState final flush failed: ' . $e->getMessage());
            }
        };
    }

    /**
     * Sync all local contexts matching the given scope and invalidate view cache.
     *
     * Called by a flush, and directly by broadcast() and the broker receive paths when they
     * do not coalesce.
     *
     * @param null|array<int, int> $rendered contexts this flush already rendered, by spl_object_id => the read epoch
     *                                       they were rendered under; the first pass skips those rendered after the scope's mark
     */
    private function syncLocally(string $scope, ?array &$rendered = null): void {
        // Serialize fan-outs per scope.
        //
        // doSyncLocally() renders each context in a loop, and a render can suspend —
        // a first-ever Twig compile, or any hooked file I/O in a view, yields under
        // SWOOLE_HOOK_ALL. A second broadcast could then run its ENTIRE fan-out before
        // the first resumed, so the first loop's remaining contexts rendered against
        // newer state and some clients never saw the intervening frame at all.
        //
        // This cannot be solved by rendering once and pushing that value to every
        // context: cacheUpdates=false exists precisely because those views may differ
        // per context (LoginExample renders per-user session state), so sharing one
        // render across contexts would leak one user's view to another.
        //
        // A broadcast that arrives mid-fan-out is therefore folded into a single
        // re-run afterwards, which also collapses broadcast storms into one extra
        // pass. After MAX_SYNC_PASSES passes one still owed goes to the next flush.
        // Note this bounds interleaving between fan-outs, not mutation of application
        // state during one: a view reading a PHP static that an action changes
        // mid-loop is beyond what the framework can snapshot.
        if (isset($this->syncInFlight[$scope])) {
            if ($this->isOwnPass($this->syncInFlight[$scope])) {
                $this->syncReentered[$scope] = true;
            } else {
                $this->syncPending[$scope] = true;
            }

            return;
        }

        $this->syncInFlight[$scope] = ['cid' => Coroutine::getCid(), 'lastCid' => (int) (Coroutine::stats()['coroutine_last_cid'] ?? PHP_INT_MAX), 'since' => hrtime(true), 'warned' => false];

        // Wrap fan-out in a "broadcast {scope}" root trace. Inside an action (the
        // synchronous path, or flushBroadcasts()) this is a no-op: the action trace is
        // already open and the render spans nest under it. A deferred flush, a timer
        // or a broker message opens its own trace so those renders are visible.
        $tracer = $this->tracer;
        $traceStarted = $tracer !== null && $tracer->startTrace('broadcast ' . $scope, 'sse');

        // Each scoped signal is read from shared memory once per pass, not once per context.
        // Inside a flush this joins the flush's epoch, so its scopes share the reads.
        $joinedEpoch = $this->readEpochs->begin();

        try {
            $passes = 0;
            $reenteredPasses = 0;

            do {
                unset($this->syncPending[$scope], $this->syncReentered[$scope]);
                $this->syncFailures[$scope] = [];

                // A re-run exists because state changed mid-pass, so it reads and renders everything again. A scope
                // marked since the epoch began reads again too, and renders what this flush rendered before the mark.
                $markedAt = $passes > 0 ? PHP_INT_MAX : ($this->scopeMarks[$scope] ?? 0);
                unset($this->scopeMarks[$scope]);
                if ($markedAt > $this->readEpochs->current()) {
                    $this->readEpochs->renew();
                }

                try {
                    $this->doSyncLocally($scope, $rendered, skipRenderedAfter: $markedAt);
                } finally {
                    $this->logSyncFailures($scope);
                }
                ++$passes;
                if (isset($this->syncReentered[$scope])) {
                    ++$reenteredPasses;
                }
            } while ((isset($this->syncPending[$scope]) || isset($this->syncReentered[$scope])) && $passes < self::MAX_SYNC_PASSES);

            if ($reenteredPasses === self::MAX_SYNC_PASSES) {
                // Another pass would broadcast again: stop rather than wedge the worker.
                $this->log('warning', "Broadcast re-entrancy limit reached for scope \"{$scope}\": a view it renders broadcasts it again on every pass, directly, through another scope or from a coroutine it starts, so its fan-out stopped after " . self::MAX_SYNC_PASSES . ' passes');
            } elseif (isset($this->syncReentered[$scope])) {
                // Not a loop, so owed like a broadcast from outside.
                $this->syncPending[$scope] = true;
            }
        } finally {
            // Owed after the last pass: the next flush renders it, and this caller returns.
            if (isset($this->syncPending[$scope])) {
                $this->oweFlush($scope);
            }
            unset($this->syncInFlight[$scope], $this->syncPending[$scope], $this->syncReentered[$scope], $this->syncFailures[$scope]);
            $this->readEpochs->end($joinedEpoch);

            if ($traceStarted) {
                $tracer->endTrace();
            }

            if (isset($this->dirtyScopes[$scope])) {
                // Marked mid-pass, when invalidating would have split the frame, or handed on above.
                $this->invalidateForBroadcast($scope);
                $this->scheduleFlush();
            }
        }
    }

    /**
     * Whether the calling coroutine runs $pass, or is one that coroutine started during the pass,
     * as a view broadcasting from a coroutine of its own does.
     *
     * @param array{cid: int, lastCid: int, since: int, warned: bool} $pass
     */
    private function isOwnPass(array $pass): bool {
        $cid = Coroutine::getCid();

        return $cid === $pass['cid'] || ($cid > $pass['lastCid'] && Coroutine::getPcid() === $pass['cid']);
    }

    /**
     * Leave a frame a running fan-out of $scope still owes to the next flush, which renders the scope again.
     * Shutdown drops it: no flush runs any more, and clients reconnect for fresh state.
     */
    private function oweFlush(string $scope): void {
        if ($this->shuttingDown) {
            return;
        }

        $this->dirtyScopes[$scope] ??= 0;
        $this->scopeMarks[$scope] = $this->readEpochs->next();
    }

    /**
     * @param null|array<int, int> $rendered          see syncLocally()
     * @param int                  $skipRenderedAfter skip the contexts in $rendered rendered under a later epoch
     */
    private function doSyncLocally(string $scope, ?array &$rendered = null, int $skipRenderedAfter = PHP_INT_MAX): void {
        $this->invalidateForBroadcast($scope);

        // Handle GLOBAL scope - sync all contexts
        if ($scope === Scope::GLOBAL) {
            $this->syncContexts($this->contexts, null, $scope, $rendered, $skipRenderedAfter);
            $this->requestLogger->logBroadcast($scope, \count($this->contexts));

            return;
        }

        // Handle ROUTE scope - sync all contexts on this route
        if (Scope::isRouteBased($scope)) {
            $parts = Scope::parse($scope);
            $route = $parts[1] ?? null;

            // If no specific route provided, broadcast to all routes
            if ($route === null) {
                $this->syncContexts($this->contexts, null, $scope, $rendered, $skipRenderedAfter);
                $this->requestLogger->logBroadcast($scope, \count($this->contexts));
            } else {
                $count = $this->syncContexts($this->contexts, $route, $scope, $rendered, $skipRenderedAfter);
                $this->requestLogger->logBroadcast($scope, $count);
            }

            return;
        }

        // Handle custom scopes (with wildcard support)
        $matchedContexts = $this->scopeRegistry->getContextsByScopePattern($scope);
        $this->syncContexts($matchedContexts, null, $scope, $rendered, $skipRenderedAfter);

        $this->requestLogger->logBroadcast($scope, \count($matchedContexts));
    }

    /**
     * Drop the cached views a broadcast of this scope makes stale.
     */
    private function invalidateForBroadcast(string $scope): void {
        if (Scope::isRouteBased($scope) && (Scope::parse($scope)[1] ?? null) === null) {
            // Bare "route" reaches every route. Cache keys carry an :initial or :update
            // suffix, so strip it to invalidate each route scope once.
            $seenScopes = [];
            foreach ($this->viewCache->getKeys() as $cacheKey) {
                $baseScope = (string) preg_replace('/:(?:initial|update)$/', '', $cacheKey);

                if (Scope::isRouteBased($baseScope) && !isset($seenScopes[$baseScope])) {
                    $this->invalidateViewCache($baseScope);
                    $seenScopes[$baseScope] = true;
                }
            }

            return;
        }

        // A route broadcast uses the full "route:/path" key, the context's primary scope.
        $this->invalidateViewCache($scope);
    }

    /**
     * Whether a broadcast from the current code is marked for the next flush instead of run now.
     */
    private function shouldCoalesce(): bool {
        return $this->config->isBroadcastCoalescingEnabled() && !$this->shuttingDown && Coroutine::getCid() > 0;
    }

    /**
     * Broker and pipe receive path: fan a broadcast from another worker or node out locally.
     */
    private function receiveBroadcast(string $scope): void {
        // pipeMessage runs in a coroutine on OpenSwoole 26, but a started worker can schedule without one.
        if ($this->config->isBroadcastCoalescingEnabled() && !$this->shuttingDown && ($this->workerStarted || Coroutine::getCid() > 0)) {
            $this->markDirty($scope, publish: false);
            $this->scheduleFlush();

            return;
        }

        $this->syncLocally($scope);
    }

    /**
     * Mark a scope for a flush. The caller schedules it.
     */
    private function markDirty(string $scope, bool $publish): void {
        $cid = Coroutine::getCid();
        $pass = $this->syncInFlight[$scope] ?? null;

        if ($pass !== null && $this->isOwnPass($pass)) {
            // A view broadcasting the scope its own pass renders: re-run the pass, at most MAX_SYNC_PASSES times.
            $coalesced = isset($this->syncReentered[$scope]);
            $this->syncReentered[$scope] = true;
        } else {
            $hops = isset($this->runningFlushes[$cid]) ? $this->runningFlushes[$cid] + 1 : 0;

            if ($hops >= self::MAX_BROADCAST_HOPS) {
                $this->log('warning', "Broadcast chain limit reached for scope \"{$scope}\": check for views that broadcast each other's scopes");

                return;
            }

            if ($pass === null) {
                // Eagerly, so a sync() or an SSE initial render before the flush is not served stale HTML.
                $this->invalidateForBroadcast($scope);
            } else {
                // Not mid-pass, which would split its frame: runFlush() invalidates once the pass ends.
                $this->warnIfSlowPass($scope, $pass);
            }

            $coalesced = isset($this->dirtyScopes[$scope]);
            $this->dirtyScopes[$scope] = max($this->dirtyScopes[$scope] ?? 0, $hops);
        }

        $this->scopeMarks[$scope] = $this->readEpochs->next();
        $this->stats->trackBroadcastScheduled($coalesced);

        if ($publish) {
            $this->unpublishedScopes[$scope] = true;
        }
    }

    /**
     * @param array{cid: int, lastCid: int, since: int, warned: bool} $pass
     */
    private function warnIfSlowPass(string $scope, array $pass): void {
        $runningMs = (hrtime(true) - $pass['since']) / 1e6;
        if ($pass['warned'] || $runningMs < self::FLUSH_WAIT_MS) {
            return;
        }

        $this->syncInFlight[$scope]['warned'] = true;
        $this->log('warning', \sprintf('The fan-out of scope "%s" has been running for %.1f s, and its next frame waits for it: check its views for slow or blocked I/O', $scope, $runningMs / 1000));
    }

    /**
     * Remember a scope this coroutine broadcast, for its flushBroadcasts().
     */
    private function rememberCallerMark(string $scope): void {
        $context = Coroutine::getContext();
        if ($context === null) {
            return;
        }

        $key = $this->callerMarksKey();
        $marks = $context[$key] ?? [];
        $marks[$scope] = true;
        $context[$key] = $marks;
    }

    /**
     * @return array<string, true> the scopes this coroutine broadcast since its last flushBroadcasts()
     */
    private function takeCallerMarks(): array {
        $context = Coroutine::getContext();
        $key = $this->callerMarksKey();
        if ($context === null || !isset($context[$key])) {
            return [];
        }

        /** @var array<string, true> $marks */
        $marks = $context[$key];
        unset($context[$key]);

        return $marks;
    }

    private function callerMarksKey(): string {
        return self::class . '#' . spl_object_id($this) . ':broadcasts';
    }

    /**
     * Schedule the next flush: one broadcast tick after the worker's last flush started and half a
     * tick after it ended, or at the end of this event-loop turn when both have passed.
     */
    private function scheduleFlush(): void {
        // A scope whose fan-out is running is scheduled when it ends; shutdown drops what is left.
        if ($this->shuttingDown || !$this->hasFlushWork()) {
            return;
        }

        // Timer::clearAll() (workerExit, test fixtures) drops the tick timer without telling anyone.
        if ($this->flushScheduled && ($this->flushTimerId === null || Timer::exists($this->flushTimerId))) {
            return;
        }

        $generation = ++$this->flushGeneration;
        $this->flushScheduled = true;
        $this->flushTimerId = null;
        $callback = fn () => $this->runScheduledFlush($generation);

        $waitMs = $this->msUntilNextTick();
        if ($waitMs > 0) {
            $id = Timer::after($waitMs, $callback);
            if (\is_int($id)) {
                $this->flushTimerId = $id;

                return;
            }
        }

        if (!Event::defer($callback)) {
            $this->flushScheduled = false;
            $this->log('error', 'Could not schedule the broadcast flush; running it now');
            $this->runTickFlush();
        }
    }

    private function hasFlushWork(): bool {
        return ($this->unpublishedScopes !== [] && !$this->publishing) || array_diff_key($this->dirtyScopes, $this->syncInFlight) !== [];
    }

    /**
     * 0 when a flush may start now, else the wait in ms (at least 1: Timer::after(0) fails).
     */
    private function msUntilNextTick(): int {
        $tickNs = $this->config->getBroadcastTickMs() * 1_000_000;
        if ($tickNs === 0 || $this->lastFlushStartNs === null) {
            return 0;
        }

        $now = hrtime(true);
        $remainingNs = $tickNs - ($now - $this->lastFlushStartNs);
        if ($this->lastFlushEndNs !== null) {
            // Flushes longer than the tick would otherwise run back to back and starve the worker's other coroutines.
            $remainingNs = max($remainingNs, intdiv($tickNs, 2) - ($now - $this->lastFlushEndNs));
        }

        return $remainingNs > 0 ? max(1, (int) ceil($remainingNs / 1_000_000)) : 0;
    }

    private function cancelScheduledFlush(): void {
        if ($this->flushTimerId !== null) {
            Timer::clear($this->flushTimerId);
            $this->flushTimerId = null;
        }
        $this->flushScheduled = false;
        ++$this->flushGeneration;
    }

    private function runScheduledFlush(int $generation): void {
        if ($generation !== $this->flushGeneration) {
            return;
        }

        if (Coroutine::getCid() <= 0) {
            // Renders push to Channels, which need a coroutine.
            if (Coroutine::create(fn () => $this->runScheduledFlush($generation)) === false) {
                $this->flushScheduled = false;
                $this->flushTimerId = null;
                $this->log('error', 'Could not start a coroutine for the broadcast flush');
            }

            return;
        }

        $this->flushScheduled = false;
        $this->flushTimerId = null;

        // Timers can fire early: never start more than 1 ms before the tick.
        if ($this->msUntilNextTick() > 1) {
            $this->scheduleFlush();

            return;
        }

        $this->runTickFlush();
    }

    /**
     * Run one flush on the dirty scopes no other flush is rendering, and record it in the stats.
     */
    private function runTickFlush(bool $inlinePublish = false): void {
        $cid = Coroutine::getCid();
        $batch = array_diff_key($this->dirtyScopes, $this->syncInFlight);
        if (isset($this->runningFlushes[$cid]) || ($batch === [] && ($this->unpublishedScopes === [] || $this->publishing))) {
            return;
        }

        $this->dirtyScopes = array_diff_key($this->dirtyScopes, $batch);
        $this->runningFlushes[$cid] = 0;
        $startNs = $this->lastFlushStartNs = hrtime(true);

        try {
            $this->runFlush($batch, $inlinePublish);
        } catch (\Throwable $e) {
            // An exception escaping a deferred callback ends the worker.
            $this->log('error', 'Broadcast flush failed: ' . Logger::describe($e));
        } finally {
            unset($this->runningFlushes[$cid]);
            $this->lastFlushEndNs = hrtime(true);
            $this->stats->trackBroadcastFlush(($this->lastFlushEndNs - $startNs) / 1e6, $this->config->getBroadcastTickMs());
            $this->scheduleFlush();
        }
    }

    /**
     * Publish the pending scopes, then fan out each scope of the batch once, rendering each
     * context at most once across the batch.
     *
     * @param array<string, int> $batch scope => hops
     */
    private function runFlush(array $batch, bool $inlinePublish): void {
        // Other workers hear about it before the local fan-out starts.
        if ($this->unpublishedScopes !== [] && !$this->publishing) {
            if ($inlinePublish || Coroutine::getCid() <= 0 || Coroutine::create(fn () => $this->publishPending()) === false) {
                $this->publishPending();
            }
        }

        // All of them first: a context reached through one scope must not render another
        // dirty scope's view from the cache, since the dedupe skips it when that scope's turn comes.
        foreach ($batch as $scope => $_) {
            if (!isset($this->syncInFlight[$scope])) {
                $this->invalidateForBroadcast($scope);
            }
        }

        $cid = Coroutine::getCid();

        /** @var array<int, int> $rendered */
        $rendered = [];

        // One read epoch for the whole flush: each scoped signal is read once for all its scopes.
        // syncLocally() and syncContexts() catch up with writes that land meanwhile.
        $joinedEpoch = $this->readEpochs->begin();

        try {
            foreach ($batch as $scope => $hops) {
                if (isset($this->syncInFlight[$scope])) {
                    // Marked again and started by another flush after this batch was taken, so that pass covers it.
                    continue;
                }

                // A broadcast from one of this scope's views is one hop further down the chain.
                $this->runningFlushes[$cid] = $hops;

                try {
                    $this->syncLocally($scope, $rendered);
                } catch (\Throwable $e) {
                    $this->log('error', "Broadcast of {$scope} failed: " . Logger::describe($e));
                }
            }
        } finally {
            $this->readEpochs->end($joinedEpoch);
        }
    }

    /**
     * Publish every pending scope. Only one coroutine per worker publishes at a time, so a broker
     * connection is never shared by two coroutines.
     */
    private function publishPending(): void {
        if ($this->publishing) {
            return;
        }
        $this->publishing = true;

        try {
            while ($this->unpublishedScopes !== []) {
                $scopes = $this->unpublishedScopes;
                $this->unpublishedScopes = [];

                foreach ($scopes as $scope => $_) {
                    try {
                        $this->broker->publish($scope);
                    } catch (\Throwable $e) {
                        $this->log('error', "Broker publish of {$scope} failed: " . Logger::describe($e));
                    }
                }
            }
        } finally {
            $this->publishing = false;
        }
    }

    /**
     * Shutdown: no deferred flush runs any more, and the owed publishes go out.
     *
     * @param bool     $renderPending       render the pending frames in a coroutine of their own, so a view waiting on I/O cannot hold up the stop
     * @param null|int $publisherDeadlineNs hrtime(true) up to which to let a running publisher finish first
     */
    private function drainBroadcasts(bool $renderPending, ?int $publisherDeadlineNs = null): void {
        $this->cancelScheduledFlush();

        if ($renderPending && Coroutine::getCid() > 0) {
            // Views that do not yield are done when this returns, before the channels close.
            Coroutine::create(fn () => $this->runTickFlush());
        }

        // Left over, such as a scope another flush is still rendering: clients reconnect for fresh state.
        $this->dirtyScopes = [];

        if ($publisherDeadlineNs !== null) {
            $this->waitWhile(fn (): bool => $this->publishing, $publisherDeadlineNs);
        }

        // Still publishing past the deadline: that publisher sends the rest itself.
        $this->publishPending();
    }

    /**
     * Inside a coroutine, wait while $condition holds, up to $deadlineNs (hrtime) or FLUSH_WAIT_MS.
     *
     * @param \Closure(): bool $condition
     */
    private function waitWhile(\Closure $condition, ?int $deadlineNs = null): void {
        if (Coroutine::getCid() <= 0) {
            return;
        }

        $deadlineNs ??= hrtime(true) + self::FLUSH_WAIT_MS * 1_000_000;
        while ($condition() && hrtime(true) < $deadlineNs) {
            Coroutine::usleep(1000);
        }
    }

    /**
     * SwooleBroker receive path: apply a scope broadcast published by another worker.
     */
    private function handlePipeMessage(int $srcWorkerId, string $data): void {
        $msg = json_decode($data, true);

        if (!\is_array($msg) || !isset($msg['scope'], $msg['nodeId'])) {
            return;
        }

        // Filter own messages (redundant guard: SwooleBroker skips self in publish)
        if ($msg['nodeId'] === $this->broker->getNodeId()) {
            return;
        }

        if (!Scope::isValidWireScope($msg['scope'])) {
            $this->log('warning', "SwooleBroker: rejected invalid scope \"{$msg['scope']}\" from worker {$srcWorkerId}");

            return;
        }

        $this->receiveBroadcast($msg['scope']);
    }

    /**
     * Register signal handlers in a worker process.
     *
     * No SIGTERM: OpenSwoole owns it and the stop runs through workerExit / workerStop.
     */
    private function registerSignalHandlers(): void {
        if ($this->signalsRegistered) {
            return;
        }
        $this->signalsRegistered = true;

        // Ctrl-C reaches the whole process group; the master gets it too and drives the stop.
        $this->registerSignal(SIGINT, function (): void {
            $this->log('debug', 'Worker received SIGINT, leaving the shutdown to the master');
        });

        $this->registerSignal(SIGHUP, function (): void {
            $this->log('info', 'Received SIGHUP signal, ignoring');
        });
    }

    private function registerSignal(int $signo, callable $handler): void {
        if (@Process::signal($signo, $handler) === false) {
            $this->log('warning', "Could not register a handler for signal {$signo}");
        }
    }

    /**
     * Stop this worker's own work: intervals, SSE loops, shutdown callbacks, broker.
     *
     * Idempotent, because workerExit fires repeatedly and workerStop follows it.
     */
    private function runWorkerShutdown(): void {
        if ($this->shutdownStarted) {
            return;
        }
        $this->shutdownStarted = true;
        // From here broadcast() renders and publishes synchronously.
        $this->shuttingDown = true;
        $stopStartNs = hrtime(true);

        foreach ($this->serverIntervalIds as $id) {
            Timer::clear($id);
        }
        $this->serverIntervalIds = [];

        // The frames waiting for the tick go out before the channels close; workerExit clears the tick's timer.
        $this->drainBroadcasts(renderPending: true);

        // Wakes SSE loops parked in getPatch() so they leave through their normal exit path.
        foreach ($this->contexts as $context) {
            $context->getPatchManager()->closePatchChannel();
        }
        $this->sseHandler->closeStreams();
        $this->waitForSseStreams();

        foreach ($this->shutdownCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $this->log('error', 'Error in shutdown callback: ' . $e->getMessage());
            }
        }

        // Presence broadcasts from onClientDisconnect and onShutdown may still sit with a running publisher.
        $stopBudgetNs = max(0, (int) ($this->server?->setting['max_wait_time'] ?? 3) - 1) * 1_000_000_000;
        $this->drainBroadcasts(renderPending: false, publisherDeadlineNs: min(hrtime(true) + self::FLUSH_WAIT_MS * 1_000_000, $stopStartNs + $stopBudgetNs));

        try {
            $this->broker->disconnect();
        } catch (\Throwable $e) {
            $this->log('error', 'Broker disconnect failed during shutdown: ' . $e->getMessage());
        }

        $others = (int) (Coroutine::stats()['coroutine_num'] ?? 0) - (Coroutine::getCid() > 0 ? 1 : 0);
        if ($others > 0) {
            $this->log('warning', "{$others} coroutine(s) still running after shutdown; the worker waits for them up to max_wait_time, then is killed");
        }
    }

    /**
     * Let the SSE exit paths (onClientDisconnect included) finish before the callbacks and the
     * broker go away, within half the stop budget so the callbacks keep the rest.
     */
    private function waitForSseStreams(): void {
        if (Coroutine::getCid() <= 0) {
            return;
        }

        $maxWait = (int) ($this->server?->setting['max_wait_time'] ?? 3);
        $deadline = microtime(true) + max(0, $maxWait - 1) / 2;
        while ($this->runningSseStreams > 0 && microtime(true) < $deadline) {
            Coroutine::usleep(10_000);
        }
    }

    /**
     * Sync each of $contexts for a fan-out, so a view that throws cannot stop the others from getting the frame.
     * Failures are collected per signature and logged once per pass by logSyncFailures().
     *
     * @param array<Context>       $contexts
     * @param null|string          $route    skip the contexts on other routes
     * @param null|array<int, int> $rendered see syncLocally()
     *
     * @return int how many contexts it reached
     */
    private function syncContexts(array $contexts, ?string $route, string $scope, ?array &$rendered, int $skipRenderedAfter): int {
        $epochs = $this->readEpochs;
        $renewals = -1;
        $epoch = 0;
        $count = 0;

        foreach ($contexts as $context) {
            if ($route !== null && $context->getRoute() !== $route) {
                continue;
            }
            ++$count;

            // Only a renewal moves the epoch this coroutine holds, so it is looked up once per pass and after each renewal.
            if ($epochs->renewals !== $renewals) {
                $renewals = $epochs->renewals;
                $epoch = $epochs->current();
            }

            if ($rendered !== null) {
                $objectId = spl_object_id($context);
                if (($rendered[$objectId] ?? 0) > $skipRenderedAfter) {
                    continue;
                }
                $rendered[$objectId] = $epoch;
            }

            try {
                if ($context->syncFanOut($epoch)) {
                    continue;
                }
            } catch (\Throwable $e) {
                $this->collectSyncFailure($scope, $e, $context);

                continue;
            }

            $this->resyncOvertaken($context, $scope, $rendered);
        }

        return $count;
    }

    /**
     * Two fan-outs can render one context at the same time when a view waits on I/O. When the one
     * whose read epoch began later queues its frame first, this renders the context again under a
     * new epoch, so the client does not end on the older frame.
     *
     * @param null|array<int, int> $rendered see syncLocally()
     */
    private function resyncOvertaken(Context $context, string $scope, ?array &$rendered): void {
        for ($attempt = 2;; ++$attempt) {
            $this->readEpochs->renew();
            $epoch = $this->readEpochs->current();
            if ($rendered !== null) {
                $rendered[spl_object_id($context)] = $epoch;
            }

            try {
                if ($context->syncFanOut($epoch)) {
                    return;
                }
            } catch (\Throwable $e) {
                $this->collectSyncFailure($scope, $e, $context);

                return;
            }

            if ($attempt >= self::MAX_SYNC_PASSES) {
                $this->log('warning', "Newer fan-outs finished context {$context->getId()} first {$attempt} times while its view waited, so the next flush of \"{$scope}\" renders it again: check the view for slow I/O", $context);
                $this->oweFlush($scope);

                return;
            }
        }
    }

    private function collectSyncFailure(string $scope, \Throwable $e, Context $context): void {
        $key = $e::class . '@' . $e->getFile() . ':' . $e->getLine();
        if (isset($this->syncFailures[$scope][$key])) {
            ++$this->syncFailures[$scope][$key][2];
        } else {
            $this->syncFailures[$scope][$key] = [$e, $context, 1];
        }
    }

    private function logSyncFailures(string $scope): void {
        foreach ($this->syncFailures[$scope] ?? [] as [$e, $context, $count]) {
            $this->log('error', "Sync failed during broadcast of {$scope} for {$count} context(s): " . Logger::describe($e), $context);
        }
        $this->syncFailures[$scope] = [];
    }

    /**
     * Invalidate view cache for a scope (called on broadcast).
     */
    private function invalidateViewCache(string $scope): void {
        $this->viewCache->invalidate($scope);
    }
}
