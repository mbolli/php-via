<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;

/**
 * Context represents a living bridge between PHP and the browser.
 *
 * It holds runtime state, defines actions, manages reactive signals, and defines UI through View.
 */
class Context {
    private string $id;
    private string $route;
    private Via $app;

    /** @var null|callable(bool): string */
    private $viewFn;
    private bool $isInitialRender = true;

    /** @var array<string, self> */
    private array $componentRegistry = [];
    private ?Context $parentPageContext = null;
    private Channel $patchChannel;

    /** @var array<string, callable> */
    private array $actionRegistry = [];

    /** @var array<string, Signal> */
    private array $signals = [];
    private ?string $namespace = null;

    /** @var array<callable> */
    private array $cleanupCallbacks = [];

    /** @var array<int> Timer IDs created by this context */
    private array $timerIds = [];

    /** @var bool Track if context uses any context-level signals (Tab scope indicator) */
    private bool $usesContextSignals = false;

    /** @var bool Track if context uses route-level actions (Route scope indicator) */
    private bool $usesRouteActions = false;

    /** @var bool Track if context uses global-level actions (Global scope indicator) */
    private bool $usesGlobalActions = false;

    /** @var ?Scope Cached scope detection result */
    private ?Scope $detectedScope = null;

    public function __construct(string $id, string $route, Via $app, ?string $namespace = null) {
        $this->id = $id;
        $this->route = $route;
        $this->app = $app;
        $this->namespace = $namespace;
        $this->patchChannel = new Channel(5);
    }

    /**
     * Get context ID.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Register a callback to be executed when the context is cleaned up (SSE disconnect).
     */
    public function onCleanup(callable $callback): void {
        $this->cleanupCallbacks[] = $callback;
    }

    /**
     * Create a timer that will be automatically cleaned up with the context.
     *
     * @param callable $callback The function to call on each tick
     * @param int      $ms       Interval in milliseconds
     *
     * @return int Timer ID
     */
    public function setInterval(callable $callback, int $ms): int {
        $timerId = Timer::tick($ms, $callback);
        $this->timerIds[] = $timerId;

        return $timerId;
    }

    /**
     * Execute cleanup callbacks and release resources.
     *
     * @internal Called by Via when context is destroyed
     */
    public function cleanup(): void {
        // Clear all timers first
        foreach ($this->timerIds as $timerId) {
            Timer::clear($timerId);
        }
        $this->timerIds = [];

        foreach ($this->cleanupCallbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                // Silently ignore cleanup errors
            }
        }

        // Close and cleanup the patch channel
        $this->patchChannel->close();

