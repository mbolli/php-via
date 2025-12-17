<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

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

    /** @var array<string> Explicit scopes for this context (can have multiple) */
    private array $scopes = [];

    /** @var array<string, string> Path parameters extracted from route */
    private array $routeParams = [];

    /** @var null|string Session ID for this context */
    private ?string $sessionId = null;

    public function __construct(string $id, string $route, Via $app, ?string $namespace = null, ?string $sessionId = null) {
        $this->id = $id;
        $this->route = $route;
        $this->app = $app;
        $this->namespace = $namespace;
        $this->sessionId = $sessionId;
        $this->patchChannel = new Channel(5);

        // Default scope is TAB (per-context isolation)
        $this->scopes = [Scope::TAB];
    }

    /**
     * Get session ID for this context.
     */
    public function getSessionId(): ?string {
        return $this->sessionId;
    }

    /**
     * Get context ID.
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Get a path parameter value by name.
     *
     * Returns the value from the page request URL for the given parameter name,
     * or an empty string if not found.
     *
     * Example:
     *   $v->page('/users/{user_id}', function(Context $c) {
     *       $userId = $c->getPathParam('user_id');
     *       // ...
     *   });
     *
     * @param string $name Parameter name
     *
     * @return string Parameter value or empty string
     */
    public function getPathParam(string $name): string {
        return $this->routeParams[$name] ?? '';
    }

    /**
     * Inject route parameters into the context.
     *
     * @internal Called by Via during route matching
     *
     * @param array<string, string> $params
     */
    public function injectRouteParams(array $params): void {
        $this->routeParams = $params;
    }

    /**
     * Register a callback to be executed when the context is cleaned up (SSE disconnect).
     */
    public function onCleanup(callable $callback): void {
        $this->cleanupCallbacks[] = $callback;
    }

    /**
     * Register a callback to be executed when the user disconnects.
     *
     * This is an alias for onCleanup() with clearer semantics.
     * The callback is executed when:
     * - SSE connection closes and remains closed for 60 seconds
     * - Browser sends explicit session close beacon
     *
     * @param callable(Context): void $callback Function to call on disconnect
     */
    public function onDisconnect(callable $callback): void {
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
                $callback($this);
            } catch (\Throwable $e) {
                error_log('Cleanup callback error: ' . $e->getMessage());
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
     * Set the scope(s) for this context.
     *
     * Replaces any previously set scopes. To add additional scopes, use addScope().
     *
     * @param string $scope Built-in scope (Scope::TAB, etc.) or custom (e.g., "room:lobby")
     */
    public function scope(string $scope): void {
        // Auto-expand ROUTE to include the actual route path
        if ($scope === Scope::ROUTE) {
            $scope = Scope::routeScope($this->route);
        }

        $this->scopes = [$scope];
        $this->app->registerContextInScope($this, $scope);
        $this->app->log('debug', "Scope set to: {$scope}", $this);
    }

    /**
     * Add an additional scope to this context (multi-scope support).
     *
     * Allows a context to belong to multiple scopes simultaneously.
     * Example: A user in a chat room can have both "user:123" and "room:lobby" scopes.
     *
     * @param string $scope Additional scope to add
     */
    public function addScope(string $scope): void {
        if (!\in_array($scope, $this->scopes, true)) {
            $this->scopes[] = $scope;
            $this->app->registerContextInScope($this, $scope);
            $this->app->log('debug', "Added scope: {$scope}", $this);
        }
    }

    /**
     * Get all scopes for this context.
     *
     * @return array<string>
     */
    public function getScopes(): array {
        return $this->scopes;
    }

    /**
     * Get the primary scope (first scope) for this context.
     */
    public function getPrimaryScope(): string {
        return $this->scopes[0] ?? Scope::TAB;
    }

    /**
     * Check if this context has a specific scope.
     */
    public function hasScope(string $scope): bool {
        return \in_array($scope, $this->scopes, true);
    }

    /**
     * Broadcast updates to all contexts with the same primary scope.
     */
    public function broadcast(): void {
        $this->app->broadcast($this->getPrimaryScope());
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
        $scope = $this->getPrimaryScope();

        // Check if this scope supports caching (non-TAB scopes)
        $shouldCache = $scope !== Scope::TAB;

        if ($shouldCache) {
            // Try to get cached view
            $cached = $this->app->getViewCache($scope);
            if ($cached !== null && !$isUpdate) {
                $this->app->log('debug', "Using cached view for scope: {$scope}");

                return $cached;
            }

            $this->app->log('debug', "Rendering view for scope: {$scope} (cache miss or update)", $this);

            $startTime = microtime(true);
            $result = ($this->viewFn)($isUpdate);
            $duration = microtime(true) - $startTime;
            $this->app->trackRender($duration);

            $this->app->setViewCache($scope, $result);
            $this->isInitialRender = false;

            return $result;
        }

        // TAB SCOPE: Render per context, no caching
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
     * @param mixed       $initialValue Initial value of the signal
     * @param null|string $name         Optional human-readable name
     */
    /**
     * Create a signal.
     *
     * @param mixed       $initialValue  The initial value of the signal
     * @param null|string $name          Optional signal name (defaults to 'signal')
     * @param null|string $scope         Optional scope for shared signal (null = TAB scope, no sharing)
     * @param bool        $autoBroadcast Auto-broadcast changes for scoped signals (default: true)
     *
     * TAB scope (scope=null): Signal is private to this context, not shared
     * ROUTE/SESSION/GLOBAL scope: Signal is shared across all contexts in the same scope
     * Custom scope: Signal is shared across all contexts with that scope (e.g., "room:lobby")
     */
    public function signal(mixed $initialValue, ?string $name = null, ?string $scope = null, bool $autoBroadcast = true): Signal {
        $baseName = $name ?? 'signal';

        // Resolve SESSION scope to actual session ID
        if ($scope === Scope::SESSION) {
            if ($this->sessionId === null) {
                throw new \RuntimeException('Cannot use SESSION scope without session ID');
            }
            $scope = 'session:' . $this->sessionId;
        }

        // For scoped signals, use scope + name as ID (no context ID needed - they're shared)
        // For TAB signals, use context ID to make them unique per context
        if ($scope !== null) {
            // Scoped signal: shared across contexts in this scope
            $signalId = $scope . ':' . $baseName;
            $signalId = preg_replace('/[^a-zA-Z0-9_:]/', '_', $signalId);

            // Check if signal already exists in this scope
            $existingSignal = $this->app->getScopedSignal($scope, $signalId);
            if ($existingSignal !== null) {
                // Return existing signal (last-write-wins if value differs)
                error_log("SIGNAL: Found existing signal {$signalId} in scope {$scope}, value: " . $existingSignal->getValue());

                return $existingSignal;
            }

            // Create new scoped signal with Via reference for auto-broadcast
            $signal = new Signal($signalId, $initialValue, $scope, $autoBroadcast, $this->app);
            error_log("SIGNAL: Created new signal {$signalId} in scope {$scope}, initial value: {$initialValue}");

            // Register in Via's scoped signals
            $this->app->registerScopedSignal($scope, $signal);

            return $signal;
        }

        // TAB scope: context-specific signal, not shared
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
     * Get all signals available to this context.
     *
     * Returns both TAB-scoped signals (context-specific) and scoped signals
     * (shared with other contexts in the same scopes).
     *
     * @return array<string, Signal>
     */
    public function getSignals(): array {
        $signals = $this->signals; // TAB-scoped signals

        // Add scoped signals from all scopes this context belongs to
        foreach ($this->getScopes() as $scope) {
            $scopedSignals = $this->app->getScopedSignals($scope);
            foreach ($scopedSignals as $signalId => $signal) {
                $signals[$signalId] = $signal;
            }
        }

        return $signals;
    }

    /**
     * Create an action trigger.
     *
     * Actions can be TAB-scoped (per-context) or shared across a scope.
     * If the context has a non-TAB scope, the action is registered as a scoped action
     * and shared with all contexts in the same scope.
     *
     * @param callable    $fn    The action function to execute
     * @param null|string $name  Optional human-readable name
     * @param null|string $scope Optional explicit scope (defaults to context's primary scope)
     */
    public function action(callable $fn, ?string $name = null, ?string $scope = null): Action {
        // Use explicit scope if provided, otherwise use context's primary scope
        $actionScope = $scope ?? $this->getPrimaryScope();

        // Auto-expand ROUTE to include the actual route path
        if ($actionScope === Scope::ROUTE) {
            $actionScope = Scope::routeScope($this->route);
        }

        // For scoped actions, use deterministic ID (name only) so cached views work
        // For TAB scope, use random ID to ensure uniqueness per context
        if ($actionScope !== Scope::TAB) {
            if ($name === null) {
                throw new \InvalidArgumentException('Action name is required for scoped actions (non-TAB scope)');
            }
            $actionId = $name; // Use name directly for deterministic ID

            // Check if action already exists in this scope
            $existingAction = $this->app->getScopedAction($actionScope, $actionId);
            if ($existingAction !== null) {
                // Action already registered in this scope, reuse it
                $this->app->log('debug', "[{$this->getId()}] Reusing existing action {$actionId} in scope {$actionScope}", $this);

                return new Action($actionId);
            }

            // Register as scoped action
            $this->app->log('debug', "[{$this->getId()}] Registering new action {$actionId} in scope {$actionScope}", $this);

            $this->app->registerScopedAction($actionScope, $actionId, $fn);
        } else {
            // TAB scope: generate unique random ID
            $actionId = $this->generateId($name ?? 'action');
            $this->actionRegistry[$actionId] = $fn;
        }

        return new Action($actionId);
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
        // First check TAB-scoped actions (context-specific)
        if (isset($this->actionRegistry[$actionId])) {
            $action = $this->actionRegistry[$actionId];
            $action($this);

            return;
        }

        // Then check scoped actions in this context's scopes
        $scopes = $this->getScopes();
        $this->app->log('debug', "Checking scoped actions for {$actionId} in scopes: " . implode(', ', $scopes), $this);
        foreach ($scopes as $scope) {
            $scopedAction = $this->app->getScopedAction($scope, $actionId);
            if ($scopedAction !== null) {
                $this->app->log('debug', "Found scoped action {$actionId} in scope {$scope}", $this);
                $scopedAction($this);

                return;
            }
            $allScopedActions = $this->app->getScopedActions($scope);
            $this->app->log('debug', "Scoped actions in {$scope}: " . implode(', ', array_keys($allScopedActions)), $this);
        }

        // Check broader scopes (ROUTE and GLOBAL) if not found in context's scopes
        $routeScope = Scope::routeScope($this->route);
        if (!\in_array($routeScope, $scopes, true)) {
            $scopedAction = $this->app->getScopedAction($routeScope, $actionId);
            if ($scopedAction !== null) {
                $this->app->log('debug', "Found scoped action {$actionId} in ROUTE scope {$routeScope}", $this);
                $scopedAction($this);

                return;
            }
        }

        // Check GLOBAL scope if not already in context's scopes
        if (!\in_array(Scope::GLOBAL, $scopes, true)) {
            $scopedAction = $this->app->getScopedAction(Scope::GLOBAL, $actionId);
            if ($scopedAction !== null) {
                $this->app->log('debug', "Found scoped action {$actionId} in GLOBAL scope", $this);
                $scopedAction($this);

                return;
            }
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
            // First check TAB-scoped signals (context-specific)
            if (isset($this->signals[$signalId])) {
                $this->signals[$signalId]->setValue($value, false);

                continue;
            }

            // Then check scoped signals (shared across contexts in each scope)
            foreach ($this->getScopes() as $scope) {
                $scopedSignals = $this->app->getScopedSignals($scope);
                if (isset($scopedSignals[$signalId])) {
                    $scopedSignals[$signalId]->setValue($value, false);

                    break; // Found and updated, stop searching
                }
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

        // Also sync scoped signals for all scopes this context belongs to
        $this->syncScopedSignals();
    }

    /**
     * Sync scoped signals for all scopes this context belongs to.
     */
    private function syncScopedSignals(): void {
        $flat = [];

        foreach ($this->getScopes() as $scope) {
            // Skip TAB scope - already handled by prepareSignalsForPatch
            if ($scope === Scope::TAB) {
                continue;
            }

            $scopedSignals = $this->app->getScopedSignals($scope);
            foreach ($scopedSignals as $id => $signal) {
                // Always sync scoped signals during broadcast (don't check hasChanged)
                // because multiple contexts need to receive the same value
                $flat[$id] = $signal->getValue();
            }
        }

        if (!empty($flat)) {
            $this->getPatchChannel()->push([
                'type' => 'signals',
                'content' => $this->flatToNested($flat),
            ]);
        }
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
