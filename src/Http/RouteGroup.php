<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Psr\Http\Server\MiddlewareInterface;

/**
 * A group of route definitions that share a URL prefix and/or middleware.
 *
 * Returned by Via::group() to support fluent shared middleware registration.
 * Routes in the group may all share a URL prefix (if one was given to group())
 * and/or a set of middleware:
 *
 * ```php
 * $app->group('/admin', function (Via $app): void {
 *     $app->page('/', fn(Context $c) => ...);      // → /admin
 *     $app->page('/users', fn(Context $c) => ...); // → /admin/users
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