        // Clear references to prevent memory leaks
        $this->cleanupCallbacks = [];
        $this->signals = [];
        $this->actionRegistry = [];
        $this->componentRegistry = [];
        $this->viewFn = null;
    }

    public function getRoute(): string {
        return $this->route;
    }

    /**
     * Get all component contexts registered with this context.
     *
     * @internal Used by Via for scope detection
     *
     * @return array<string, Context>
     */
    public function getComponentRegistry(): array {
        return $this->componentRegistry;
    }

    /**
     * Detect the scope of this context based on usage patterns.
     *
     * @internal Used by Via for caching decisions
     *
     * Global scope: Uses ONLY global actions, no signals or route actions
     * Route scope: Uses ONLY route actions, no context signals or global actions
     * Tab scope: Uses context signals OR mixes scopes (default, safest)
     *
     * @return Scope The detected scope for this context
     */
    public function getScope(): Scope {
        // Return cached result if already detected
        if ($this->detectedScope !== null) {
            return $this->detectedScope;
        }

        // Global scope: ONLY global actions, NO route actions, NO context signals
        // This means all state is shared across ALL routes and users
        if ($this->usesGlobalActions && !$this->usesRouteActions && !$this->usesContextSignals) {
            $this->detectedScope = Scope::GLOBAL;
            $this->app->log('debug', 'Scope detected: GLOBAL (uses global actions only)', $this);

            return Scope::GLOBAL;
        }

        // Route scope: ONLY route actions, NO global actions, NO context signals
        // This means all state is shared across users on same route
        if ($this->usesRouteActions && !$this->usesGlobalActions && !$this->usesContextSignals) {
            $this->detectedScope = Scope::ROUTE;
            $this->app->log('debug', 'Scope detected: ROUTE (uses route actions, no signals)', $this);

            return Scope::ROUTE;
        }

        // Default to Tab scope (safest)
        // This means each user gets their own state, or scopes are mixed
        $this->detectedScope = Scope::TAB;
        $this->app->log('debug', 'Scope detected: TAB (uses signals, mixed scopes, or default)', $this);

        return Scope::TAB;
    }

    /**
     * Define the UI rendered by this context.
     *
     * @param callable(bool): string|string $view  Function that returns HTML content, or Twig template name
     * @param array<string, mixed>          $data  Optional data for Twig templates
     * @param null|string                   $block Optional block name to render only that block during updates
     */
    public function view(callable|string $view, array $data = [], ?string $block = null): void {
        if (\is_string($view)) {
            // Twig template name
            $this->viewFn = fn () => $this->render($view, $data, $block);
        } elseif (\is_callable($view)) {
            // Callable function - don't wrap, let the callable handle its own structure
            $this->viewFn = $view;
        } else {
            throw new \RuntimeException('View must be a template name or callable');
        }
    }

    /**
     * Render a Twig template with context data.
     *
     * @param array<string, mixed> $data  Data to pass to the template
     * @param null|string          $block Optional block name to render only that block
     */
    public function render(string $template, array $data = [], ?string $block = null): string {
        $data += [
            'contextId' => $this->id,
            'via_is_update' => !$this->isInitialRender,
        ];

        if ($block !== null) {
            // Render only the specified block
            $twig = $this->app->getTwig();
            $twigTemplate = $twig->load($template);

            return $twigTemplate->renderBlock($block, $data);
        }

        return $this->app->getTwig()->render($template, $data);
    }

    /**
     * Render a Twig template from string.
     *
     * @param array<string, mixed> $data Data to pass to the template
     */
    public function renderString(string $template, array $data = []): string {
        $twig = $this->app->getTwig();

        return $twig->createTemplate($template)->render($data);
    }

    /**
     * Render the view with automatic scope-based caching.
     *
     * @internal Called by Via during SSE updates and initial page render
     *
     * Scope detection:
     * - Route scope: Render once, cache, share with all clients
     * - Tab scope: Render per context, no caching
     */
    public function renderView(): string {
        if ($this->viewFn === null) {
            throw new \RuntimeException('View not defined');
        }

        $isUpdate = !$this->isInitialRender;
        $scope = $this->getScope();

        // GLOBAL SCOPE: Cache view and share across ALL routes and clients
        if ($scope === Scope::GLOBAL) {
            return $this->renderCachedView(
                cacheKey: '__global__',
                getCache: fn (): ?string => $this->app->getGlobalViewCache(),
                setCache: function (string $html): void {
                    $this->app->setGlobalViewCache($html);
                },
                logPrefix: 'GLOBAL-scoped view',
                isUpdate: $isUpdate
            );
        }

        // ROUTE SCOPE: Cache view and share across all clients on same route
        if ($scope === Scope::ROUTE) {
            return $this->renderCachedView(
                cacheKey: $this->route,
                getCache: fn (): ?string => $this->app->getCachedView($this->route),
                setCache: function (string $html): void {
                    $this->app->cacheView($this->route, $html);
                },
                logPrefix: "ROUTE-scoped view for {$this->route}",
                isUpdate: $isUpdate,
                withLock: true
            );
        }

        // TAB SCOPE: Render per context, no caching
        // Each user gets their own unique view
        $this->app->log('debug', "Rendering TAB-scoped view for {$this->route} (no cache)", $this);

        $startTime = microtime(true);
        $result = ($this->viewFn)($isUpdate);
        $duration = microtime(true) - $startTime;
        $this->app->trackRender($duration);

        $this->isInitialRender = false;

        return $result;
    }

    /**
     * Create a reactive signal.
     *
     * @param mixed $initialValue Initial value for the signal
     */
    /**
     * Create a reactive signal.
     *
     * @param mixed       $initialValue Initial value of the signal
     * @param null|string $name         Optional human-readable name
     */
    public function signal(mixed $initialValue, ?string $name = null): Signal {
        // Mark that this context uses context-level signals (Tab scope indicator)
        $this->usesContextSignals = true;

        $baseName = $name ?? 'signal';
        // clean base name to be alphanumeric and underscores only
        // Use context ID to make signal IDs predictable and queryable
        // Format: basename_contextId for easier readability
        $signalId = $this->namespace
            ? $this->namespace . '.' . $baseName
            : $baseName . '_' . $this->id;
        $signalId = preg_replace('/[^a-zA-Z0-9_]/', '_', $signalId);
        $signal = new Signal($signalId, $initialValue);

        // Components register signals on parent page
        if ($this->isComponent()) {
            $this->parentPageContext->signals[$signalId] = $signal;
        } else {
            $this->signals[$signalId] = $signal;
        }

        return $signal;
    }

    /**
     * Get a signal by name.
     *
     * @param string $name Signal name (without namespace prefix)
     *
     * @return null|Signal The signal if found, null otherwise
     */
    public function getSignal(string $name): ?Signal {
        // With context ID-based signal IDs, we can construct the expected ID
        $signalId = $this->namespace
            ? $this->namespace . '.' . $name
            : $name . '_' . $this->id;
        $signalId = preg_replace('/[^a-zA-Z0-9_]/', '_', $signalId);

        return $this->signals[$signalId] ?? null;
    }

    /**
     * Create an action trigger.
     *
     * @param callable    $fn   The action function to execute
     * @param null|string $name Optional human-readable name
     */
    public function action(callable $fn, ?string $name = null): Action {
        $actionId = $this->generateId($name ?? 'action');
        $this->actionRegistry[$actionId] = $fn;

        return new Action($actionId);
    }

    /**
     * Register a route-level action (shared across all contexts on this route).
     * Use this for actions that should work the same for all users on the same page.
     *
     * @param callable $fn   The action handler function (receives Context as first param)
     * @param string   $name Action name (must be consistent across contexts)
     */
    public function routeAction(callable $fn, string $name): Action {
        // Mark that this context uses route-level actions (Route scope indicator)
        $this->usesRouteActions = true;

        $this->app->registerRouteAction($this->route, $name, $fn);

        return new Action($name);
    }

    /**
     * Register a global-scoped action that can be called from any route.
     * Use this for actions that affect state shared across ALL routes.
     *
     * @param callable $fn   The action handler function (receives Context as first param)
     * @param string   $name Action name (must be globally unique)
     */
    public function globalAction(callable $fn, string $name): Action {
        // Mark that this context uses global-level actions (Global scope indicator)
        $this->usesGlobalActions = true;

        // Register as route action but with a special global prefix
        $this->app->registerRouteAction('__global__', $name, $fn);

        return new Action($name);
    }

    /**
     * Execute a function periodically.
     *
     * @param int      $milliseconds Interval in milliseconds
     * @param callable $fn           The function to execute
     *
     * @return int Timer ID that can be used to clear the timer
     */
    public function interval(int $milliseconds, callable $fn): int {
        return Timer::tick($milliseconds, function () use ($fn): void {
            $fn();
        });
    }

    /**
     * Execute a registered action.
     *
     * @internal Called by Via when handling action requests
     */
    public function executeAction(string $actionId): void {
        // First check this context
        if (isset($this->actionRegistry[$actionId])) {
            $action = $this->actionRegistry[$actionId];
            $action();

            return;
        }

        // Check component contexts
        foreach ($this->componentRegistry as $component) {
            if ($component->hasAction($actionId)) {
                $component->executeAction($actionId);

                return;
            }
        }

        throw new \RuntimeException("Action not found: {$actionId}");
    }

    /**
     * Create a component (sub-context).
     *
     * @param callable    $fn        Component initialization function
     * @param null|string $namespace Optional namespace for component signals
     *
     * @return callable Returns a function that renders the component
     */
    public function component(callable $fn, ?string $namespace = null): callable {
        $componentId = $this->id . '/_component/' . $this->generateId();
        $componentNamespace = $namespace ?? 'c' . mb_substr(md5($componentId), 0, 8);
        $componentContext = new self($componentId, $this->route, $this->app, $componentNamespace);

        if ($this->isComponent()) {
            $componentContext->parentPageContext = $this->parentPageContext;
        } else {
            $componentContext->parentPageContext = $this;
        }

        $fn($componentContext);

        $this->componentRegistry[$componentId] = $componentContext;

        return function () use ($componentContext) {
            $html = $componentContext->renderView();
            // Create valid CSS ID by replacing slashes and prefixing with 'c-'
            $cssId = 'c-' . str_replace(['/', '_'], '-', $componentContext->getId());

            return '<div id="' . $cssId . '">' . $html . '</div>';
        };
    }

    /**
     * Sync current view and signals to the browser.
     */
    public function sync(): void {
        $channel = $this->getPatchChannel();

        // If channel is full, drop oldest patches to make room for new updates
        // This prevents user interactions from being lost when client is slow
        while ($channel->isFull()) {
            $dropped = $channel->pop(0);
            if ($dropped !== false) {
                $this->app->log('debug', "Dropped old patch for context {$this->id} - channel full");
            } else {
                break;
            }
        }

        // Sync view with proper selector for components
        $viewHtml = $this->renderView();

        if ($this->isComponent()) {
            // Create valid CSS ID by replacing slashes and prefixing with 'c-'
            $cssId = 'c-' . str_replace(['/', '_'], '-', $this->id);
            $wrappedHtml = '<div id="' . $cssId . '">' . $viewHtml . '</div>';
            $this->getPatchChannel()->push([
                'type' => 'elements',
                'content' => $wrappedHtml,
                'selector' => '#' . $cssId,
            ]);
        } else {
            // For pages, update entire content
            $this->getPatchChannel()->push([
                'type' => 'elements',
                'content' => $viewHtml,
            ]);
        }

        // Sync signals
        $this->syncSignals();
    }

    /**
     * Execute JavaScript on the client.
     */
    public function execScript(string $script): void {
        if (empty($script)) {
            return;
        }

        $this->patchChannel->push([
            'type' => 'script',
            'content' => $script,
        ]);
    }

    /**
     * Inject signals from the client.
     *
     * @internal Called by Via when processing requests
     *
     * @param array<int|string, mixed> $signalsData Nested structure of signals from the client
     */
    public function injectSignals(array $signalsData): void {
        // Convert nested structure back to flat
        $flat = $this->nestedToFlat($signalsData);

        foreach ($flat as $signalId => $value) {
            if (isset($this->signals[$signalId])) {
                $this->signals[$signalId]->setValue($value, false);
            }
        }
    }

    /**
     * Get next patch from the queue.
     *
     * @internal Called by Via during SSE event streaming
     *
     * @return null|array<string, mixed> Next patch data or null if none available
     */
    public function getPatch(): ?array {
        if ($this->patchChannel->isEmpty()) {
            return null;
        }

        return $this->patchChannel->pop(0.01);
    }

    public function getNamespace(): ?string {
        return $this->namespace;
    }

    /**
     * Sync only signals to the browser.
     * Useful when you only need to update signal values without re-rendering.
     */
    public function syncSignals(): void {
        $updatedSignals = $this->prepareSignalsForPatch();

        if (!empty($updatedSignals)) {
            $this->getPatchChannel()->push([
                'type' => 'signals',
                'content' => $updatedSignals,
            ]);
        }
    }

    /**
     * Render view with caching support.
     *
     * @param string   $cacheKey  Cache identifier
     * @param callable $getCache  Function to retrieve cached view
     * @param callable $setCache  Function to store cached view
     * @param string   $logPrefix Prefix for log messages
     * @param bool     $isUpdate  Whether this is an update render
     * @param bool     $withLock  Whether to use render lock (for ROUTE scope)
     */
    private function renderCachedView(
        string $cacheKey,
        callable $getCache,
        callable $setCache,
        string $logPrefix,
        bool $isUpdate,
        bool $withLock = false
    ): string {
        // Check cache first
        $cached = $getCache();
        if ($cached !== null) {
            $this->app->log('debug', "Using cached {$logPrefix}", $this);
            $this->isInitialRender = false;

            return $cached;
        }

        // Handle concurrent render locking (for ROUTE scope)
        if ($withLock) {
            if ($this->app->isRendering($cacheKey)) {
                Coroutine::sleep(0.001);
                $cached = $getCache();
                if ($cached !== null) {
                    $this->app->log('debug', "Got cached view after wait: {$logPrefix}", $this);
                    $this->isInitialRender = false;

                    return $cached;
                }
            }
            $this->app->setRendering($cacheKey, true);
        }

        $this->app->log('debug', "Rendering {$logPrefix} (will cache)", $this);

        // Render and track time
        $startTime = microtime(true);
        $result = ($this->viewFn)($isUpdate);
        $duration = microtime(true) - $startTime;
        $this->app->trackRender($duration);

        $this->isInitialRender = false;
        $setCache($result);
        $this->app->log('debug', "Cached {$logPrefix}", $this);

        if ($withLock) {
            $this->app->setRendering($cacheKey, false);
        }

        return $result;
    }

    /**
     * Prepare signals for patching.
     *
     * @return array<string, mixed> Nested structure of changed signals
     */
    private function prepareSignalsForPatch(): array {
        // Components should use parent's signals
        $signalsToCheck = $this->isComponent()
            ? $this->parentPageContext->signals
            : $this->signals;

        $flat = [];

        foreach ($signalsToCheck as $id => $signal) {
            if ($signal->hasChanged()) {
                $flat[$id] = $signal->getValue();
                $signal->markSynced();
            }
        }

        // Convert flat structure to nested object for namespaced signals
        return $this->flatToNested($flat);
    }

    /**
     * Check if this context has a specific action.
     */
    private function hasAction(string $actionId): bool {
        return isset($this->actionRegistry[$actionId]);
    }

    /**
     * Convert nested signal structure to flat
     * e.g., {"counter1": {"count": 0}} => {"counter1.count": 0}.
     *
     * @param array<int|string, mixed> $nested
     *
     * @return array<string, mixed>
     */
    private function nestedToFlat(array $nested, string $prefix = ''): array {
        $flat = [];

        foreach ($nested as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;

            if (\is_array($value) && !$this->isAssocArray($value)) {
                // It's a regular array value, not an object
                $flat[$fullKey] = $value;
            } elseif (\is_array($value)) {
                // It's an object/nested structure - recurse
                $flat = array_merge($flat, $this->nestedToFlat($value, $fullKey));
            } else {
                // It's a scalar value
                $flat[$fullKey] = $value;
            }
        }

        return $flat;
    }

    /**
     * Check if array is associative (object-like).
     *
     * @param array<int|string, mixed> $arr
     */
    private function isAssocArray(array $arr): bool {
        if (empty($arr)) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
    }

    /**
     * Convert flat signal structure to nested object
     * e.g., {"counter1.count": 0} => {"counter1": {"count": 0}}.
     *
     * @param array<string, mixed> $flat
     *
     * @return array<string, mixed>
     */
    private function flatToNested(array $flat): array {
        $nested = [];

        foreach ($flat as $key => $value) {
            if (mb_strpos($key, '.') !== false) {
                // Namespaced signal - convert to nested structure
                $parts = explode('.', $key);
                $current = &$nested;

                foreach ($parts as $i => $part) {
                    if ($i === \count($parts) - 1) {
                        // Last part - set the value
                        $current[$part] = $value;
                    } else {
                        // Intermediate part - ensure object exists
                        if (!isset($current[$part]) || !\is_array($current[$part])) {
                            $current[$part] = [];
                        }
                        $current = &$current[$part];
                    }
                }
            } else {
                // Non-namespaced signal - keep flat
                $nested[$key] = $value;
            }
        }

        return $nested;
    }

    /**
     * Check if this is a component context.
     */
    private function isComponent(): bool {
        return $this->parentPageContext !== null;
    }

    /**
     * Generate random ID.
     */
    /**
     * Generate a unique ID with optional human-readable prefix.
     *
     * @param null|string $prefix Optional prefix for the ID
     */
    private function generateId(?string $prefix = null): string {
        $random = bin2hex(random_bytes(4)); // Shortened to 8 chars

        return $prefix ? "{$prefix}-{$random}" : $random;
    }

    /**
     * Get the patch channel (for components, use parent's channel).
     */
    private function getPatchChannel(): Channel {
        if ($this->isComponent()) {
            return $this->parentPageContext->patchChannel;
        }

        return $this->patchChannel;
    }
}
