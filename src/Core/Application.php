<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\ActionRegistry;
use Mbolli\PhpVia\State\ScopeRegistry;
use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\State\SharedContextDirectory;
use Mbolli\PhpVia\State\SharedSessionStore;
use Mbolli\PhpVia\State\SharedTable;
use Mbolli\PhpVia\State\SignalManager;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\Stats;
use OpenSwoole\Timer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Application - Core application state management.
 *
 * Manages:
 * - Context registry
 * - Client tracking
 * - Global state
 * - Twig environment
 * - Context lifecycle (cleanup, timers)
 */
class Application {
    /**
     * Maximum number of distinct session buckets kept in memory.
     * When this limit is reached the least-recently-used sessions are evicted.
     */
    private const int MAX_SESSIONS = 10_000;

    /**
     * Maximum number of revival records kept in memory. Each is a few strings for a
     * recently-destroyed context; the soonest-expiring are evicted past this cap.
     */
    private const int MAX_REVIVABLE = 10_000;

    /** @var array<string, Context> */
    private array $contexts = [];

    /** @var array<string, int> Cleanup timer IDs for contexts */
    private array $cleanupTimers = [];

    /** @var array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}> Client info by context ID */
    private array $clients = [];

    /** @var array<string, mixed> Global state shared across all routes and clients */
    private array $globalState = [];

    /**
     * Shared-memory table for global state (used when worker_num > 1).
     * Null until injected by Via after server creation.
     */
    private ?SharedTable $sharedTable = null;

    /** Cross-worker context directory; null when running single-worker. */
    private ?SharedContextDirectory $contextDirectory = null;

    /** Cross-worker SSE client registry; null when running single-worker. */
    private ?SharedClientRegistry $clientRegistry = null;

    /** Cross-worker session data; null when running single-worker. */
    private ?SharedSessionStore $sessionStore = null;

    /** @var array<string, array<string, mixed>> Per-session key-value storage (sessionId => key => value) */
    private array $sessionData = [];

    /** @var array<string, int> Last-access Unix timestamp per session (used for LRU eviction) */
    private array $sessionLastAccess = [];

    /** @var array<string, string> Session ID by context ID (contextId => sessionId) */
    private array $contextSessions = [];

    /**
     * Revival records for recently-destroyed contexts, keyed by context ID. Lets a returning
     * tab whose context was cleaned up rebuild an equivalent one (same ID → same signal IDs)
     * instead of hard-reloading. Populated at cleanup time only, so this holds recently-gone
     * contexts, not live ones.
     *
     * @var array<string, array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int}>
     */
    private array $revivableContexts = [];

    /** Context directory writes that found the table full, and when that was last logged. */
    private int $directoryWriteFailures = 0;

    private int $directoryWarnedAt = 0;

    /** When this worker last swept expired context directory records (hrtime ns). */
    private int $directoryPrunedAtNs = 0;

    private Environment $twig;

    public function __construct(
        private Config $config,
        private Logger $logger,
        private Stats $stats,
        private ScopeRegistry $scopeRegistry,
        private SignalManager $signalManager,
        private ActionRegistry $actionRegistry,
    ) {
        $this->initializeTwig();
    }

    /**
     * Apply configuration changes (called when config is updated).
     */
    public function applyConfig(): void {
        if ($this->config->getTemplateDir()) {
            $loader = new FilesystemLoader($this->config->getTemplateDir());
            $loader->addPath(\dirname(__DIR__, 2), 'via');
            $this->twig->setLoader($loader);
        }
    }

    /**
     * Get Twig environment.
     */
    public function getTwig(): Environment {
        return $this->twig;
    }

    /**
     * Get configuration.
     */
    public function getConfig(): Config {
        return $this->config;
    }

    /**
     * Register a context.
     */
    public function registerContext(Context $context): void {
        $this->contexts[$context->getId()] = $context;

        // Publish how to rebuild it, so an action landing on any other worker can. Written at
        // creation rather than destruction: a context alive on another worker right now has no
        // revival record, which is exactly the case that returned HTTP 400.
        $this->publishContextRecord($context, $this->config->getContextDirectoryTtlSeconds());
    }

    /**
     * Unregister a context.
     */
    public function unregisterContext(string $contextId): void {
        if (isset($this->contexts[$contextId])) {
            $this->releaseScopes($this->contexts[$contextId]);

            unset($this->contexts[$contextId], $this->clients[$contextId], $this->cleanupTimers[$contextId], $this->contextSessions[$contextId]);
        }
    }

