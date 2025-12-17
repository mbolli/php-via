<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\ServerSentEventGenerator;
use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Timer;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Via - Real-time engine for building reactive web applications in PHP.
 *
 * Main application class that manages routing, contexts, and SSE connections.
 */
class Via {
    private Server $server;

    /** @var array<string, callable> */
    private array $routes = [];

    /** @var array<string, array<string, callable>> Route-level actions */
    private array $routeActions = [];

    /** @var array<string, Context> */
    private array $contexts = [];

    /** @var array<string, int> Cleanup timer IDs for contexts */
    private array $cleanupTimers = [];

    /** @var array<string, array{id: string, identicon: string, connected_at: int, ip: string}> Client info by context ID */
    private array $clients = [];

    /** @var array{render_count: int, total_time: float, min_time: float, max_time: float} */
    private array $renderStats = ['render_count' => 0, 'total_time' => 0.0, 'min_time' => PHP_FLOAT_MAX, 'max_time' => 0.0];

    /** @var array<string, string> Route-based view cache (route -> html) */
    private array $viewCache = [];

    /** @var array<string, bool> Tracks if route is currently rendering (prevents race condition) */
    private array $rendering = [];

    /** @var array<string, mixed> Global state shared across all routes and clients */
    private array $globalState = [];

    /** @var null|string Global view cache (shared across all routes) */
    private ?string $globalViewCache = null;

    /** @var array<int, string> */
    private array $headIncludes = [];

    /** @var array<int, string> */
    private array $footIncludes = [];
    private Environment $twig;

    public function __construct(private Config $config) {
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

        // Initialize Twig with appropriate loader
        if ($this->config->getTemplateDir()) {
            $loader = new FilesystemLoader($this->config->getTemplateDir());
        } else {
            $loader = new ArrayLoader([]);
        }

        $this->twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);

        // Add custom Twig functions for Via
        $this->addTwigFunctions();

        $this->server->on('start', function (Server $server): void {
            $this->log('info', "Via server started on {$this->config->getHost()}:{$this->config->getPort()}");
        });

