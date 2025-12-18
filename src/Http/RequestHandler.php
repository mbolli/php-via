<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Handles incoming HTTP requests and routes them appropriately.
 */
class RequestHandler {
    /** @var array<string, callable> */
    private array $routes = [];

    private Via $via;
    private SseHandler $sseHandler;
    private ActionHandler $actionHandler;

    public function __construct(Via $via, SseHandler $sseHandler, ActionHandler $actionHandler) {
        $this->via = $via;
        $this->sseHandler = $sseHandler;
        $this->actionHandler = $actionHandler;
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
        if ($path === '/_datastar.js') {
            $this->serveDatastarJs($response);

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
        $this->via->invokeHandlerWithParams($handler, $context, $params);

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
     * Serve Datastar JavaScript file.
     */
    private function serveDatastarJs(Response $response): void {
        $datastarJs = file_get_contents(__DIR__ . '/../../datastar.js');

        $response->header('Content-Type', 'application/javascript');
        $response->end($datastarJs);
    }
}