    /**
     * Destroy a context that never reached the client, registered or not: no revival record,
     * no directory entry, no timers.
     *
     * @internal called when the page handler or the initial render throws
     */
    public function discardContext(Context $context): void {
        $contextId = $context->getId();
        $context->cleanup();
        $this->releaseScopes($context);
        $this->cancelContextCleanup($contextId);
        $this->forgetRevivable($contextId);
        unset($this->contexts[$contextId], $this->clients[$contextId], $this->contextSessions[$contextId]);
    }

    /**
     * Get a context by ID.
     */
    public function getContext(string $contextId): ?Context {
        return $this->contexts[$contextId] ?? null;
    }

    /**
     * Get all contexts.
     *
     * @return array<string, Context>
     */
    public function getAllContexts(): array {
        return $this->contexts;
    }

    /**
     * Get contexts on a specific route.
     *
     * @return array<Context>
     */
    public function getContextsOnRoute(string $route): array {
        $filtered = [];
        foreach ($this->contexts as $context) {
            if ($context->getRoute() === $route) {
                $filtered[] = $context;
            }
        }

        return $filtered;
    }

    /**
     * Register a client.
     *
     * @param string                                                              $contextId  Context ID
     * @param array{id: string, identicon: string, connected_at: int, ip: string} $clientInfo Client information
     */
    public function registerClient(string $contextId, array $clientInfo): void {
        $this->clients[$contextId] = $clientInfo + ['context_id' => $contextId];

        // The identicon is not published: it is derived from the ID, so every worker can
        // regenerate it rather than store 1.5 KB of SVG per client.
        $registered = $this->clientRegistry?->register(
            $contextId,
            $clientInfo['id'],
            $clientInfo['ip'],
            $clientInfo['connected_at']
        );

        if ($registered === false) {
            $this->logger->log('warning', "Client registry is full, so getClients() leaves out {$contextId}: raise Config::withContextDirectorySize()");
        }
    }

    /**
     * Unregister a client.
     */
    public function unregisterClient(string $contextId): void {
        $this->clientRegistry?->unregister($contextId);
        unset($this->clients[$contextId]);
    }

    /**
     * Get all connected clients.
     *
     * @param int $readEpoch the caller's fan-out read epoch, or 0; see SharedClientRegistry::all()
     *
     * @return array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}>
     */
    public function getClients(int $readEpoch = 0): array {
        return $this->clientRegistry?->all($readEpoch) ?? $this->clients;
    }

    /**
     * Track view render time.
     */
    public function trackRender(float $duration): void {
        $this->stats->trackRender($duration);
    }

    /**
     * Get render statistics.
     *
     * @return array{render_count: int, total_time: float, min_time: float, max_time: float, avg_time: float}
     */
    public function getRenderStats(): array {
        return $this->stats->getStats();
    }

    /**
     * Inject the SharedTable for cross-worker GlobalState storage.
     * Called by Via before $server->start() when worker_num > 1.
     */
    public function setSharedTable(SharedTable $table): void {
        $this->sharedTable = $table;
    }

    /**
     * Install the cross-worker context directory.
     *
     * @internal called from Via::start() in the master process, and by tests standing several
     *           Via instances in for several workers
     */
    public function setContextDirectory(?SharedContextDirectory $directory): void {
        $this->contextDirectory = $directory;
    }

    public function getContextDirectory(): ?SharedContextDirectory {
        return $this->contextDirectory;
    }

    /**
     * Install the cross-worker SSE client registry.
     *
     * @internal called from Via::start() in the master process
     */
    public function setClientRegistry(?SharedClientRegistry $registry): void {
        $this->clientRegistry = $registry;
    }

    /**
     * Bind the client registry to this worker, dropping the clients of the earlier process with
     * the same worker ID: one that crashed or was killed, or on a reload one still draining.
     *
     * @internal called at the start of workerStart
     */
    public function claimWorker(int $workerId): void {
        $removed = $this->clientRegistry?->claimWorker($workerId) ?? 0;

        if ($removed > 0) {
            $this->logger->log('debug', "Worker {$workerId} dropped {$removed} client(s) registered by its previous process");
        }
    }

