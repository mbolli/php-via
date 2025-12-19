<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Mbolli\PhpVia\Context\ComponentManager;
use Mbolli\PhpVia\Context\ContextLifecycle;
use Mbolli\PhpVia\Context\PatchManager;
use Mbolli\PhpVia\Context\SignalFactory;
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

    /** @var array<string, callable> */
    private array $actionRegistry = [];

    private ?string $namespace = null;

    /** Whether to cache update renders (default true for performance) */
    private bool $cacheUpdates = true;

    /** @var array<string> Explicit scopes for this context (can have multiple) */
    private array $scopes = [];

    /** @var array<string, string> Path parameters extracted from route */
    private array $routeParams = [];

    /** @var null|string Session ID for this context */
    private ?string $sessionId = null;

    private ContextLifecycle $lifecycle;
    private SignalFactory $signalFactory;
    private ComponentManager $componentManager;
    private PatchManager $patchManager;

    public function __construct(string $id, string $route, Via $app, ?string $namespace = null, ?string $sessionId = null) {
        $this->id = $id;
        $this->route = $route;
        $this->app = $app;
        $this->namespace = $namespace;
        $this->sessionId = $sessionId;

        // Initialize managers
        $this->lifecycle = new ContextLifecycle($this);
        $this->signalFactory = new SignalFactory($this, $app);
        $this->componentManager = new ComponentManager($this, $app);
        $this->patchManager = new PatchManager($this, $app, $this->signalFactory, $this->componentManager);

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
     * Get signal factory.
     *
     * @internal Used by Context managers
     */
    public function getSignalFactory(): SignalFactory {
        return $this->signalFactory;
    }

    /**
     * Get component manager.
     *
     * @internal Used by Context managers
     */
    public function getComponentManager(): ComponentManager {
        return $this->componentManager;
    }

    /**
     * Get patch manager.
     *
     * @internal Used by Context managers
     */
    public function getPatchManager(): PatchManager {
        return $this->patchManager;
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
        $this->lifecycle->addCleanupCallback($callback);
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
        $this->lifecycle->addCleanupCallback($callback);
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
        return $this->lifecycle->registerTimer($callback, $ms);
    }

    /**
     * Execute cleanup callbacks and release resources.
     *
     * @internal Called by Via when context is destroyed
     */
    public function cleanup(): void {
        $this->lifecycle->cleanup();

        // Close patch channel
        $this->patchManager->closePatchChannel();

        // Clear references to prevent memory leaks
        $this->signalFactory->clearSignals();
        $this->actionRegistry = [];
        $this->componentManager->clearComponents();
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
        return $this->componentManager->getComponentRegistry();
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
     * @internal
     *
     * @return array<string>
     */
    public function getScopes(): array {
        return $this->scopes;
    }

    /**
     * Get the primary scope (first scope) for this context.
     *
     * @internal
     */
    public function getPrimaryScope(): string {
        return $this->scopes[0] ?? Scope::TAB;
    }

    /**
     * Check if this context has a specific scope.
     *
     * @internal
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
     * @param callable(bool): string|string $view         Function that returns HTML content, or Twig template name
     * @param array<string, mixed>          $data         Optional data for Twig templates
     * @param null|string                   $block        Optional block name to render only that block during updates
     * @param bool                          $cacheUpdates Whether to cache update renders (default true). Set to false if view returns different content on updates (e.g., empty string).
     */
    public function view(callable|string $view, array $data = [], ?string $block = null, bool $cacheUpdates = true): void {
        if (\is_string($view)) {
            // Twig template name
            $this->viewFn = fn () => $this->render($view, $data, $block);
        } elseif (\is_callable($view)) {
            // Callable function - don't wrap, let the callable handle its own structure
            $this->viewFn = $view;
        } else {
            throw new \RuntimeException('View must be a template name or callable');
        }

        $this->cacheUpdates = $cacheUpdates;
    }

    /**
     * Check if a view has been defined for this context.
     */
    public function hasView(): bool {
        return $this->viewFn !== null;
    }

    /**
     * Check if update renders should be cached.
     *
     * @internal
     */
    public function shouldCacheUpdates(): bool {
        return $this->cacheUpdates;
    }

    /**
     * Render a Twig template with context data.
     *
     * @param array<string, mixed> $data  Data to pass to the template
     * @param null|string          $block Optional block name to render only that block
     */
    public function render(string $template, array $data = [], ?string $block = null): string {
        $data += ['contextId' => $this->id];

        return $this->app->getViewRenderer()->renderTemplate($template, $data, $block);
    }

    /**
     * Render a Twig template from string.
     *
     * @param string               $template Template content
     * @param array<string, mixed> $data     Data to pass to the template
     */
    public function renderString(string $template, array $data = []): string {
        // Add context data automatically
        $data += ['contextId' => $this->id];

        return $this->app->getViewRenderer()->renderString($template, $data);
    }

    /**
     * Render the view with automatic scope-based caching.
     *
     * @internal Called by Via during SSE updates and initial page render
     *
     * @param bool $isUpdate If true, this is an SSE update render (not initial page load)
     *
     * Scope detection:
     * - Route scope: Render once, cache, share with all clients
     * - Tab scope: Render per context, no caching
     */
    public function renderView(bool $isUpdate = false): string {
        if ($this->viewFn === null) {
            throw new \RuntimeException('View not defined');
        }

        return $this->app->getViewRenderer()->renderView(
            $this->viewFn,
            $isUpdate,
            $this->getPrimaryScope(),
            $this,
            $this->route
        );
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
        return $this->signalFactory->createSignal($initialValue, $name, $scope, $autoBroadcast);
    }

    /**
     * Get a signal by name.
     *
     * @param string $name Signal name (without namespace prefix)
     *
     * @return null|Signal The signal if found, null otherwise
     */
    public function getSignal(string $name): ?Signal {
        return $this->signalFactory->getSignal($name);
    }

    /**
     * Get all signals available to this context.
     *
     * Returns both TAB-scoped signals (context-specific) and scoped signals
     * (shared with other contexts in the same scopes).
     *
     * @internal
     *
     * @return array<string, Signal>
     */
    public function getSignals(): array {
        return $this->signalFactory->getAllSignals();
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
            $actionId = $this->app->generateId();
            $this->actionRegistry[$actionId] = $fn;
        }

        return new Action($actionId);
    }

    /**
     * Execute a function periodically.
     *
     *     * @deprecated Use setInterval() instead
     *
     * @internal
     *
     *     * @param int      $milliseconds Interval in milliseconds
     * @param callable $fn The function to execute
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
        foreach ($this->componentManager->getComponents() as $component) {
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
        return $this->componentManager->createComponent($fn, $namespace);
    }

    /**
     * Sync current view and signals to the browser.
     */
    public function sync(): void {
        $this->patchManager->sync();
    }

    /**
     * Execute JavaScript on the client.
     */
    public function execScript(string $script): void {
        $this->patchManager->execScript($script);
    }

    /**
     * Inject signals from the client.
     *
     * @internal Called by Via when processing requests
     *
     * @param array<int|string, mixed> $signalsData Nested structure of signals from the client
     */
    public function injectSignals(array $signalsData): void {
        $this->signalFactory->injectSignals($signalsData);
    }

    /**
     * Get next patch from the queue.
     *
     * @internal Called by Via during SSE event streaming
     *
     * @return null|array<string, mixed> Next patch data or null if none available
     */
    public function getPatch(): ?array {
        return $this->patchManager->getPatch();
    }

    public function getNamespace(): ?string {
        return $this->namespace;
    }

    /**
     * Sync only signals to the browser.
     * Useful when you only need to update signal values without re-rendering.
     */
    public function syncSignals(): void {
        $this->patchManager->syncSignals();
    }

    /**
     * Check if this context has a specific action.
     */
    private function hasAction(string $actionId): bool {
        return isset($this->actionRegistry[$actionId]);
    }
}
