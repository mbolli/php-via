<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\ActionRegistry;
use Mbolli\PhpVia\State\ScopeRegistry;
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

    /** @var array<string, Context> */
    private array $contexts = [];

    /** @var array<string, int> Cleanup timer IDs for contexts */
    private array $cleanupTimers = [];

    /** @var array<string, array{id: string, identicon: string, connected_at: int, ip: string}> Client info by context ID */
    private array $clients = [];

    /** @var array<string, mixed> Global state shared across all routes and clients */
    private array $globalState = [];

    /** @var array<string, array<string, mixed>> Per-session key-value storage (sessionId => key => value) */
    private array $sessionData = [];

    /** @var array<string, int> Last-access Unix timestamp per session (used for LRU eviction) */
    private array $sessionLastAccess = [];

    /** @var array<string, string> Session ID by context ID (contextId => sessionId) */
    private array $contextSessions = [];

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
    }

    /**
     * Unregister a context.
     */
    public function unregisterContext(string $contextId): void {
        if (isset($this->contexts[$contextId])) {
            $context = $this->contexts[$contextId];

            // Remove from scope registry
            $emptyScopes = $this->scopeRegistry->unregisterContextFromAllScopes($context);

            // Clean up signals and actions for empty scopes
            foreach ($emptyScopes as $scope) {
                $hadSignals = $this->signalManager->clearScope($scope);
                $hadActions = $this->actionRegistry->clearScope($scope);

                if ($hadSignals || $hadActions) {
                    $this->logger->log('debug', "Cleaned up empty scope with signals/actions: {$scope}");
                } else {
                    $this->logger->log('debug', "Cleaned up empty scope: {$scope}");
                }
            }

            unset($this->contexts[$contextId], $this->clients[$contextId], $this->cleanupTimers[$contextId]);
        }
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
        $this->clients[$contextId] = $clientInfo;
    }

    /**
     * Unregister a client.
     */
    public function unregisterClient(string $contextId): void {
        unset($this->clients[$contextId]);
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
     * Get global state value.
     */
    public function getGlobalState(string $key, mixed $default = null): mixed {
        return $this->globalState[$key] ?? $default;
    }

    /**
     * Set global state value.
     */
    public function setGlobalState(string $key, mixed $value): void {
        $this->globalState[$key] = $value;
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
     * It is shared across all browser tabs that belong to the same session.
     *
     * @param string $sessionId Session cookie ID
     * @param string $key       Data key
     * @param mixed  $default   Value returned if key is not set
     */
    public function getSessionData(string $sessionId, string $key, mixed $default = null): mixed {
        $this->sessionLastAccess[$sessionId] = time();

        return $this->sessionData[$sessionId][$key] ?? $default;
    }

    /**
     * Set a per-session data value.
     */
    public function setSessionData(string $sessionId, string $key, mixed $value): void {
        $this->sessionLastAccess[$sessionId] = time();
        $this->sessionData[$sessionId][$key] = $value;
        $this->evictOldestSessionsIfNeeded();
    }

    /**
     * Clear one key or all data for a session.
     *
     * @param string      $sessionId Session cookie ID
     * @param null|string $key       Key to remove, or null to clear the entire session bucket
     */
    public function clearSessionData(string $sessionId, ?string $key = null): void {
        if ($key === null) {
            unset($this->sessionData[$sessionId], $this->sessionLastAccess[$sessionId]);
        } else {
            unset($this->sessionData[$sessionId][$key]);
        }
    }

    /**
     * Schedule context cleanup after a delay.
     * Allows time for reconnection or navigation between pages.
     */
    public function scheduleContextCleanup(string $contextId, int $delayMs = 5000): void {
        // Cancel any existing cleanup timer
        if (isset($this->cleanupTimers[$contextId])) {
            Timer::clear($this->cleanupTimers[$contextId]);
            unset($this->cleanupTimers[$contextId]);
        }

        // Schedule cleanup after delay
        $timerId = Timer::after($delayMs, function () use ($contextId): void {
            if (isset($this->contexts[$contextId])) {
                $this->logger->log('debug', "Cleaning up inactive context: {$contextId}");
                $context = $this->contexts[$contextId];
                $context->cleanup();
                $this->unregisterContext($contextId);
            }
        });

        $this->cleanupTimers[$contextId] = $timerId;
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
            'cache' => false,
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
