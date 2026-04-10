<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Psr\Http\Server\MiddlewareInterface;

/**
 * A group of route definitions that share middleware.
 *
 * Returned by Via::group() to support fluent shared middleware registration:
 *
 * ```php
 * $app->group(function (Via $app): void {
 *     $app->page('/admin', fn(Context $c) => ...);
 *     $app->page('/admin/users', fn(Context $c) => ...);
 * })->middleware(new AuthMiddleware());
 * ```
 */
class RouteGroup {
    /** @param list<RouteDefinition> $definitions */
    public function __construct(private readonly array $definitions) {}

    /**
     * Add middleware to every route in this group.
     *
     * Middleware is applied in registration order (first = outermost).
     * Calling middleware() multiple times accumulates on all routes in the group.
     */
    public function middleware(MiddlewareInterface ...$middleware): self {
        foreach ($this->definitions as $definition) {
            $definition->middleware(...$middleware);
        }

        return $this;
    }

    /** @return list<RouteDefinition> */
    public function getDefinitions(): array {
        return $this->definitions;
    }
}