    /**
     * Drop the clients of worker processes that no longer exist and that claimWorker() missed.
     *
     * @internal run periodically on the leader worker
     */
    public function removeDeadClients(): void {
        $removed = $this->clientRegistry?->removeDeadProcesses() ?? 0;

        if ($removed > 0) {
            $this->logger->log('info', "Removed {$removed} client(s) of worker processes that no longer run");
        }
    }

    /**
     * Install the cross-worker session data store.
     *
     * @internal called from Via::start() in the master process, and by tests standing several
     *           Via instances in for several workers
     */
    public function setSessionStore(?SharedSessionStore $store): void {
        $this->sessionStore = $store;
    }

    public function getSessionStore(): ?SharedSessionStore {
        return $this->sessionStore;
    }

    /**
     * Evict the least recently used sessions from the shared store once it is over capacity.
     *
     * @internal run periodically on the leader worker
     */
    public function evictSharedSessions(): void {
        if ($this->sessionStore === null) {
            return;
        }

        $removed = $this->sessionStore->evict();
        if ($removed > 0) {
            $this->logger->log('warning', "Session data LRU eviction: removed {$removed} least recently used sessions (cap: {$this->sessionStore->capacity()}, raise with Config::withSessionTableSize())");
        }
    }

    /**
     * Get global state value.
     */
    public function getGlobalState(string $key, mixed $default = null): mixed {
        if ($this->sharedTable !== null) {
            return $this->sharedTable->get($key, $default);
        }

        return $this->globalState[$key] ?? $default;
    }

    /**
     * Set global state value.
     */
    public function setGlobalState(string $key, mixed $value): void {
        if ($this->sharedTable !== null) {
            $this->sharedTable->set($key, $value);

            return;
        }

        $this->globalState[$key] = $value;
    }

    /**
     * Add to an integer global-state value atomically, returning the new value.
     *
     * `setGlobalState($k, getGlobalState($k) + 1)` is a read and a write with a gap in between:
     * with more than one worker, two of them read the same value and each write back the same
     * result, silently losing one. This routes to an atomic shared-memory increment instead.
     *
     * A key nothing has written yet starts at zero, so the first increment(1) returns 1.
     * Single-worker behaviour is identical to the equivalent setGlobalState().
     *
     * @throws \LogicException if the key is currently holding a non-integer
     */
    public function incrementGlobalState(string $key, int $by = 1): int {
        if ($this->sharedTable !== null) {
            return $this->sharedTable->increment($key, $by);
        }

        $current = $this->globalState[$key] ?? 0;
        if (!\is_int($current)) {
            throw new \LogicException(
                "GlobalState key \"{$key}\" does not hold an integer, so it cannot be incremented."
            );
        }

        return $this->globalState[$key] = $current + $by;
    }

    /**
     * Read, transform and write a global-state value as one indivisible step.
     *
     * The supported way to do read-modify-write on a NON-integer global-state value: appending
     * to a list, updating one key of a map. incrementGlobalState() covers the numeric case, and
     * a wholesale assignment needs nothing.
     *
     * The callback runs on this worker while a lock keeps other workers out, so keep it fast and
     * free of side effects. The mutator receives null for a key nothing has written yet.
     *
     * Do not mix mutateGlobalState() and incrementGlobalState() on the same key: the latter
     * deliberately skips the lock, so a mutate running beside it can write back over an
     * increment that landed in between. Pick one per key.
     *
     * @template T
     *
     * @param callable(mixed): T $mutator Receives the current value, returns the new one
     *
     * @return T the value written
     */
    public function mutateGlobalState(string $key, callable $mutator): mixed {
        if ($this->sharedTable !== null) {
            return $this->sharedTable->mutate($key, $mutator);
        }

        return $this->globalState[$key] = $mutator($this->globalState[$key] ?? null);
    }

    /**
     * Set session ID for a context.
     */
    public function setContextSession(string $contextId, string $sessionId): void {
        $this->contextSessions[$contextId] = $sessionId;
    }

    /**
     * Get session ID for a context.
     */
    public function getContextSessionId(string $contextId): ?string {
        return $this->contextSessions[$contextId] ?? null;
    }

    /**
     * Get a per-session data value.
     *
     * Session data persists for the server process lifetime (not cleared on disconnect).
     * It is shared across all browser tabs that belong to the same session, and across
     * workers when worker_num > 1.
     *
     * @param string $sessionId Session cookie ID
     * @param string $key       Data key
     * @param mixed  $default   Value returned if key is not set
     */
    public function getSessionData(string $sessionId, string $key, mixed $default = null): mixed {
        if ($this->sessionStore !== null) {
            return $this->sessionStore->get($sessionId, $key, $default);
        }

        $this->sessionLastAccess[$sessionId] = time();

        return $this->sessionData[$sessionId][$key] ?? $default;
    }

