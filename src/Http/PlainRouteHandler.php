<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Core\RequestSession;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Http\Adapter\PsrRequestFactory;
use Mbolli\PhpVia\Http\Adapter\PsrResponseEmitter;
use Mbolli\PhpVia\Http\Middleware\MiddlewareDispatcher;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the plain routes of Via::route(): a PSR-15 handler behind the global and route middleware,
 * with no context, shell or template.
 *
 * @internal used by RequestHandler
 */
final class PlainRouteHandler {
    public function __construct(
        private Via $via,
        private PsrRequestFactory $requestFactory,
        private PsrResponseEmitter $responseEmitter,
    ) {}

    /**
     * The plain route for $method on $path, first registered first. HEAD finds a GET route, and a route
     * registered for '*' takes any method its path has no route of its own for.
     *
     * @param array<string, string> $params  the matched route's path parameters
     * @param list<string>          $allowed the methods the routes on $path take, when none takes $method
     *
     * @return null|array{RequestHandlerInterface, RouteDefinition}
     */
    public function find(string $method, string $path, array &$params, array &$allowed): ?array {
        $method = strtoupper($method);
        $allowed = [];
        $router = $this->via->getRouter();

        foreach ($this->via->getPlainRoutes() as $pattern => $byMethod) {
            $matched = [];
            if (!$router->matchesRoute($pattern, $path, $matched)) {
                continue;
            }
            $route = $byMethod[$method] ?? ($method === 'HEAD' ? ($byMethod['GET'] ?? null) : null) ?? $byMethod['*'] ?? null;
            if ($route !== null) {
                $params = $matched;
                $allowed = [];

                return $route;
            }
            array_push($allowed, ...array_keys($byMethod));
            if (isset($byMethod['GET'])) {
                $allowed[] = 'HEAD';
            }
        }
        $allowed = array_values(array_unique($allowed));

        return null;
    }

    /**
     * Run the route's middleware and handler, and send the response they return. A HEAD that a GET route
     * takes reaches them as GET, and its response goes out without the body.
     *
     * @param array<string, string> $params path parameters, each passed as a request attribute
     *
     * @return int the status sent, 500 for a response body that throws
     */
    public function serve(Request $request, Response $response, RequestHandlerInterface $handler, RouteDefinition $definition, array $params): int {
        $session = $this->via->getRequestSession($request);
        $psrRequest = $this->requestFactory->create($request, 'route')->withAttribute('via.session', $session->key)->withAttribute(RequestSession::class, $session);
        $head = $psrRequest->getMethod() === 'HEAD';
        $byMethod = $this->via->getPlainRoutes()[$definition->getRoute()] ?? [];
        if ($head && !isset($byMethod['HEAD']) && isset($byMethod['GET'])) {
            $psrRequest = $psrRequest->withMethod('GET');
        }
        foreach ($params as $name => $value) {
            $psrRequest = $psrRequest->withAttribute($name, $value);
        }

        try {
            $stack = [...$this->via->getGlobalMiddleware(), ...$definition->getMiddleware()];
            $psrResponse = (new MiddlewareDispatcher($stack, $handler))->handle($psrRequest);
        } catch (\Throwable $e) {
            return $this->fail($response, $definition, $e, 'Route handler exception on ');
        }

        $this->via->writeSessionCookie($request, $response);

        try {
            $this->responseEmitter->emit($psrResponse, $response, withoutBody: $head);
        } catch (\Throwable $e) {
            return $this->fail($response, $definition, $e, 'Route response body failed on ');
        }

        return $psrResponse->getStatusCode();
    }

    /**
     * Log and report a throw, and answer 500 unless the response has gone out already, which the emitter closed.
     */
    private function fail(Response $response, RouteDefinition $definition, \Throwable $e, string $what): int {
        $this->via->log('error', $what . $definition->getRoute() . ': ' . Logger::describe($e));
        if ($response->isWritable()) {
            $response->status(500);
            $response->end('Internal Server Error');
        }
        $this->via->reportError($e, null, ErrorPhase::Route, $definition->getRoute());

        return 500;
    }
}
