<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Mbolli\PhpVia\Composition\ClassMetadata;
use Mbolli\PhpVia\Composition\PageMount;
use Mbolli\PhpVia\Context\ComponentManager;
use Mbolli\PhpVia\Context\ContextLifecycle;
use Mbolli\PhpVia\Context\PatchManager;
use Mbolli\PhpVia\Context\SignalFactory;
use Mbolli\PhpVia\Rendering\Bootstrap;
use Mbolli\PhpVia\Rendering\Html;
use Mbolli\PhpVia\Rendering\TemplateEngine;
use Mbolli\PhpVia\Support\Removed;
use Mbolli\PhpVia\Tracing\Tracer;
use OpenSwoole\Timer;
use starfederation\datastar\enums\ElementPatchMode;

/**
 * Context represents a living bridge between PHP and the browser.
 *
 * It holds runtime state, defines actions, manages reactive signals, and defines UI through View.
 * Not designed for extension.
 */
class Context {
    private string $id;
    private string $route;
    private Via $app;

    /** @var null|callable(bool, string): string */
    private $viewFn;

    /** @var array<string, callable> */
    private array $actionRegistry = [];

    /** Monotonic counter for generating deterministic IDs for anonymous (unnamed) TAB actions. */
    private int $anonActionSeq = 0;

    /** @var array<string, true> TAB action ids already warned about as registered twice */
    private array $duplicateActionWarned = [];

    /** @var array<string, Action> Named actions keyed by user-supplied name (raw, not camelCased) */
    private array $namedActions = [];

    /** @var null|array<string, mixed> Cached result of buildAutoData(), frozen after first render */
    private ?array $autoDataCache = null;

    private ?string $namespace = null;

    /** Whether update renders are shared by every context of this view in its primary scope */
    private bool $shareRender = false;

    /** @var array<string> Explicit scopes for this context (can have multiple) */
    private array $scopes = [];

    /** @var array<string, true> the scopes addScope() joined, which scope() keeps */
    private array $joinedScopes = [];

    /** @var array<string, string> Path parameters extracted from route */
    private array $routeParams = [];

    /** @var null|string Session ID for this context */
    private ?string $sessionId = null;

    /** @var null|string Per-route shell template override */
    private ?string $shellTemplate = null;

    /** @var array<string> Per-context additions to <head> */
    private array $contextHeadIncludes = [];

    /** @var array<string> Per-context additions before </body> */
    private array $contextFootIncludes = [];

    /** @var array<string, mixed> PSR-7 request attributes set by middleware */
    private array $requestAttributes = [];

    /** @var array<string, mixed> HTTP query/post params for the current request */
    private array $requestInput = [];

    /** @var array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> Uploaded files for the current action request */
    private array $requestFiles = [];

    /** @var array<string, string> Cookies from the current request */
    private array $requestCookies = [];

    /** @var list<array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}> Cookies queued to be sent with the next response */
    private array $pendingCookies = [];

    /** Whether broadcast() has warned that it no longer reaches the scopes this TAB-primary context joined */
    private bool $tabBroadcastWarned = false;

    /** Read epoch of the newest broadcast frame queued for this context; see syncFanOut() */
    private int $fanOutEpoch = 0;

    /**
     * While an action-revived context waits for its SSE connect to seed it: every TAB signal of
     * this page and its components with its write count when the wait began. Null when not waiting.
     *
     * @var null|list<array{Signal, int}>
     */
    private ?array $seedWait = null;

    private ContextLifecycle $lifecycle;
    private SignalFactory $signalFactory;
    private ComponentManager $componentManager;
    private PatchManager $patchManager;