    /**
     * Set a per-session data value.
     *
     * @throws \InvalidArgumentException with worker_num > 1, if the value cannot be serialized
     * @throws \OverflowException        with worker_num > 1, if the session's serialized data would exceed
     *                                   Config::withSessionTableSize()
     * @throws \RuntimeException         with worker_num > 1, if the session's lock is not taken within
     *                                   about 7 s (a worker died holding it or its event loop is blocked)
     */
    public function setSessionData(string $sessionId, string $key, mixed $value): void {
        if ($this->sessionStore !== null) {
            $this->sessionStore->set($sessionId, $key, $value);

            return;
        }

        $this->sessionLastAccess[$sessionId] = time();
        $this->sessionData[$sessionId][$key] = $value;
        $this->evictOldestSessionsIfNeeded();
    }

    /**
     * Clear one key or all data for a session.
     *
     * @param string      $sessionId Session cookie ID
     * @param null|string $key       Key to remove, or null to clear the entire session bucket
     *
     * @throws \RuntimeException with worker_num > 1, if the session's lock is not taken within about
     *                           7 s (a worker died holding it or its event loop is blocked)
     */
    public function clearSessionData(string $sessionId, ?string $key = null): void {
        if ($this->sessionStore !== null) {
            $this->sessionStore->clear($sessionId, $key);

            return;
        }

        if ($key === null) {
            unset($this->sessionData[$sessionId], $this->sessionLastAccess[$sessionId]);
        } else {
            unset($this->sessionData[$sessionId][$key]);
        }
    }

    /**
     * Schedule context cleanup after a delay.
     * Allows time for reconnection or navigation between pages.
     *
     * @param null|int              $delayMs       Grace period in milliseconds. Null uses
     *                                             Config::getContextCleanupDelayMs().
     * @param null|callable(): bool $isActiveCheck If provided, called when the timer fires.
     *                                             Returns true if an SSE connection is active
     *                                             (the timer reschedules itself instead of destroying).
     */
    public function scheduleContextCleanup(string $contextId, ?int $delayMs = null, ?callable $isActiveCheck = null): void {
        $delayMs ??= $this->config->getContextCleanupDelayMs();

        // Cancel any existing cleanup timer
        if (isset($this->cleanupTimers[$contextId])) {
            Timer::clear($this->cleanupTimers[$contextId]);
            unset($this->cleanupTimers[$contextId]);
        }

        // Schedule cleanup after delay
        $timerId = Timer::after($delayMs, function () use ($contextId, $delayMs, $isActiveCheck): void {
            try {
                if ($isActiveCheck !== null && $isActiveCheck()) {
                    // SSE still connected: reschedule instead of destroying.
                    $this->logger->log('debug', "Context {$contextId} has active SSE, deferring cleanup");
                    $this->scheduleContextCleanup($contextId, $delayMs, $isActiveCheck);

                    return;
                }

                $this->destroyContext($contextId);
            } catch (\Throwable $e) {
                $this->logger->log('error', "Context cleanup failed for {$contextId}: " . Logger::describe($e));
            }
        });

        $this->cleanupTimers[$contextId] = $timerId;
    }

    /**
     * Capture a revival snapshot, then tear the context down. Runs when the cleanup timer fires.
     *
     * The revival record is taken *before* destruction so a returning tab can rebuild an
     * equivalent context (same ID) instead of hard-reloading.
     *
     * @internal invoked by the cleanup timer (and directly by tests, since timers don't fire under VIA_TEST_MODE)
     */
    public function destroyContext(string $contextId): void {
        $context = $this->contexts[$contextId] ?? null;
        if ($context === null) {
            return;
        }

        $this->logger->log('debug', "Cleaning up inactive context: {$contextId}");
        $this->recordRevivable($context);
        $context->cleanup();

        // Cleanup callbacks can yield, and a returning tab may have revived this ID meanwhile,
        // so only entries that still belong to this context object are dropped.
        $this->releaseScopes($context);
        if (($this->contexts[$contextId] ?? null) === $context) {
            unset($this->contexts[$contextId], $this->clients[$contextId], $this->cleanupTimers[$contextId], $this->contextSessions[$contextId]);
        }
    }

