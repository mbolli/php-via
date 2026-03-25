<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\RouteDefinition;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/*
 * RouteDefinition Tests
 *
 * Tests route definition and fluent middleware registration.
 */

describe('RouteDefinition', function (): void {
    test('stores route and handler', function (): void {
        $handler = fn () => null;
        $def = new RouteDefinition('/test', $handler);

        expect($def->getRoute())->toBe('/test');
        expect($def->getHandler())->toBe($handler);
    });

    test('starts with empty middleware', function (): void {
        $def = new RouteDefinition('/', fn () => null);

        expect($def->getMiddleware())->toBe([]);
    });

    test('accepts middleware via fluent API', function (): void {
        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $def = (new RouteDefinition('/', fn () => null))->middleware($mw);

        expect($def->getMiddleware())->toHaveCount(1);
        expect($def->getMiddleware()[0])->toBe($mw);
    });

    test('accepts multiple middleware in single call', function (): void {
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

        $def = (new RouteDefinition('/', fn () => null))->middleware($mw1, $mw2);

        expect($def->getMiddleware())->toHaveCount(2);
    });

    test('middleware() is chainable', function (): void {
        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $def = new RouteDefinition('/', fn () => null);
        $result = $def->middleware($mw);

        expect($result)->toBe($def);
    });

    test('chained calls accumulate middleware in order', function (): void {
        $mw1 = new class implements MiddlewareInterface {
            public string $name = 'first';

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $mw2 = new class implements MiddlewareInterface {
            public string $name = 'second';

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $def = (new RouteDefinition('/', fn () => null))
            ->middleware($mw1)
            ->middleware($mw2)
        ;

        expect($def->getMiddleware())->toHaveCount(2);
        expect($def->getMiddleware()[0]->name)->toBe('first');
        expect($def->getMiddleware()[1]->name)->toBe('second');
    });
});