    /**
     * @internal pages, mount() and component() create contexts
     */
    public function __construct(string $id, string $route, Via $app, ?string $namespace = null, ?string $sessionId = null) {
        $this->id = $id;
        $this->route = $route;
        $this->app = $app;
        $this->namespace = $namespace;
        $this->sessionId = $sessionId;

        // Weak, so that the last reference to a context frees it without PHP's cycle collector.
        $self = \WeakReference::create($this);
        $this->lifecycle = new ContextLifecycle($self, $app);
        $this->signalFactory = new SignalFactory($self, $app);
        $this->componentManager = new ComponentManager($self, $app);
        $this->patchManager = new PatchManager($self, $app, $this->signalFactory, $this->componentManager);

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
     * Get a per-session data value for this context's session.
     *
     * Session data persists for the server process lifetime and is shared across
     * all browser tabs belonging to the same session, and across workers when
     * worker_num > 1. Returns $default if this context has no session or the key is not set.
     *
     * @param string $key     Data key
     * @param mixed  $default Value returned if key is not set
     */
    public function sessionData(string $key, mixed $default = null): mixed {
        if ($this->sessionId === null) {
            return $default;
        }

        return $this->app->getSessionData($this->sessionId, $key, $default);
    }

    /**
     * Set a per-session data value.
     *
     * No-op if this context has no session.
     *
     * @throws \InvalidArgumentException with worker_num > 1, if the value cannot be serialized
     * @throws \OverflowException        with worker_num > 1, if the session's serialized data would exceed
     *                                   Config::withSessionTableSize()
     * @throws \RuntimeException         with worker_num > 1, if the session's lock is not taken within
     *                                   about 7 s (a worker died holding it or its event loop is blocked)
     */
    public function setSessionData(string $key, mixed $value): void {
        if ($this->sessionId === null) {
            return;
        }

        $this->app->setSessionData($this->sessionId, $key, $value);
    }

    /**
     * Clear one key or all data from this context's session.
     *
     * @param null|string $key Key to remove, or null to clear all session data
     *
     * @throws \RuntimeException with worker_num > 1, if the session's lock is not taken within about
     *                           7 s (a worker died holding it or its event loop is blocked)
     */
    public function clearSessionData(?string $key = null): void {
        if ($this->sessionId === null) {
            return;
        }

        $this->app->clearSessionData($this->sessionId, $key);
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
     * Get Config from the application.
     */
    public function getConfig(): Config {
        return $this->app->getConfig();
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
     * Get the route parameters injected into this context.
     *
     * @internal used by the revival path to reconstruct a destroyed context
     *
     * @return array<string, string>
     */
    public function getRouteParams(): array {
        return $this->routeParams;
    }

    /**
     * Set PSR-7 request attributes from middleware.
     *
     * @internal called by RequestHandler after middleware pipeline runs
     *
     * @param array<string, mixed> $attributes
     */
    public function setRequestAttributes(array $attributes): void {
        $this->requestAttributes = $attributes;
    }

    /**
     * Get a request attribute set by middleware.
     *
     * Middleware can store data (e.g. authenticated user, locale) as PSR-7
     * request attributes via $request->withAttribute(). These are bridged
     * into the Context so page handlers can access them.
     */
    public function getRequestAttribute(string $name, mixed $default = null): mixed {
        return $this->requestOwner()->requestAttributes[$name] ?? $default;
    }

    /**
     * Get all request attributes set by middleware.
     *
     * @return array<string, mixed>
     */
    public function getRequestAttributes(): array {
        return $this->requestOwner()->requestAttributes;
    }

    /**
     * Set HTTP request input (query + post params + files) for the current action request.
     *
     * @internal called by ActionHandler before executing an action
     *
     * @param array<string, mixed>                                                                      $query GET query parameters
     * @param array<string, mixed>                                                                      $post  POST body parameters
     * @param array<string, array{name: string, type: string, tmp_name: string, error: int, size: int}> $files Uploaded files (from multipart/form-data)
     */
    public function setRequestInput(array $query, array $post, array $files = []): void {
        $this->requestInput = array_merge($query, $post);
        $this->requestFiles = $files;
    }

    /**
     * Get an HTTP request parameter from the current action request.
     *
     * Reads from merged GET + POST parameters. Use this instead of \$_GET/\$_POST
     * superglobals, which are not safe in OpenSwoole's coroutine model.
     *
     * @param string $name    Parameter name
     * @param mixed  $default Value returned if parameter is not set
     */
    public function input(string $name, mixed $default = null): mixed {
        return $this->requestOwner()->requestInput[$name] ?? $default;
    }

    /**
     * Get an uploaded file from the current action request.
     *
     * Returns the parsed file array for the named field when a file was
     * successfully uploaded via a multipart/form-data form. Returns null if
     * no file was sent, the field is missing, or the upload failed.
     *
     * Use this in action callbacks instead of \$_FILES, which is not safe in
     * OpenSwoole's coroutine model.
     *
     * @param string $name The file input field name
     *
     * @return null|array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public function file(string $name): ?array {
        $f = $this->requestOwner()->requestFiles[$name] ?? null;
        if (!\is_array($f) || $f['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        return $f;
    }

    /**
     * Set cookies from the current request.
     *
     * @internal called by RequestHandler and ActionHandler before executing the handler/action
     *
     * @param array<string, string> $cookies Raw cookie array from the OpenSwoole request
     */
    public function setRequestCookies(array $cookies): void {
        $this->requestCookies = $cookies;
    }

    /**
     * Get a cookie value from the current request.
     *
     * Returns null if the cookie is not present. Use this instead of $_COOKIE,
     * which is not safe in OpenSwoole's coroutine model.
     *
     * @param string $name Cookie name
     */
    public function cookie(string $name): ?string {
        $value = $this->requestOwner()->requestCookies[$name] ?? null;

        return $value !== null ? (string) $value : null;
    }

    /**
     * Queue a cookie to be sent with the next response.
     *
     * The cookie is applied to the HTTP response by RequestHandler (page load) or
     * ActionHandler (action response). It cannot be sent mid-SSE-stream.
     *
     * @param string $name     Cookie name
     * @param string $value    Cookie value
     * @param int    $expires  Unix timestamp when the cookie expires; 0 = session cookie
     * @param string $path     Cookie path; defaults to '/'
     * @param string $domain   Cookie domain; defaults to current host (empty string)
     * @param bool   $secure   Restrict to HTTPS; defaults to true
     * @param bool   $httpOnly Prevent JS access; defaults to true
     * @param string $sameSite SameSite policy ('Lax', 'Strict', 'None'); defaults to 'Lax'
     */
    public function setCookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): void {
        $this->requestOwner()->pendingCookies[] = compact('name', 'value', 'expires', 'path', 'domain', 'secure', 'httpOnly', 'sameSite');
    }

    /**
     * Queue a cookie deletion to be sent with the next response.
     *
     * Sets the cookie value to an empty string with an expiry in the past,
     * causing the browser to remove it.
     *
     * @param string $path Cookie path (must match the path the cookie was set with)
     */
    public function deleteCookie(string $name, string $path = '/'): void {
        $this->setCookie($name, '', expires: 1, path: $path);
    }

    /**
     * Return all pending cookies and clear the queue.
     *
     * @internal called by RequestHandler and ActionHandler to apply queued cookies
     *
     * @return list<array{name: string, value: string, expires: int, path: string, domain: string, secure: bool, httpOnly: bool, sameSite: string}>
     */
    public function flushPendingCookies(): array {
        $cookies = $this->pendingCookies;
        $this->pendingCookies = [];

        return $cookies;
    }

    /**
     * Register a callback to run when this context is destroyed, the moment its tab is gone for good:
     * - the SSE connection closed and stayed closed for the cleanup delay (Config::withContextTimeouts())
     * - the browser sent the tab-close beacon
     * - no SSE stream attached within the connect timeout
     *
     * Via::onClientDisconnect() runs earlier, when the stream closes, also for a reconnect blip.
     *
     * @param callable(Context): void $callback
     */
    public function onCleanup(callable $callback): void {
        $this->lifecycle->addCleanupCallback($callback);
    }

    /**
     * @deprecated removed in 0.14; throws and names onCleanup()
     */
    public function onDisconnect(callable $callback): never {
        Removed::method('Context::onDisconnect()', 'Use $c->onCleanup($fn): it runs at the same moment, when the context is destroyed.');
    }

    /**
     * Create a timer that will be automatically cleaned up with the context.
     * A throw from the callback is logged and the timer keeps running.
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
        $this->seedWait = null;
        $this->actionRegistry = [];
        // A component that joined a scope is registered there itself and would outlive the page.
        foreach ($this->componentManager->getComponents() as $component) {
            $this->app->getApp()->releaseComponent($component);
        }
        $this->componentManager->clearComponents();
        $this->viewFn = null;
    }

    public function getRoute(): string {
        return $this->route;
    }

    /**
     * Override the shell template for this page.
     *
     * Allows different routes to use different outer layouts
     * (e.g., marketing shell vs docs shell vs debug shell).
     *
     * @param string $path Absolute path to the shell HTML template
     */
    public function setShellTemplate(string $path): self {
        $this->shellTemplate = $path;

        return $this;
    }

    /**
     * Get the shell template override for this context, if any.
     *
     * @internal
     */
    public function getShellTemplate(): ?string {
        return $this->shellTemplate;
    }

    /**
     * Add content to the <head> section for this page only.
     *
     * Useful for per-page <title>, <meta>, and <link> tags.
     *
     * @param string ...$elements HTML elements to append
     */
    public function appendToHead(string ...$elements): self {
        foreach ($elements as $element) {
            $this->contextHeadIncludes[] = $element;
        }

        return $this;
    }

    /**
     * Add content before closing </body> for this page only.
     *
     * @param string ...$elements HTML elements to append
     */
    public function appendToFoot(string ...$elements): self {
        foreach ($elements as $element) {
            $this->contextFootIncludes[] = $element;
        }

        return $this;
    }

    /**
     * Get per-context head includes.
     *
     * @internal Used by HtmlBuilder
     *
     * @return array<string>
     */
    public function getContextHeadIncludes(): array {
        return $this->contextHeadIncludes;
    }

    /**
     * Get per-context foot includes.
     *
     * @internal Used by HtmlBuilder
     *
     * @return array<string>
     */
    public function getContextFootIncludes(): array {
        return $this->contextFootIncludes;
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
     * Set the primary scope of this context.
     *
     * Replaces the primary scope set before. The scopes joined through addScope() or a scoped signal stay.
     *
     * The primary scope is the target of broadcast() and the key of the shared update render. It is no
     * default for later declarations: actions stay per tab, and signal() needs the scope to share a signal.
     * Scope::ROUTE resolves to this route's scope and Scope::SESSION to this session's.
     *
     * @param string $scope Built-in scope (Scope::TAB, etc.) or custom (e.g., "room:lobby")
     */
    public function scope(string $scope): void {
        $scope = Scope::resolve($scope, $this, 'Context::scope()');

        $joined = array_filter($this->scopes, fn (string $s): bool => $s !== $scope && isset($this->joinedScopes[$s]));
        $this->scopes = [$scope, ...array_values($joined)];
        $this->app->registerContextInScope($this, $scope);
        $this->app->log('debug', "Scope set to: {$scope}", $this);
    }

    /**
     * Add an additional scope to this context (multi-scope support).
     *
     * Allows a context to belong to multiple scopes simultaneously.
     * Example: A user in a chat room can have both "user:123" and "room:lobby" scopes.
     * Scope::ROUTE and Scope::SESSION resolve as in scope().
     *
     * @param string $scope Additional scope to add
     */
    public function addScope(string $scope): void {
        $scope = Scope::resolve($scope, $this, 'Context::addScope()');
        if ($scope !== Scope::TAB) {
            $this->joinedScopes[$scope] = true;
        }
        if (!\in_array($scope, $this->scopes, true)) {
            $this->scopes[] = $scope;
            $this->app->registerContextInScope($this, $scope);
            $this->app->log('debug', "Added scope: {$scope}", $this);
        }
    }

    /**
     * Remove a scope from this context.
     *
     * The context will no longer receive broadcasts targeted at this scope.
     * TAB scope cannot be removed (it is always present for direct targeting).
     *
     * @param string $scope Scope to remove
     */
    public function removeScope(string $scope): void {
        $scope = Scope::resolve($scope, $this, 'Context::removeScope()');
        if ($scope === Scope::TAB) {
            return; // TAB scope is permanent: it's the per-context identity scope
        }
        unset($this->joinedScopes[$scope]);
        $key = array_search($scope, $this->scopes, true);
        if ($key !== false) {
            array_splice($this->scopes, (int) $key, 1);
            $this->app->unregisterContextInScope($this, $scope);
            $this->app->log('debug', "Removed scope: {$scope}", $this);
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
     * Broadcast updates to all contexts with the same primary scope.
     *
     * Inside a coroutine this only marks the scope for the worker's next broadcast flush; see Via::broadcast().
     * With the default primary scope, TAB, it syncs this tab only: scopes joined through addScope() are reached
     * with Via::broadcast().
     */
    public function broadcast(): void {
        $scope = $this->getPrimaryScope();
        if ($scope !== Scope::TAB) {
            $this->app->broadcast($scope);

            return;
        }

        $joined = array_values(array_diff($this->scopes, [Scope::TAB]));
        if ($joined !== [] && !$this->tabBroadcastWarned && $this->app->getSettings()->devMode) {
            $this->tabBroadcastWarned = true;
            $this->app->log('warn', \sprintf(
                'Context::broadcast() syncs only this tab, since its primary scope is TAB; before php-via 0.14 it re-rendered '
                . 'every tab on the worker. To reach the scopes it joined (%s), call $app->broadcast() with one of them.',
                implode(', ', $joined),
            ), $this);
        }

        $this->sync();
    }

    /**
     * Time a block of work as a span in the Dev Bar's current trace.
     *
     * Wrap any operation worth seeing in the waterfall: a DB query, an HTTP
     * call, an expensive computation. The name's prefix before the first dot
     * (e.g. "db" in "db.list_issues") becomes the colour category. When tracing
     * is disabled the callable simply runs with zero overhead.
     *
     * ```php
     * $issues = $c->span('db.list_issues', fn () => $repo->all(), ['limit' => 50]);
     * ```
     *
     * @template T
     *
     * @param string               $name       Dotted span name
     * @param callable(): T        $fn         Work to time
     * @param array<string, mixed> $attributes Annotations shown under the span
     *
     * @return T
     */
    public function span(string $name, callable $fn, array $attributes = []): mixed {
        $tracer = Tracer::current();
        if ($tracer === null) {
            return $fn();
        }

        return $tracer->span($name, $fn, $attributes);
    }

    /**
     * Annotate the currently open span with a key/value pair.
     *
     * No-op when tracing is disabled or no span is open. Useful to record
     * decisions inside an action, e.g. `$c->traceAttribute('cache.hit', false)`.
     */
    public function traceAttribute(string $key, mixed $value): void {
        Tracer::current()?->setAttribute($key, $value);
    }

    /**
     * Define the UI rendered by this context.
     *
     * Either view($callable), which renders whatever the callable returns and receives
     * ($isUpdate, $basePath), or view('template.html.twig', $data, $block), which renders a
     * template through the engine from Config::withTemplateEngine() or withTemplateDir().
     *
     * @param callable(bool, string): string|string                 $view        Function that returns HTML, or a template name
     * @param array<string, mixed>|callable(): array<string, mixed> $data        Template data, or a callable that builds it on every render. Template views only.
     * @param null|string                                           $block       Block rendered on SSE updates instead of the whole template; the initial page load always renders the whole template. Template views only.
     * @param bool                                                  $shareRender Render each update once for every context of this view (same primary scope, route and component) instead of once per context. Only for views that are identical for every tab: TAB signals, per-user data or components inside the view make the shared HTML wrong for the others. Needs a primary scope set with scope(). A full-document view never shares its render.
     *
     * @throws \InvalidArgumentException when $data or $block is passed with a callable, or the template name is markup
     * @throws \LogicException           for a template without a template engine, or $block with an engine that renders no blocks
     */
    public function view(callable|string $view, array|callable $data = [], ?string $block = null, bool $shareRender = false): void {
        if (\is_string($view)) {
            if (str_contains($view, '<')) {
                throw new \InvalidArgumentException('view() takes a template name as a string, not markup. Return the HTML from a callable instead: $c->view(fn () => \'<div>...</div>\').');
            }
            $this->templateEngine("view('{$view}')", $block);

            $this->viewFn = fn (bool $isUpdate): string => $this->render($view, $this->resolveViewData($data), $isUpdate ? $block : null);
        } else {
            if ($block !== null) {
                throw new \InvalidArgumentException("view(callable, block: '{$block}') does nothing: a block applies only to a template view. Use \$c->view('template.html.twig', fn () => [...], block: '{$block}').");
            }
            if ($data !== []) {
                throw new \InvalidArgumentException('view(callable, $data): data applies only to a template view. Build the data inside the callable, or use $c->view(\'template.html.twig\', $data).');
            }

            $this->viewFn = $view;
        }

        $this->shareRender = $shareRender;
    }

    /**
     * Check if a view has been defined for this context.
     *
     * @internal
     */
    public function hasView(): bool {
        return $this->viewFn !== null;
    }

    /**
     * Whether update renders are shared by every context of this view in its primary scope.
     *
     * @internal
     */
    public function shouldShareRender(): bool {
        return $this->shareRender;
    }

    /**
     * Render a template with this context's data: its named signals and actions, '_via',
     * contextId, currentRoute, basePath, and via_head and via_foot. Explicit $data wins.
     *
     * @param array<string, mixed> $data  Data to pass to the template
     * @param null|string          $block Optional block name to render only that block
     *
     * @throws \LogicException without a template engine, or with $block and an engine that renders no blocks
     */
    public function render(string $template, array $data = [], ?string $block = null): string {
        $this->templateEngine("render('{$template}')", $block);
        $data = array_merge($this->buildAutoData(), $data); // explicit $data wins
        $data += ['contextId' => $this->id, 'currentRoute' => $this->route, 'basePath' => $this->app->getSettings()->basePath] + $this->documentData();

        return $this->app->getViewRenderer()->renderTemplate($template, $data, $block);
    }

    /**
     * @deprecated removed in 0.14; throws and names getTwig()->createTemplate()
     *
     * @param array<string, mixed> $data
     */
    public function renderString(string $template, array $data = []): never {
        Removed::method('Context::renderString()', 'Render a template held as a string with $app->getTwig()->createTemplate($template)->render($data).');
    }

    /**
     * The tags that connect a page to php-via, for its <head> right after <meta charset>: the
     * via_ctx signal, the import map (with withDatastarRocket() or withImportMap() entries), the
     * SSE connect with its reconnect, and the beacon that closes the context when the tab goes.
     *
     * The default shell writes it with {{ via_head }}, a custom shell with the same placeholder, a
     * Twig template with {{ via_head() }}, and a closure that returns a full document with this
     * method. Every tag carries the nonce from the page request's 'via.csp_nonce' attribute, which
     * middleware sets for a Content-Security-Policy. A component returns its page's.
     *
     * @throws \LogicException when 'via.csp_nonce' is set to something other than a string
     */
    public function viaHead(): string {
        $page = $this->getPageContext();
        $nonce = $page->cspNonce();
        $settings = $this->app->getSettings();

        return Bootstrap::head($page->id, $settings->basePath, $settings->importMapTag($nonce), $nonce);
    }

    /**
     * The Datastar module script, from Config::getDatastarUrl(), for the end of <body>: {{ via_foot }}
     * in a shell, {{ via_foot() }} in a Twig template. It carries the nonce of viaHead(). A layout
     * that loads its own Datastar bundle leaves it out.
     *
     * @throws \LogicException when 'via.csp_nonce' is set to something other than a string
     */
    public function viaFoot(): string {
        return Bootstrap::foot($this->app->getSettings()->datastarUrl, $this->getPageContext()->cspNonce());
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

        $scope = $this->getPrimaryScope();
        if ($this->shareRender && $scope === Scope::TAB) {
            throw new \LogicException("view(shareRender: true) on {$this->route} has no scope to share the render in: its primary scope is TAB. Call \$c->scope(...) with the shared scope, or drop shareRender.");
        }

        return $this->app->getViewRenderer()->renderView(
            $this->viewFn,
            $isUpdate,
            $scope,
            $this,
            $this->route
        );
    }

    /**
     * Create a signal.
     *
     * @param mixed       $initialValue   The initial value of the signal
     * @param string      $name           The signal's name in this context, used by getSignal(), templates
     *                                    and the browser id
     * @param null|string $scope          Optional scope for shared signal (null = TAB scope, no sharing)
     * @param bool        $autoBroadcast  Auto-broadcast changes for scoped signals (default: true)
     * @param null|bool   $clientWritable Whether the client may write this signal. null (default):
     *                                    TAB signals are writable, scoped ones server-owned, and
     *                                    Config::withStrictTabSignals() makes TAB ones server-owned
     *                                    too. true or false applies to any scope.
     *
     * TAB scope (scope=null): Signal is private to this context, not shared
     * ROUTE/SESSION/GLOBAL scope: Signal is shared across all contexts in the same scope
     * Custom scope: Signal is shared across all contexts with that scope (e.g., "room:lobby")
     *
     * A scoped signal joins this context to its scope, so its writes reach the tab. The primary scope
     * set by scope() is not a default: after scope() set a shared one, a signal without a scope throws.
     *
     * Declaring a TAB signal again with the same name returns the existing signal and sets it to
     * the new initial value; a warning is logged when that changes the live value.
     *
     * @throws \LogicException without a scope, after scope() set a primary scope other than TAB
     */
    public function signal(mixed $initialValue, string $name, ?string $scope = null, bool $autoBroadcast = true, ?bool $clientWritable = null): Signal {
        return $this->signalFactory->createSignal($initialValue, $name, $scope, $autoBroadcast, $clientWritable);
    }

    /**
     * Get a signal by its user-supplied name.
     *
     * Works for both TAB-scoped and scoped (ROUTE/SESSION/GLOBAL/custom) signals.
     *
     * @param string $name Signal name as passed to signal()
     *
     * @return null|Signal The signal if found, null otherwise
     */
    public function getSignal(string $name): ?Signal {
        return $this->signalFactory->getSignal($name);
    }

    /**
     * Get all named signals registered on this context, keyed by user-supplied name.
     *
     * Covers all scopes: TAB, ROUTE, SESSION, GLOBAL, and custom.
     *
     * @return array<string, Signal>
     *
     * @internal
     */
    public function getNamedSignals(): array {
        return $this->signalFactory->getNamedSignals();
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
     * Get a named action by its user-supplied name.
     *
     * Only actions registered with an explicit $name are retrievable.
     *
     * @param string $name Action name as passed to action()
     *
     * @return null|Action The action if found, null otherwise
     */
    public function getAction(string $name): ?Action {
        return $this->namedActions[$name] ?? null;
    }

    /**
     * Get all named actions registered on this context, keyed by user-supplied name.
     *
     * Only actions registered with an explicit $name are included.
     *
     * @return array<string, Action>
     *
     * @internal
     */
    public function getNamedActions(): array {
        return $this->namedActions;
    }

    /**
     * Create an action trigger. It runs for the tab, or the component, that posts it.
     *
     * Registering a name twice keeps the later callback and logs a warning once per id.
     *
     * @param callable    $fn         The action function to execute
     * @param null|string $name       Optional human-readable name
     * @param mixed       ...$removed Nothing: the $scope argument was removed in php-via 0.14, and a value here throws
     */
    public function action(callable $fn, ?string $name = null, mixed ...$removed): Action {
        if ($removed !== []) {
            // ArgumentCountError, like Signal's removed flags: no catch (\Exception) block hides it.
            throw new \ArgumentCountError('The $scope argument of Context::action() was removed in php-via 0.14. An action runs for the tab that posts it: drop the third argument, and give signal() a scope to share state.');
        }

        // Deterministic ID so a destroyed context that is later revived
        // (re-created with the same context ID, handler re-run) regenerates byte-identical
        // action URLs: the already-loaded DOM's buttons keep working without a reload.
        // Keyed on the stable namespace (not the random component context ID): a component's
        // namespace disambiguates its actions from the parent page's (e.g. `a-increment` vs
        // `increment`), which keeps executeAction()'s parent-first lookup unambiguous.
        $base = $name ?? 'action' . $this->anonActionSeq++;
        $namespace = $this->getNamespace();
        $actionId = $namespace !== null ? $namespace . '-' . $base : $base;

        if (isset($this->actionRegistry[$actionId]) && !isset($this->duplicateActionWarned[$actionId])) {
            $this->duplicateActionWarned[$actionId] = true;
            $this->app->log('warn', "Action '{$actionId}' registered twice in this context; the later callback replaces the earlier one", $this);
        }

        $this->actionRegistry[$actionId] = $fn;

        $action = new Action($actionId, $this->app->getSettings()->basePath);

        if ($name !== null) {
            $this->namedActions[$name] = $action;
        }

        return $action;
    }

    /**
     * Register an action shared by every context of $scope, as #[Action(scope: ...)] does. The first callback
     * registered for an id in a scope serves the whole scope and receives the context that posts it and the id.
     *
     * @internal used by PageMount
     *
     * @param callable(Context, string): void $fn
     */
    public function scopedAction(callable $fn, string $name, string $scope): Action {
        $actionScope = Scope::resolve($scope, $this, '#[Action(scope: ...)]');
        // The id holds no context id, so a shared render carries the same URL for every context. A component's
        // namespace is in it, as in action(), so two components that declare one name keep apart.
        $namespace = $this->getNamespace();
        $actionId = $namespace !== null ? $namespace . '-' . $name : $name;
        if ($actionScope === Scope::TAB) {
            return $this->action(static fn (Context $caller) => $fn($caller, $actionId), $name);
        }

        $action = new Action($actionId, $this->app->getSettings()->basePath);
        $this->namedActions[$name] = $action;

        if ($this->app->getScopedAction($actionScope, $actionId) !== null) {
            $this->app->log('debug', "[{$this->getId()}] Reusing existing action {$actionId} in scope {$actionScope}", $this);

            return $action;
        }

        $this->app->log('debug', "[{$this->getId()}] Registering new action {$actionId} in scope {$actionScope}", $this);
        $this->app->registerScopedAction($actionScope, $actionId, static fn (Context $caller) => $fn($caller, $actionId));

        return $action;
    }

    /**
     * Execute a registered action.
     *
     * @internal Called by Via when handling action requests
     */
    public function executeAction(string $actionId): void {
        // First check TAB-scoped actions (context-specific)
        if (isset($this->actionRegistry[$actionId])) {
            $this->app->log('debug', "Found TAB-scoped action {$actionId}", $this);
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

        $sessionScope = $this->sessionId !== null ? Scope::sessionScope($this->sessionId) : null;
        if ($sessionScope !== null && !\in_array($sessionScope, $scopes, true)) {
            $scopedAction = $this->app->getScopedAction($sessionScope, $actionId);
            if ($scopedAction !== null) {
                $this->app->log('debug', "Found scoped action {$actionId} in SESSION scope", $this);
                $scopedAction($this);

                return;
            }
        }

        $component = $this->componentWithAction($actionId);
        if ($component !== null) {
            $component->executeAction($actionId);

            return;
        }

        throw new \RuntimeException("Action not found: {$actionId}");
    }

    /**
     * Create a component (sub-context).
     *
     * Accepts either a setup callable (existing API) or a composition-pattern
     * class name string. When a class name is provided, the framework builds
     * the setup closure from the class's #[Signal]/#[Action] metadata.
     *
     * @param callable|class-string $fn        Component setup function, or class name
     * @param string                $namespace Name of the component, unique on its page: it prefixes the component's
     *                                         signals and actions and keeps its id stable when the page is rebuilt
     *
     * @return callable Returns a function that renders the component
     */
    public function component(callable|string $fn, string $namespace): callable {
        if (\is_string($fn)) {
            $fn = PageMount::buildClosure(ClassMetadata::analyze($fn), $this->app);
        }

        return $this->componentManager->createComponent($fn, $namespace);
    }

    /**
     * Sync current view and signals to the browser.
     */
    public function sync(): void {
        $this->patchManager->sync();
    }

    /**
     * Sync for a broadcast fan-out that began reading state at read epoch $epoch.
     *
     * @internal Called by Via
     *
     * @return bool false when a fan-out that began later already queued its frame, so the one just queued is older
     */
    public function syncFanOut(int $epoch): bool {
        $this->patchManager->sync();
        if ($this->fanOutEpoch > $epoch) {
            return false;
        }
        // A sync held for a seed queues nothing but still takes $epoch, so an older fan-out that
        // renders this context once the wait ends renders it again.
        $this->fanOutEpoch = $epoch;

        return true;
    }

    /**
     * Execute JavaScript on the client.
     */
    public function execScript(string $script): void {
        $this->patchManager->execScript($script);
    }

    /**
     * Patch HTML into this tab outside the view, such as a modal, a toast or a chunk of streamed output.
     *
     * Without $selector, Outer and Replace match the top-level elements of $html by id; the other modes
     * need a selector. Remove needs no HTML: patchElements(selector: '#toast', mode: PatchMode::Remove).
     * Patches queue until the tab's stream is open, like sync(). Each one counts, since no render sends it
     * again: a full queue drops them last, and a client that falls behind gets them all.
     *
     * @throws \InvalidArgumentException when there is neither HTML nor a selector, or the mode needs a selector
     */
    public function patchElements(string $html = '', ?string $selector = null, PatchMode $mode = PatchMode::Outer): void {
        if ($html === '' && ($selector ?? '') === '') {
            throw new \InvalidArgumentException('patchElements() needs HTML, a selector, or both.');
        }
        if (($selector ?? '') === '' && $mode !== PatchMode::Outer && $mode !== PatchMode::Replace) {
            throw new \InvalidArgumentException("PatchMode::{$mode->name} needs a selector: only Outer and Replace find their target by the element's id.");
        }

        $patch = ['type' => 'elements', 'content' => $html, 'mode' => $mode];
        if ($selector !== null && $selector !== '') {
            $patch['selector'] = $selector;
        }

        $this->patchManager->queuePatch($patch);
    }

    /**
     * Whether this tab's SSE stream is open on this worker.
     *
     * sync() and patches sent while it is not are queued and delivered when the tab connects or
     * reconnects, so checking it first only saves the render.
     */
    public function isConnected(): bool {
        return ($this->app->activeSseCount[$this->getPageContext()->id] ?? 0) > 0;
    }

    /**
     * The page this context belongs to: the page itself, or for a component the page it sits on.
     *
     * Use it to tell which visitor an action inside a component came from.
     */
    public function getPageContext(): self {
        return $this->componentManager->getParentPageContext() ?? $this;
    }

    /**
     * Inject signals from the client.
     *
     * @internal Called by Via when processing requests
     *
     * @param array<int|string, mixed> $signalsData Nested structure of signals from the client
     */
    public function injectSignals(array $signalsData): void {
        // Values posted by an action seed the context as a revival would.
        if ($this->seedWait !== null && array_diff_key($signalsData, ['via_ctx' => true]) !== []) {
            $this->seedWait = null;
        }

        $this->signalFactory->injectSignals($signalsData);
    }

    /**
     * Hold this context's syncs until its next SSE connect seeds the TAB signals. A context
     * without TAB signals has nothing to seed and does not wait.
     *
     * @internal set by Via when an action revived the context from a request without signals
     *
     * @return bool Whether the wait began
     */
    public function awaitSeed(): bool {
        $signals = $this->collectTabSignals();
        if ($signals === []) {
            return false;
        }

        $this->seedWait = array_map(static fn (Signal $signal): array => [$signal, $signal->writeCount()], $signals);

        return true;
    }

    /**
     * Whether this context waits for its SSE connect to seed it.
     *
     * @internal read by PatchManager, which queues no sync while it waits
     */
    public function isAwaitingSeed(): bool {
        return $this->seedWait !== null;
    }

    /**
     * Give the client's values to the TAB signals no write has touched since the wait began, then
     * end the wait. The usual clientWritable rules apply. Without a wait this does nothing.
     *
     * @internal called through Via::seedFromConnect()
     *
     * @param array<int|string, mixed> $clientSignals Signal values the SSE connect carries
     */
    public function seedFromClient(array $clientSignals): void {
        if ($this->seedWait === null) {
            return;
        }

        $seed = [];
        $written = [];
        foreach ($this->seedWait as [$signal, $writes]) {
            $id = $signal->id();
            if ($signal->writeCount() !== $writes) {
                $written[$id] = true;
            } elseif (\array_key_exists($id, $clientSignals)) {
                $seed[$id] = $clientSignals[$id];
            }
        }

        $this->seedWait = null;
        // Ids are injective, so $seed holds no written id; the diff keeps a write winning should
        // two seeded signals ever share an id again.
        $this->signalFactory->injectSignals(array_diff_key($seed, $written));
    }

    /**
     * Get next patch from the queue, or null if none is available. Its `confirm` must be invoked
     * only after the patch has actually been written.
     *
     * @internal Called by Via during SSE event streaming
     *
     * @return null|array{type: string, content: mixed, selector?: string, mode?: ElementPatchMode|PatchMode, confirm?: callable(): void}
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
     * The component, at any depth, that registered $actionId for its tab or in a scope it joined.
     */
    private function componentWithAction(string $actionId): ?self {
        foreach ($this->componentManager->getComponents() as $component) {
            if ($component->hasAction($actionId)) {
                return $component;
            }
            foreach ($component->getScopes() as $scope) {
                if ($this->app->getScopedAction($scope, $actionId) !== null) {
                    return $component;
                }
            }
            $nested = $component->componentWithAction($actionId);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * via_head and via_foot as template data, built only when a template prints them.
     *
     * @return array{via_head: Html, via_foot: Html}
     */
    private function documentData(): array {
        return ['via_head' => new Html($this->viaHead(...)), 'via_foot' => new Html($this->viaFoot(...))];
    }

    /**
     * The app's template engine, for $call, which renders a template.
     *
     * @throws \LogicException without an engine, or with $block and an engine that renders no blocks
     */
    private function templateEngine(string $call, ?string $block): TemplateEngine {
        $engine = $this->app->getViewRenderer()->getEngine();
        if ($engine === null) {
            throw new \LogicException("{$call} renders a template, and this app has no template engine. For Twig templates run composer require twig/twig, then set \$config->withTemplateDir(__DIR__ . '/templates') or ->withTemplateEngine(new \\Mbolli\\PhpVia\\Twig\\TwigEngine(__DIR__ . '/templates')). Without templates, return the HTML from a closure: \$c->view(fn () => '<div>...</div>').");
        }
        if ($block !== null && !$engine->supportsBlocks()) {
            throw new \LogicException("{$call} with block: '{$block}' needs a template engine that renders single blocks, and " . $engine::class . ' does not. Drop block:, so updates render the whole template.');
        }

        return $engine;
    }

    /**
     * The CSP nonce from this context's page request, null without one.
     *
     * @throws \LogicException when 'via.csp_nonce' is set to something other than a string
     */
    private function cspNonce(): ?string {
        $nonce = $this->requestAttributes['via.csp_nonce'] ?? null;
        if ($nonce === null || \is_string($nonce)) {
            return $nonce;
        }

        throw new \LogicException("The 'via.csp_nonce' request attribute holds the CSP nonce for via_head and via_foot as a string, got " . get_debug_type($nonce) . '.');
    }

    /**
     * The data of a template view for one render.
     *
     * @param array<string, mixed>|callable(): array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function resolveViewData(array|callable $data): array {
        return \is_array($data) ? $data : $data();
    }

    /**
     * The context that holds the current request: the page, for a component, which has none of its own.
     */
    private function requestOwner(): self {
        return $this->componentManager->getParentPageContext() ?? $this;
    }

    /**
     * Build the auto-injection data array for templates.
     *
     * Merges all named signals (keyed by user-supplied name) and named actions
     * (keyed by camelCase of user-supplied name) into a single array, plus a
     * `_via` debug key listing all available names.
     *
     * Result is cached after first call: signals and actions are frozen after
     * page setup, so repeated calls during SSE ticks are free.
     *
     * @return array<string, mixed>
     */
    private function buildAutoData(): array {
        if ($this->autoDataCache !== null) {
            return $this->autoDataCache;
        }

        $namedSignals = $this->signalFactory->getNamedSignals();

        $camelActions = [];
        $camelKeyToRaw = [];

        foreach ($this->namedActions as $rawName => $action) {
            $camelKey = $this->toCamelCase($rawName);

            if (isset($camelKeyToRaw[$camelKey])) {
                $this->app->log('warning', "Auto-inject action name collision: '{$camelKeyToRaw[$camelKey]}' and '{$rawName}' both resolve to '{$camelKey}'");
            }

            $camelKeyToRaw[$camelKey] = $rawName;
            $camelActions[$camelKey] = $action;
        }

        foreach ($namedSignals as $signalName => $_) {
            if (isset($camelActions[$signalName])) {
                $this->app->log('warning', "Auto-inject clash: '{$signalName}' is both a signal name and a camelCased action name");
            }
        }

        $this->autoDataCache = array_merge(
            $namedSignals,
            $camelActions,
            ['_via' => ['signals' => array_keys($namedSignals), 'actions' => array_keys($camelActions)]],
        );

        return $this->autoDataCache;
    }

    /**
     * Convert a kebab-case or snake_case name to camelCase.
     *
     * Examples: 'refresh-graphs' => 'refreshGraphs', 'save_settings' => 'saveSettings'
     */
    private function toCamelCase(string $name): string {
        return lcfirst(str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name))));
    }

    /**
     * Check if this context has a specific action.
     */
    private function hasAction(string $actionId): bool {
        return isset($this->actionRegistry[$actionId]);
    }

    /**
     * TAB signals of this context and, recursively, of its components.
     *
     * @return list<Signal>
     */
    private function collectTabSignals(): array {
        $signals = array_values($this->signalFactory->getTabSignals());
        foreach ($this->componentManager->getComponents() as $component) {
            array_push($signals, ...$component->collectTabSignals());
        }

        return $signals;
    }
}