        $this->server->on('request', function (Request $request, Response $response): void {
            $this->handleRequest($request, $response);
        });
    }

    /**
     * Get the configuration instance for fluent configuration.
     */
    public function config(): Config {
        return $this->config;
    }

    /**
     * Apply configuration changes (called internally after fluent config).
     */
    public function applyConfig(): void {
        // Update Twig loader if template directory is set
        if ($this->config->getTemplateDir()) {
            $loader = new FilesystemLoader($this->config->getTemplateDir());
            $this->twig->setLoader($loader);
        }
    }

    /**
     * Get global state value.
     */
    public function globalState(string $key, mixed $default = null): mixed {
        return $this->globalState[$key] ?? $default;
    }

    /**
     * Set global state value.
     */
    public function setGlobalState(string $key, mixed $value): void {
        $this->globalState[$key] = $value;
    }

    /**
     * Get the global view cache.
     *
     * @internal Used by Context for global scope caching
     */
    public function getGlobalViewCache(): ?string {
        return $this->globalViewCache;
    }

    /**
     * Set the global view cache.
     *
     * @internal Used by Context for global scope caching
     */
    public function setGlobalViewCache(string $html): void {
        $this->globalViewCache = $html;
    }

    /**
     * Register a page route with its handler.
     *
     * @param string   $route   The route pattern (e.g., '/')
     * @param callable $handler Function that receives a Context instance
     */
    public function page(string $route, callable $handler): void {
        $this->routes[$route] = $handler;
    }

    /**
     * Broadcast sync to all contexts on a specific route.
     *
     * Automatically detects scope:
     * - Route scope: Invalidates cache, triggers ONE render shared by all
     * - Tab scope: Triggers per-context renders
     */
    public function broadcast(string $route): void {
        // Detect if any context or component on this route is ROUTE-scoped
        if ($this->hasRouteScopeOnRoute($route)) {
            $this->invalidateViewCache($route);
            $this->log('debug', "Broadcasting to ROUTE-scoped page: {$route} (cache invalidated)");
        } else {
            $this->log('debug', "Broadcasting to TAB-scoped page: {$route} (no cache)");
        }

        $this->syncContextsOnRoute($route);
    }

    /**
     * Broadcast to ALL contexts across ALL routes (global scope).
     * Invalidates global cache and syncs every connected client.
     */
    public function broadcastGlobal(): void {
        $this->invalidateGlobalViewCache();
        $this->log('debug', 'Broadcasting globally (cache invalidated, syncing all contexts)');
        $this->syncAllContexts();
    }

    /**
     * Add elements to the document head.
     */
    public function appendToHead(string ...$elements): void {
        $this->headIncludes = array_merge($this->headIncludes, $elements);
    }

    /**
     * Add elements to the document footer.
     */
    public function appendToFoot(string ...$elements): void {
        $this->footIncludes = array_merge($this->footIncludes, $elements);
    }

    /**
     * Start the Via server.
     */
    public function start(): void {
        $this->server->start();
    }

    /**
     * Log message.
     */
    public function log(string $level, string $message, ?Context $context = null): void {
        $levels = ['debug' => 0, 'info' => 1, 'warn' => 2, 'error' => 3];
        $configLevel = $levels[$this->config->getLogLevel()] ?? 1;

        if ($levels[$level] >= $configLevel) {
            $prefix = $context ? "[{$context->getId()}] " : '';
            echo '[' . mb_strtoupper($level) . "] {$prefix}{$message}\n";
        }
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
     * Get render statistics.
     *
     * @return array{render_count: int, total_time: float, min_time: float, max_time: float, avg_time: float}
     */
    public function getRenderStats(): array {
        $stats = $this->renderStats;
        $stats['avg_time'] = $stats['render_count'] > 0 ? $stats['total_time'] / $stats['render_count'] : 0.0;
        if ($stats['min_time'] === PHP_FLOAT_MAX) {
            $stats['min_time'] = 0.0;
        }

        return $stats;
    }

    /**
     * Track view render time.
     *
     * @internal Called by Context during rendering
     */
    public function trackRender(float $duration): void {
        ++$this->renderStats['render_count'];
        $this->renderStats['total_time'] += $duration;
        $this->renderStats['min_time'] = min($this->renderStats['min_time'], $duration);
        $this->renderStats['max_time'] = max($this->renderStats['max_time'], $duration);
    }

    /**
     * Get cached view HTML for a route if available and fresh.
     *
     * @internal Used by Context for scope-based caching
     */
    public function getCachedView(string $route): ?string {
        return $this->viewCache[$route] ?? null;
    }

    /**
     * Cache rendered view HTML for a route.
     *
     * @internal Used by Context for scope-based caching
     */
    public function cacheView(string $route, string $html): void {
        $this->viewCache[$route] = $html;
    }

    /**
     * Get Twig environment.
     *
     * @internal Used by Context for template rendering
     */
    public function getTwig(): Environment {
        return $this->twig;
    }

    /**
     * Register a route-level action (shared across all contexts on this route).
     *
     * @internal Called by Context when creating route/global actions
     */
    public function registerRouteAction(string $route, string $actionId, callable $handler): void {
        if (!isset($this->routeActions[$route])) {
            $this->routeActions[$route] = [];
        }
        $this->routeActions[$route][$actionId] = $handler;
    }

    /**
     * Check if a route is currently rendering.
     *
     * @internal Used by Context for render locking
     */
    public function isRendering(string $route): bool {
        return $this->rendering[$route] ?? false;
    }

    /**
     * Set rendering status for a route.
     *
     * @internal Used by Context for render locking
     */
    public function setRendering(string $route, bool $status): void {
        if ($status) {
            $this->rendering[$route] = true;
        } else {
            unset($this->rendering[$route]);
        }
    }

    /**
     * Invalidate the global view cache.
     */
    private function invalidateGlobalViewCache(): void {
        $this->globalViewCache = null;
    }

    /**
     * Check if any context or component on the given route is ROUTE-scoped.
     */
    private function hasRouteScopeOnRoute(string $route): bool {
        foreach ($this->contexts as $ctx) {
            if ($ctx->getRoute() === $route) {
                if ($ctx->getScope() === Scope::ROUTE) {
                    return true;
                }

                foreach ($ctx->getComponentRegistry() as $componentCtx) {
                    if ($componentCtx->getScope() === Scope::ROUTE) {
                        return true;
                    }
                }
            }
        }

        return false;
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
     * Invalidate view cache for a route (called on broadcast).
     */
    private function invalidateViewCache(string $route): void {
        unset($this->viewCache[$route]);
    }

    /**
     * Get route action handler.
     */
    private function getRouteAction(string $route, string $actionId): ?callable {
        return $this->routeActions[$route][$actionId] ?? null;
    }

    /**
     * Read Datastar signals from a Swoole HTTP request.
     *
     * This is a replacement for ServerSentEventGenerator::readSignals() which only checks
     * $_GET['datastar'] and php://input, but doesn't handle $_POST.
     * In Swoole, POST requests need special handling since we use $request->post instead of $_POST.
     *
     * @return array<string, mixed> The decoded signals array
     */
    private static function readSignals(Request $request): array {
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
     * Handle incoming HTTP requests.
     */
    private function handleRequest(Request $request, Response $response): void {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];

        // Populate superglobals for compatibility
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];

        $this->log('debug', "Request: {$method} {$path}");

        // Serve Datastar.js
        if ($path === '/_datastar.js') {
            $this->serveDatastarJs($response);

            return;
        }

        // Handle SSE connection
        if ($path === '/_sse') {
            $this->handleSSE($request, $response);

            return;
        }

        // Handle action triggers
        if (preg_match('#^/_action/(.+)$#', $path, $matches)) {
            $this->handleAction($request, $response, $matches[1]);

            return;
        }

        // Handle session close
        if ($path === '/_session/close' && $method === 'POST') {
            $this->handleSessionClose($request, $response);

            return;
        }

        // Handle stats endpoint
        if ($path === '/_stats' && $method === 'GET') {
            $this->handleStats($request, $response);

            return;
        }

        // Handle page routes
        foreach ($this->routes as $route => $handler) {
            $params = [];
            if ($this->matchRoute($route, $path, $params)) {
                $this->handlePage($request, $response, $route, $handler, $params);

                return;
            }
        }

        // 404 Not Found
        $response->status(404);
        $response->end('Not Found');
    }

    /**
     * Handle page rendering.
     *
     * @param array<string, string> $params Route parameters
     */
    private function handlePage(Request $request, Response $response, string $route, callable $handler, array $params = []): void {
        // Generate unique context ID
        $contextId = $route . '_/' . $this->generateId();

        // Create context
        $context = new Context($contextId, $route, $this);

        // Inject route parameters
        $context->injectRouteParams($params);

        // Execute the page handler with automatic parameter injection
        $this->invokeHandlerWithParams($handler, $context, $params);

        // Store context
        $this->contexts[$contextId] = $context;

        // Build HTML document
        $html = $this->buildHtmlDocument($context);

        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end($html);
    }

    /**
     * Handle SSE connection for real-time updates.
     */
    private function handleSSE(Request $request, Response $response): void {
        // Get context ID from signals
        $signals = self::readSignals($request);
        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $sse = new ServerSentEventGenerator();
        // Set SSE headers using Datastar SDK
        foreach (ServerSentEventGenerator::headers() as $name => $value) {
            $response->header($name, $value);
        }

        // If context doesn't exist, it was cleaned up
        // Use Datastar SDK to send reload script
        if (!isset($this->contexts[$contextId])) {
            $this->log('info', "Context expired, sending reload: {$contextId}");

            // Use Datastar's executeScript to reload
            $output = $sse->executeScript('window.location.reload()');
            $response->write($output);
            $response->end();

            return;
        }

        $context = $this->contexts[$contextId];

        // Track client info when SSE connects (not at page load)
        if (!isset($this->clients[$contextId])) {
            $clientId = $this->generateClientId();
            $this->clients[$contextId] = [
                'id' => $clientId,
                'identicon' => $this->generateIdenticon($clientId),
                'connected_at' => time(),
                'ip' => $request->server['remote_addr'] ?? 'unknown',
            ];
        }

        $this->log('debug', "SSE connection established for context: {$contextId}");

        // Cancel any pending cleanup timer for this context (reconnection)
        if (isset($this->cleanupTimers[$contextId])) {
            Timer::clear($this->cleanupTimers[$contextId]);
            unset($this->cleanupTimers[$contextId]);
            $this->log('debug', "Cancelled cleanup timer for reconnected context: {$contextId}");
        }

        // Send initial sync (view + signals) on connection/reconnection
        // Do this synchronously to ensure patches are ready before the loop starts
        $context->sync();

        // Keep connection alive and listen for patches
        $lastKeepalive = time();
        while (true) {
            if (!$response->isWritable()) {
                break;
            }

            // Check for patches from the context
            $patch = $context->getPatch();
            if ($patch) {
                try {
                    $output = $this->sendSSEPatch($sse, $patch);
                    if (!$response->write($output)) {
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->log('debug', 'Patch write exception, client disconnected: ' . $e->getMessage(), $context);

                    break;
                }
            }

            // Send keepalive comment every 30 seconds to prevent timeout
            if (time() - $lastKeepalive >= 30) {
                try {
                    if (!$response->write(": keepalive\n\n")) {
                        break;
                    }
                    $lastKeepalive = time();
                } catch (\Throwable $e) {
                    break;
                }
            }

            Coroutine::sleep(0.1);
        }

        $this->log('debug', "SSE connection closed for context: {$context->getId()}");

        // Don't immediately cleanup - give client time to reconnect (e.g., tab switching)
        // Schedule cleanup after 60 seconds of inactivity
        $timerId = Timer::after(60000, function () use ($contextId): void {
            if (isset($this->contexts[$contextId])) {
                $this->log('debug', "Cleaning up inactive context: {$contextId}");
                $this->contexts[$contextId]->cleanup();
                unset($this->contexts[$contextId], $this->clients[$contextId], $this->cleanupTimers[$contextId]);
            }
        });

        $this->cleanupTimers[$contextId] = $timerId;
    }

    /**
     * Handle action triggers from the client.
     */
    private function handleAction(Request $request, Response $response, string $actionId): void {
        // Read signals from request
        $signals = self::readSignals($request);

        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId || !isset($this->contexts[$contextId])) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $context = $this->contexts[$contextId];
        $route = $context->getRoute();

        // Check for global action first
        $globalHandler = $this->getRouteAction('__global__', $actionId);
        if ($globalHandler !== null) {
            try {
                // Inject signals into context
                $context->injectSignals($signals);

                $this->log('debug', "Executing global action {$actionId}");

                // Execute the global action with context
                $globalHandler($context);

                $this->log('debug', "Global action {$actionId} completed successfully");

                $response->status(200);
                $response->end();

                return;
            } catch (\Exception $e) {
                $this->log('error', "Global action {$actionId} failed: " . $e->getMessage());
                $response->status(500);
                $response->end('Action failed');

                return;
            }
        }

        // Check for route-level action
        $routeHandler = $this->getRouteAction($route, $actionId);
        if ($routeHandler !== null) {
            try {
                // Inject signals into context
                $context->injectSignals($signals);

                $this->log('debug', "Executing route action {$actionId} for route {$route}");

                // Execute the route-level action with context
                $routeHandler($context);

                $this->log('debug', "Route action {$actionId} completed successfully");

                $response->status(200);
                $response->end();

                return;
            } catch (\Exception $e) {
                $this->log('error', "Route action {$actionId} failed: " . $e->getMessage());
                $response->status(500);
                $response->end('Action failed');

                return;
            }
        }

        try {
            // Inject signals into context
            $context->injectSignals($signals);

            $this->log('debug', "Executing action {$actionId} for context {$contextId}");

            // Execute the context-level action
            $context->executeAction($actionId);

            $this->log('debug', "Action {$actionId} completed successfully");

            $response->status(200);
            $response->end();
        } catch (\Exception $e) {
            $this->log('error', "Action {$actionId} failed: " . $e->getMessage());
            $response->status(500);
            $response->end('Action failed');
        }
    }

    /**
     * Handle session close.
     */
    private function handleSessionClose(Request $request, Response $response): void {
        $contextId = $request->rawContent();

        if (isset($this->contexts[$contextId])) {
            unset($this->contexts[$contextId], $this->clients[$contextId]);

            $this->log('debug', "Context closed: {$contextId}");
        }

        $response->status(200);
        $response->end();
    }

    /**
     * Handle stats endpoint.
     */
    private function handleStats(Request $request, Response $response): void {
        $stats = [
            'contexts' => \count($this->contexts),
            'clients' => $this->getClients(),
            'render_stats' => $this->getRenderStats(),
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true),
            ],
            'uptime' => time() - ($_SERVER['REQUEST_TIME'] ?? time()),
        ];

        $response->header('Content-Type', 'application/json');
        $response->end(json_encode($stats, JSON_PRETTY_PRINT));
    }

    /**
     * Serve Datastar JavaScript file.
     */
    private function serveDatastarJs(Response $response): void {
        // In a real implementation, embed the datastar.js file
        $datastarJs = file_get_contents(__DIR__ . '/../datastar.js');

        $response->header('Content-Type', 'application/javascript');
        $response->end($datastarJs);
    }

    /**
     * Send SSE patch to client using Datastar SDK.
     *
     * @param array{type: string, content: mixed, selector?: string, mode?: ElementPatchMode} $patch
     */
    private function sendSSEPatch(ServerSentEventGenerator $sse, array $patch): string {
        $type = $patch['type'];
        $content = $patch['content'];
        $selector = $patch['selector'] ?? null;
        $mode = $patch['mode'] ?? null;

        return match ($type) {
            'elements' => $sse->patchElements($content, array_filter([
                'selector' => $selector,
                'mode' => $mode,
            ])),
            'signals' => $sse->patchSignals($content),
            'script' => $sse->executeScript($content),
            default => ''
        };
    }

    /**
     * Build complete HTML document.
     */
    private function buildHtmlDocument(Context $context): string {
        $contextId = $context->getId();

        $headContent = implode("\n", $this->headIncludes);
        $footContent = implode("\n", $this->footIncludes);

        $content = $context->renderView();

        // If it's a full page (already processed by processView), return it
        if (stripos($content, '<html') !== false) {
            return $content;
        }

        // Use the shell template for fragments
        $signalsJson = json_encode([
            'via_ctx' => $contextId,
            '_disconnected' => false,
        ]);

        // Build replacement arrays (base + signals)
        $replacements = [
            '{{ signals_json }}' => $signalsJson,
            '{{ context_id }}' => $contextId,
            '{{ head_content }}' => $headContent,
            '{{ content }}' => $content,
            '{{ foot_content }}' => $footContent,
        ];

        // Add signal replacements - extract signal name from ID for route-scoped signals
        // e.g., "embed" from route-scoped or "greeting_TAB123" from tab-scoped
        foreach ($context->getSignals() as $fullId => $signal) {
            // Get the base name (before underscore for tab-scoped signals)
            $baseName = strpos($fullId, '_') !== false 
                ? substr($fullId, 0, strpos($fullId, '_'))
                : $fullId;
            
            // Support both {{ signalName }} for value and {{ signalName.id }} for ID
            $replacements['{{ ' . $baseName . ' }}'] = json_encode($signal->getValue());
            $replacements['{{ ' . $baseName . '.id }}'] = $signal->id();
        }

        // Simple template replacement for the shell
        $shellPath = $this->config->getShellTemplate() ?? __DIR__ . '/../shell.html';
        $shell = file_get_contents($shellPath);

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $shell
        );
    }

    /**
     * Match route pattern.
     */
    /**
     * Match route pattern against path and extract parameters.
     *
     * @param string                $route  Route pattern (e.g., '/users/{id}')
     * @param string                $path   Request path (e.g., '/users/123')
     * @param array<string, string> $params Output array for extracted parameters
     *
     * @return bool True if route matches
     */
    private function matchRoute(string $route, string $path, array &$params = []): bool {
        // Exact match (no parameters)
        if ($route === $path) {
            return true;
        }

        // Check if route has parameters
        if (!str_contains($route, '{')) {
            return false;
        }

        // Convert route pattern to regex
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_]\w*)\}/',
            static fn (array $matches) => '(?P<' . $matches[1] . '>[^/]+)',
            $route
        );
        $pattern = '#^' . $pattern . '$#';

        // Match and extract parameters
        if (preg_match($pattern, $path, $matches)) {
            // Extract named parameters
            foreach ($matches as $key => $value) {
                if (\is_string($key)) {
                    $params[$key] = $value;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Invoke a handler with automatic path parameter injection.
     *
     * Inspects the callable's parameters and automatically injects path parameters
     * matching the parameter names, along with the Context as the first parameter.
     * Automatically casts route parameters to the expected type (int, float, bool, string).
     *
     * @param array<string, string> $routeParams Available route parameters
     */
    private function invokeHandlerWithParams(callable $handler, Context $context, array $routeParams): void {
        try {
            $reflection = new \ReflectionFunction(\Closure::fromCallable($handler));
        } catch (\ReflectionException $e) {
            // Fallback: just call with context
            $handler($context);

            return;
        }

        $parameters = $reflection->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();

            // First parameter should be Context (or if it's type-hinted as Context)
            if ($paramType instanceof \ReflectionNamedType && $paramType->getName() === Context::class) {
                $args[] = $context;

                continue;
            }

            // Check if this parameter name matches a route parameter
            if (isset($routeParams[$paramName])) {
                $value = $routeParams[$paramName];

                // Cast to the expected type if type hint is present
                if ($paramType instanceof \ReflectionNamedType && !$paramType->isBuiltin()) {
                    // Non-builtin type, pass as string
                    $args[] = $value;
                } elseif ($paramType instanceof \ReflectionNamedType) {
                    $args[] = $this->castToType($value, $paramType->getName());
                } else {
                    // No type hint, pass as string
                    $args[] = $value;
                }

                continue;
            }

            // If parameter has default value, use it
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }

            // If parameter is optional (nullable), pass null
            if ($param->allowsNull()) {
                $args[] = null;

                continue;
            }

            // Otherwise, pass empty string for missing parameters
            $args[] = '';
        }

        $handler(...$args);
    }

    /**
     * Cast a string value to the specified type.
     *
     * @param string $value The string value to cast
     * @param string $type  The target type (int, float, bool, string)
     *
     * @return mixed The casted value
     */
    private function castToType(string $value, string $type): mixed {
        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /**
     * Generate random ID.
     */
    private function generateId(): string {
        return bin2hex(random_bytes(8));
    }

    /**
     * Generate unique client ID.
     */
    private function generateClientId(): string {
        return bin2hex(random_bytes(4)); // 8 char hex
    }

    /**
     * Generate SVG identicon based on client ID.
     * Creates a 5x5 symmetric pattern.
     */
    private function generateIdenticon(string $clientId): string {
        // Use client ID to seed colors and pattern
        $hash = hash('sha256', $clientId);

        // Extract color from hash
        $hue = hexdec(substr($hash, 0, 2)) / 255 * 360;
        $color = "hsl({$hue}, 70%, 50%)";
        $bgColor = "hsl({$hue}, 70%, 90%)";

        // Generate 5x5 pattern (symmetric, so only need 3 columns)
        $size = 5;
        $cells = [];
        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < 3; ++$x) {
                $index = $y * 3 + $x;
                $cells[$y][$x] = (bool) (hexdec($hash[$index % 64]) % 2);
            }
        }

        // Build SVG
        $cellSize = 20;
        $svgSize = $size * $cellSize;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $svgSize . '" height="' . $svgSize . '" viewBox="0 0 ' . $svgSize . ' ' . $svgSize . '">';
        $svg .= '<rect width="' . $svgSize . '" height="' . $svgSize . '" fill="' . $bgColor . '"/>';

        for ($y = 0; $y < $size; ++$y) {
            for ($x = 0; $x < $size; ++$x) {
                // Mirror pattern
                $cellX = $x < 3 ? $x : 4 - $x;
                if ($cells[$y][$cellX]) {
                    $posX = $x * $cellSize;
                    $posY = $y * $cellSize;
                    $svg .= '<rect x="' . $posX . '" y="' . $posY . '" width="' . $cellSize . '" height="' . $cellSize . '" fill="' . $color . '"/>';
                }
            }
        }

        $svg .= '</svg>';

        // Return base64 data URI
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Add custom Twig functions for Via.
     */
    private function addTwigFunctions(): void {
        // Add signal binding function
        $this->twig->addFunction(new TwigFunction('bind', fn (Signal $signal) => new Markup($signal->bind(), 'html')));
    }
}