    /**
     * Cancel scheduled context cleanup.
     */
    public function cancelContextCleanup(string $contextId): void {
        if (isset($this->cleanupTimers[$contextId])) {
            Timer::clear($this->cleanupTimers[$contextId]);
            unset($this->cleanupTimers[$contextId]);
        }
    }

    /**
     * Get logger instance.
     */
    public function getLogger(): Logger {
        return $this->logger;
    }

    /**
     * Look up a revival record by context ID, or null if absent or expired.
     *
     * @return null|array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int}
     */
    public function getRevivable(string $contextId): ?array {
        if ($this->contextDirectory !== null) {
            return $this->contextDirectory->get($contextId);
        }

        $record = $this->revivableContexts[$contextId] ?? null;
        if ($record === null) {
            return null;
        }

        if ($record['expiresAt'] <= time()) {
            unset($this->revivableContexts[$contextId]);

            return null;
        }

        return $record;
    }

    /**
     * Drop a revival record once the context has been rebuilt (or is otherwise no longer revivable).
     */
    public function forgetRevivable(string $contextId): void {
        $this->contextDirectory?->forget($contextId);
        unset($this->revivableContexts[$contextId]);
    }

    /**
     * Drop only this process's revival record, leaving the shared directory entry in place.
     *
     * Used after a successful rebuild. When records were per-process and only ever described a
     * DESTROYED context, rebuilding made the record redundant and dropping it was right. The
     * shared directory entry is a different thing: it is how every OTHER worker rebuilds this
     * context, so consuming it on the first revival would send the rest back to answering
     * "400 Invalid context". registerContext() has already refreshed it with a live TTL.
     */
    public function forgetLocalRevivable(string $contextId): void {
        unset($this->revivableContexts[$contextId]);
    }

    /**
     * Rewrite a live context's directory entry with the full TTL, so it cannot expire under a connected tab.
     * Rewritten, not extended: a worker that destroyed its own copy shortened it, and it may be gone since.
     *
     * @internal called by SseHandler::heartbeatStreams() for every context with a running stream
     */
    public function refreshContextRecord(Context $context): void {
        $this->publishContextRecord($context, $this->config->getContextDirectoryTtlSeconds());
    }

    /**
     * Tear down a component context with its page: run its cleanup and leave its scopes.
     *
     * The scopes' signals and actions stay: a component is often the only registered member
     * of a shared scope (GLOBAL, a room) that pages outside the registry still read and act on.
     *
     * @internal called by Context::cleanup() for each of its components
     */
    public function releaseComponent(Context $component): void {
        $component->cleanup();
        $this->scopeRegistry->unregisterContextFromAllScopes($component);
    }

    /**
     * Remove a context from all its scopes and clear the signals and actions of scopes left empty.
     */
    private function releaseScopes(Context $context): void {
        $emptyScopes = $this->scopeRegistry->unregisterContextFromAllScopes($context);

        foreach ($emptyScopes as $scope) {
            $hadSignals = $this->signalManager->clearScope($scope);
            $hadActions = $this->actionRegistry->clearScope($scope);

            if ($hadSignals || $hadActions) {
                $this->logger->log('debug', "Cleaned up empty scope with signals/actions: {$scope}");
            } else {
                $this->logger->log('debug', "Cleaned up empty scope: {$scope}");
            }
        }
    }

    /**
     * Write a context's rebuild record, expiring $ttlSeconds from now.
     */
    private function publishContextRecord(Context $context, int $ttlSeconds): void {
        // With revival off no worker rebuilds a context, so nothing would ever read the record.
        if ($this->contextDirectory === null || $this->config->getContextRevivalWindowMs() <= 0) {
            return;
        }

        try {
            $this->contextDirectory->put($context->getId(), [
                'route' => $context->getRoute(),
                'params' => $context->getRouteParams(),
                'sessionId' => $context->getSessionId(),
                'expiresAt' => time() + $ttlSeconds,
            ]);
        } catch (\OverflowException $e) {
            // Losing the entry costs cross-worker reachability for this one context, which
            // degrades to the old 400. It must not take the page load down with it.
            ++$this->directoryWriteFailures;
            $now = time();
            if ($now - $this->directoryWarnedAt >= 10) {
                $this->directoryWarnedAt = $now;
                $this->logger->log('warn', "Context directory write failed ({$this->directoryWriteFailures} times in this worker): " . $e->getMessage());
            }
        }
    }

