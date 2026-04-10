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
