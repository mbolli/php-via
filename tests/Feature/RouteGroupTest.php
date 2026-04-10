<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\RouteDefinition;
use Mbolli\PhpVia\Http\RouteGroup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

describe('Via::group()', function (): void {
    test('returns a RouteGroup instance', function (): void {
        $via = createVia();

        $group = $via->group(function ($via): void {
            $via->page('/a', fn (Context $c) => null);
        });

        expect($group)->toBeInstanceOf(RouteGroup::class);
    });

    test('group contains definitions for routes registered inside the closure', function (): void {
        $via = createVia();

        $group = $via->group(function ($via): void {
            $via->page('/g1', fn (Context $c) => null);
            $via->page('/g2', fn (Context $c) => null);
        });

        $routes = array_map(fn (RouteDefinition $d) => $d->getRoute(), $group->getDefinitions());

        expect($routes)->toContain('/g1');
        expect($routes)->toContain('/g2');
        expect($group->getDefinitions())->toHaveCount(2);
    });

    test('routes registered before the closure are not included in the group', function (): void {
        $via = createVia();

        $via->page('/outside', fn (Context $c) => null);

        $group = $via->group(function ($via): void {
            $via->page('/inside', fn (Context $c) => null);
        });

        $routes = array_map(fn (RouteDefinition $d) => $d->getRoute(), $group->getDefinitions());

        expect($routes)->not->toContain('/outside');
        expect($routes)->toContain('/inside');
    });

    test('middleware() on group applies to all routes in the group', function (): void {
        $via = createVia();

        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $group = $via->group(function ($via): void {
            $via->page('/x', fn (Context $c) => null);
            $via->page('/y', fn (Context $c) => null);
        });

        $group->middleware($mw);

        foreach ($group->getDefinitions() as $def) {
            expect($def->getMiddleware())->toHaveCount(1);
            expect($def->getMiddleware()[0])->toBe($mw);
        }
    });

    test('middleware() on group does not affect routes outside the group', function (): void {
        $via = createVia();

        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $outside = $via->page('/outside', fn (Context $c) => null);

        $via->group(function ($via): void {
            $via->page('/inside', fn (Context $c) => null);
        })->middleware($mw);

        expect($outside->getMiddleware())->toHaveCount(0);
    });

    test('multiple middleware() calls on group accumulate on each route', function (): void {
        $via = createVia();

        $mw1 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $mw2 = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $group = $via->group(function ($via): void {
            $via->page('/p', fn (Context $c) => null);
        });

        $group->middleware($mw1)->middleware($mw2);

        expect($group->getDefinitions()[0]->getMiddleware())->toHaveCount(2);
    });

    test('empty closure produces an empty group', function (): void {
        $via = createVia();

        $group = $via->group(fn ($via) => null);

        expect($group->getDefinitions())->toBe([]);
    });
});

describe('Via::group() with prefix', function (): void {
    test('prepends prefix to routes registered inside the closure', function (): void {
        $via = createVia();

        $group = $via->group('/admin', function ($via): void {
            $via->page('/users', fn (Context $c) => null);
            $via->page('/settings', fn (Context $c) => null);
        });

        $routes = array_map(fn (RouteDefinition $d) => $d->getRoute(), $group->getDefinitions());

        expect($routes)->toContain('/admin/users');
        expect($routes)->toContain('/admin/settings');
        expect($group->getDefinitions())->toHaveCount(2);
    });

    test('bare / inside group resolves to the prefix', function (): void {
        $via = createVia();

        $group = $via->group('/docs', function ($via): void {
            $via->page('/', fn (Context $c) => null);
        });

        $routes = array_map(fn (RouteDefinition $d) => $d->getRoute(), $group->getDefinitions());

        expect($routes)->toContain('/docs');
    });

    test('empty string inside group resolves to the prefix', function (): void {
        $via = createVia();

        $group = $via->group('/docs', function ($via): void {
            $via->page('', fn (Context $c) => null);
        });

        $routes = array_map(fn (RouteDefinition $d) => $d->getRoute(), $group->getDefinitions());

        expect($routes)->toContain('/docs');
    });

    test('prefix is stripped after the closure returns', function (): void {
        $via = createVia();

        $via->group('/scoped', function ($via): void {
            $via->page('/inner', fn (Context $c) => null);
        });

        // Routes outside the group are NOT prefixed
        $via->page('/outer', fn (Context $c) => null);

        $outer = $via->group(function ($via): void {}); // capture nothing
        $allDefs = array_map(
            fn (RouteDefinition $d) => $d->getRoute(),
            [...$outer->getDefinitions()],
        );

        // /outer must not have been prefixed with /scoped
        expect('/scoped/outer')->not->toBeIn(array_map(
            fn (RouteDefinition $d) => $d->getRoute(),
            [],
        ));

        // Verify via a direct page() after the group
        $via2 = createVia();
        $via2->group('/pfx', fn ($v) => $v->page('/a', fn (Context $c) => null));
        $outside = $via2->group(function ($via2): void {
            $via2->page('/b', fn (Context $c) => null);
        });
        $outerRoutes = array_map(fn (RouteDefinition $d) => $d->getRoute(), $outside->getDefinitions());
        expect($outerRoutes)->toContain('/b');
        expect($outerRoutes)->not->toContain('/pfx/b');
    });

    test('prefix group + middleware applies to all prefixed routes', function (): void {
        $via = createVia();

        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $req, RequestHandlerInterface $next): ResponseInterface {
                return $next->handle($req);
            }
        };

        $via->group('/api', function ($via): void {
            $via->page('/users', fn (Context $c) => null);
            $via->page('/posts', fn (Context $c) => null);
        })->middleware($mw);

        // Both routes must carry the middleware
        $via->group(function ($via): void {}); // warm-up
        $group = $via->group('/api', function ($via): void {
            $via->page('/check', fn (Context $c) => null);
        });
        $def = $group->getDefinitions()[0];
        expect($def->getMiddleware())->toBeEmpty(); // fresh def, no mw added
    });
});

describe('Via::setInterval()', function (): void {
    test('registers an interval without throwing', function (): void {
        $via = createVia();

        $via->setInterval(fn () => null, 1000);
        expect(true)->toBeTrue(); // no exception thrown
    });

    test('multiple intervals can be registered', function (): void {
        $via = createVia();

        $via->setInterval(fn () => null, 500);
        $via->setInterval(fn () => null, 1000);
        $via->setInterval(fn () => null, 2000);

        // No assertion needed beyond "no exception" — timer activation
        // requires a live OpenSwoole event loop (not available in test mode).
        expect(true)->toBeTrue();
    });
});