    /**
     * Store a revival record for a context about to be destroyed.
     *
     * No-op when the revival window is 0 (feature disabled). Called from the cleanup timer.
     */
    private function recordRevivable(Context $context): void {
        $windowMs = $this->config->getContextRevivalWindowMs();
        if ($windowMs <= 0) {
            return;
        }

        if ($this->contextDirectory !== null) {
            // Shorten the live entry to the revival window: the context is gone, and only a
            // returning tab has any use for it now.
            $this->publishContextRecord($context, (int) ceil($windowMs / 1000));
            // The sweep reads every row, so a worker runs it at most once a second.
            $now = hrtime(true);
            if ($now - $this->directoryPrunedAtNs >= 1_000_000_000) {
                $this->directoryPrunedAtNs = $now;
                $this->contextDirectory->prune();
            }

            return;
        }

        $this->revivableContexts[$context->getId()] = [
            'route' => $context->getRoute(),
            'params' => $context->getRouteParams(),
            'sessionId' => $context->getSessionId(),
            'expiresAt' => time() + (int) ceil($windowMs / 1000),
        ];

        $this->pruneRevivableIfNeeded();
    }

    /**
     * Evict expired revival records, then the soonest-expiring ones if still over the cap.
     * Called only from recordRevivable, so the overhead is paid only on cleanup.
     */
    private function pruneRevivableIfNeeded(): void {
        $now = time();
        foreach ($this->revivableContexts as $id => $record) {
            if ($record['expiresAt'] <= $now) {
                unset($this->revivableContexts[$id]);
            }
        }

        if (\count($this->revivableContexts) <= self::MAX_REVIVABLE) {
            return;
        }

        uasort($this->revivableContexts, static fn (array $a, array $b): int => $a['expiresAt'] <=> $b['expiresAt']);
        $evictCount = max(1, (int) (self::MAX_REVIVABLE * 0.01));
        $toEvict = \array_slice(array_keys($this->revivableContexts), 0, $evictCount);

        foreach ($toEvict as $id) {
            unset($this->revivableContexts[$id]);
        }

        $this->logger->log('warning', "Revival record LRU eviction: removed {$evictCount} records (cap: " . self::MAX_REVIVABLE . ')');
    }

    /**
     * Evict the least-recently-used session buckets when MAX_SESSIONS is exceeded.
     *
     * Called only from setSessionData, so the overhead is paid only on writes.
     * Evicts 1% of the cap (minimum 1) per call to avoid repeated single evictions.
     */
    private function evictOldestSessionsIfNeeded(): void {
        if (\count($this->sessionData) <= self::MAX_SESSIONS) {
            return;
        }

        asort($this->sessionLastAccess);
        $evictCount = max(1, (int) (self::MAX_SESSIONS * 0.01));
        $toEvict = \array_slice(array_keys($this->sessionLastAccess), 0, $evictCount);

        foreach ($toEvict as $sid) {
            unset($this->sessionData[$sid], $this->sessionLastAccess[$sid]);
        }

        $this->logger->log('warning', "Session data LRU eviction: removed {$evictCount} inactive sessions (cap: " . self::MAX_SESSIONS . ')');
    }

    /**
     * Initialize Twig environment with appropriate loader.
     */
    private function initializeTwig(): void {
        if ($this->config->getTemplateDir()) {
            $loader = new FilesystemLoader($this->config->getTemplateDir());
            $loader->addPath(\dirname(__DIR__, 2), 'via');
        } else {
            $loader = new ArrayLoader([]);
        }

        $this->twig = new Environment($loader, [
            'cache' => $this->config->getTwigCacheDir(),
            'auto_reload' => true,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);

        // Add global variables
        $this->twig->addGlobal('basePath', $this->config->getBasePath());

        $this->addTwigFunctions();
    }

    /**
     * Add custom Twig functions for Via.
     */
    private function addTwigFunctions(): void {
        $this->twig->addFunction(new TwigFunction(
            'bind',
            fn (Signal $signal) => new Markup($signal->bind(), 'html')
        ));

        $this->twig->addFunction(
            new TwigFunction(
                'dump',
                fn (mixed ...$vars): string => '<pre>' . htmlspecialchars(print_r($vars, true), ENT_QUOTES, 'UTF-8') . '</pre>',
                ['is_safe' => ['html']]
            ),
        );
    }
}
