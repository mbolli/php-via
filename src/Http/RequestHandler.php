<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\PrometheusExporter;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/**
 * Handles incoming HTTP requests and routes them appropriately.
 */
class RequestHandler {
    /** @var array<string, callable> */
    private array $routes = [];

    private Via $via;
    private SseHandler $sseHandler;
    private ActionHandler $actionHandler;
    private ?PrometheusExporter $prometheusExporter = null;

    public function __construct(Via $via, SseHandler $sseHandler, ActionHandler $actionHandler) {
        $this->via = $via;
        $this->sseHandler = $sseHandler;
        $this->actionHandler = $actionHandler;
    }

    /**
     * Enable Prometheus metrics endpoint at /_metrics.
     */
    public function enablePrometheus(?PrometheusExporter $exporter = null): void {
        $this->prometheusExporter = $exporter ?? new PrometheusExporter();
    }

    /**
     * Get the Prometheus exporter instance.
     */
    public function getPrometheusExporter(): ?PrometheusExporter {
        return $this->prometheusExporter;
    }

    /**
     * @param array<string, callable> $routes
     */
    public function setRoutes(array $routes): void {
        $this->routes = $routes;
    }

    /**
     * Handle incoming HTTP request.
     */
    public function handleRequest(Request $request, Response $response): void {
        $path = $request->server['request_uri'];
        $method = $request->server['request_method'];

        // Detect basePath from X-Base-Path header (set by reverse proxy like Caddy)
        $basePathHeader = $request->header['x-base-path'] ?? null;
        $this->via->getApp()->getConfig()->detectBasePathFromRequest($basePathHeader);
        $this->via->getApp()->getTwig()->addGlobal('basePath', $this->via->getApp()->getConfig()->getBasePath());

        // Populate superglobals for compatibility
        $_GET = $request->get ?? [];
        $_POST = $request->post ?? [];
        $_FILES = $request->files ?? [];

        // Handle HEAD requests without logging or rendering
        if ($method === 'HEAD') {
            $this->handleHeadRequest($path, $response);

            return;
        }

        $this->via->log('debug', "Request: {$method} {$path}");

        // Serve Datastar.js
        if ($path === '/datastar.js') {
            $this->serveDatastarJs($response);

            return;
        }

        // Serve Via CSS
        if ($path === '/via.css') {
            $this->serveViaCss($response);

            return;
        }

        // Handle SSE connection
        if ($path === '/_sse') {
            $this->sseHandler->handleSSE($request, $response);

            return;
        }

        // Handle action triggers
        if (preg_match('#^/_action/(.+)$#', $path, $matches)) {
            $this->actionHandler->handleAction($request, $response, $matches[1]);

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

        // Handle Prometheus metrics endpoint
        if ($path === '/_metrics' && $method === 'GET') {
            $this->handleMetrics($request, $response);

            return;
        }

        // Handle page routes
        $params = [];
        $handler = $this->via->getRouter()->matchRoute($path, $params);
        if ($handler !== null) {
            // Extract route pattern from matched route
            foreach ($this->routes as $route => $h) {
                if ($h === $handler) {
                    $this->handlePage($request, $response, $route, $handler, $params);

                    return;
                }
            }
        }

        // 404 Not Found
        $response->status(404);
        $response->end('Not Found');
    }

    /**
     * Handle HEAD requests for route checking.
     */
    private function handleHeadRequest(string $path, Response $response): void {
        $params = [];
        $handler = $this->via->getRouter()->matchRoute($path, $params);
        if ($handler !== null) {
            $response->status(200);
            $response->header('Content-Type', 'text/html; charset=utf-8');
            $response->end();

            return;
        }
        // Route not found
        $response->status(404);
        $response->end();
    }

    /**
     * Handle page rendering.
     *
     * @param array<string, string> $params Route parameters
     */
    private function handlePage(Request $request, Response $response, string $route, callable $handler, array $params = []): void {
        // Get or create session ID
        $sessionId = $this->via->getSessionId($request);

        // Generate unique context ID
        $contextId = $route . '_/' . $this->via->generateId();

        // Create context with session ID
        $context = new Context($contextId, $route, $this->via, null, $sessionId);

        // Track session for this context
        $this->via->contextSessions[$contextId] = $sessionId;

        // Inject route parameters
        $context->injectRouteParams($params);

        // Execute the page handler with automatic parameter injection
        try {
            $this->via->invokeHandlerWithParams($handler, $context, $params);
        } catch (\Throwable $e) {
            $this->via->log(
                'error',
                'Page handler exception on ' . $route . ': ' . \get_class($e) . ': ' . $e->getMessage()
                . "\n" . $e->getTraceAsString()
            );
            $response->status(500);
            $response->end('Internal Server Error');

            return;
        }

        // Store context (in both legacy array and Application)
        $this->via->contexts[$contextId] = $context;
        $this->via->getApp()->registerContext($context);
        $this->via->getApp()->setContextSession($contextId, $sessionId);

        // Register context in its default TAB scope
        $this->via->registerContextInScope($context, Scope::TAB);

        // Build HTML document
        $html = $this->via->buildHtmlDocument($context);

        // Set session cookie
        $this->via->setSessionCookie($response, $sessionId);

        $response->header('Content-Type', 'text/html; charset=utf-8');
        $response->end($html);
    }

    /**
     * Handle session close.
     */
    private function handleSessionClose(Request $request, Response $response): void {
        $contextId = $request->rawContent();

        if (isset($this->via->contexts[$contextId])) {
            // Don't immediately delete context - delay to allow page navigation
            // If SSE reconnects within timeout, context survives; otherwise it's cleaned up
            // This prevents reload loops during navigation
            $this->via->scheduleContextCleanup($contextId);

            $this->via->log('debug', "Context cleanup scheduled: {$contextId}");
        }

        $response->status(200);
        $response->end();
    }

    /**
     * Handle stats endpoint.
     */
    private function handleStats(Request $request, Response $response): void {
        $stats = [
            'contexts' => \count($this->via->contexts),
            'clients' => $this->via->getClients(),
            'render_stats' => $this->via->getRenderStats(),
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
     * Handle Prometheus metrics endpoint.
     */
    private function handleMetrics(Request $request, Response $response): void {
        if ($this->prometheusExporter === null) {
            $response->status(404);
            $response->end('Prometheus metrics not enabled. Call enablePrometheus() first.');

            return;
        }

        // Update metrics from current state
        $this->prometheusExporter->updateFromStats($this->via->getStats());

        // Add memory metrics
        $this->prometheusExporter->gauge('memory_usage_bytes', (float) memory_get_usage(true), 'Current memory usage in bytes');
        $this->prometheusExporter->gauge('memory_peak_bytes', (float) memory_get_peak_usage(true), 'Peak memory usage in bytes');

        // Add context count
        $this->prometheusExporter->gauge('contexts_active', (float) \count($this->via->contexts), 'Currently active contexts');

        // Serve metrics
        $this->prometheusExporter->handleRequest($request, $response);
    }

    /**
     * Serve Datastar JavaScript file.
     */
    private function serveDatastarJs(Response $response): void {
        $datastarJs = file_get_contents(__DIR__ . '/../../public/datastar.js');

        $response->header('Content-Type', 'application/javascript');
        $response->end($datastarJs);
    }

    /**
     * Serve Via CSS file.
     */
    private function serveViaCss(Response $response): void {
        $viaCss = file_get_contents(__DIR__ . '/../../public/via.css');

        $response->header('Content-Type', 'text/css; charset=utf-8');
        $response->end($viaCss);
    }
}
