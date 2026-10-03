<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

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
     * The plain route for $method on $path, first registered first. HEAD finds a GET route.
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
            $route = $byMethod[$method] ?? ($method === 'HEAD' ? ($byMethod['GET'] ?? null) : null);
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
     * Run the route's middleware and handler, and send the response they return.
     *
     * @param array<string, string> $params path parameters, each passed as a request attribute
     *
     * @return int the status sent
     */
    public function serve(Request $request, Response $response, RequestHandlerInterface $handler, RouteDefinition $definition, array $params): int {
        $psrRequest = $this->requestFactory->create($request, 'route')->withAttribute('via.session', $this->via->getSessionId($request));
        foreach ($params as $name => $value) {
            $psrRequest = $psrRequest->withAttribute($name, $value);
        }

        try {
            $stack = [...$this->via->getGlobalMiddleware(), ...$definition->getMiddleware()];
            $psrResponse = (new MiddlewareDispatcher($stack, $handler))->handle($psrRequest);
        } catch (\Throwable $e) {
            $this->via->log('error', "Route handler exception on {$definition->getRoute()}: " . Logger::describe($e));
            $response->status(500);
            $response->end('Internal Server Error');

            return 500;
        }

        $this->responseEmitter->emit($psrResponse, $response, withoutBody: strtoupper($psrRequest->getMethod()) === 'HEAD');

        return $psrResponse->getStatusCode();
    }
}
