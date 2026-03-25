<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Represents a registered route with its handler and optional middleware.
 *
 * Returned by Via::page() to support fluent middleware registration:
 *
 * ```php
 * $app->page('/admin', fn(Context $c) => ...)->middleware(new AuthMiddleware());
 * ```
 */
class RouteDefinition {
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    private string $route;

    /** @var callable */
    private $handler;

    /**
     * @param string   $route   Route pattern (e.g. '/users/{id}')
     * @param callable $handler Page handler function
     */
    public function __construct(string $route, callable $handler) {
        $this->route = $route;
        $this->handler = $handler;
    }

    /**
     * Add middleware to this route.
     *
     * Middleware is executed in registration order (first registered = outermost).
     */
    public function middleware(MiddlewareInterface ...$middleware): self {
        foreach ($middleware as $mw) {
            $this->middleware[] = $mw;
        }

        return $this;
    }

    public function getRoute(): string {
        return $this->route;
    }

    public function getHandler(): callable {
        return $this->handler;
    }

    /**
     * @return list<MiddlewareInterface>
     */
    public function getMiddleware(): array {
        return $this->middleware;
    }
}
