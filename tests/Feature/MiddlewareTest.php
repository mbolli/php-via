<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\Middleware\SseAwareMiddleware;
use Mbolli\PhpVia\Http\RouteDefinition;
use Mbolli\PhpVia\Via;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/*
 * Middleware Registration Tests
 *
 * Tests the Via-level middleware API: global registration, per-route
 * registration via RouteDefinition, and SSE-aware middleware filtering.
 */

describe('Via::middleware() — global middleware', function (): void {
    test('starts with no global middleware', function (): void {
        $via = createVia();

        expect($via->getGlobalMiddleware())->toBe([]);
    });

    test('registers a single global middleware', function (): void {
        $via = createVia();
        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $via->middleware($mw);

        expect($via->getGlobalMiddleware())->toHaveCount(1);
        expect($via->getGlobalMiddleware()[0])->toBe($mw);
    });

    test('registers multiple global middleware in order', function (): void {
        $via = createVia();
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

        $via->middleware($mw1, $mw2);

        expect($via->getGlobalMiddleware())->toHaveCount(2);
        expect($via->getGlobalMiddleware()[0]->name)->toBe('first');
        expect($via->getGlobalMiddleware()[1]->name)->toBe('second');
    });

    test('subsequent middleware() calls accumulate', function (): void {
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

        $via->middleware($mw1);
        $via->middleware($mw2);

        expect($via->getGlobalMiddleware())->toHaveCount(2);
    });
});

describe('Via::page() — returns RouteDefinition', function (): void {
    test('returns a RouteDefinition instance', function (): void {
        $via = createVia();

        $result = $via->page('/', fn (Context $c) => null);

        expect($result)->toBeInstanceOf(RouteDefinition::class);
    });

    test('returned definition has correct route', function (): void {
        $via = createVia();

        $def = $via->page('/test', fn (Context $c) => null);

        expect($def->getRoute())->toBe('/test');
    });

    test('supports fluent middleware registration', function (): void {
        $via = createVia();
        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $via->page('/admin', fn (Context $c) => null)->middleware($mw);

        expect($via->getRouteMiddleware('/admin'))->toHaveCount(1);
    });
});

describe('Via::getRouteMiddleware() — per-route middleware', function (): void {
    test('returns empty array for route without middleware', function (): void {
        $via = createVia();
        $via->page('/', fn (Context $c) => null);

        expect($via->getRouteMiddleware('/'))->toBe([]);
    });

    test('returns empty array for unknown route', function (): void {
        $via = createVia();

        expect($via->getRouteMiddleware('/does-not-exist'))->toBe([]);
    });

    test('returns middleware registered on route', function (): void {
        $via = createVia();
        $mw1 = new class implements MiddlewareInterface {
            public string $name = 'auth';

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $mw2 = new class implements MiddlewareInterface {
            public string $name = 'logging';

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $via->page('/admin', fn (Context $c) => null)->middleware($mw1, $mw2);

        $routeMiddleware = $via->getRouteMiddleware('/admin');
        expect($routeMiddleware)->toHaveCount(2);
        expect($routeMiddleware[0]->name)->toBe('auth');
        expect($routeMiddleware[1]->name)->toBe('logging');
    });

    test('route middleware is independent per route', function (): void {
        $via = createVia();
        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $via->page('/admin', fn (Context $c) => null)->middleware($mw);
        $via->page('/public', fn (Context $c) => null);

        expect($via->getRouteMiddleware('/admin'))->toHaveCount(1);
        expect($via->getRouteMiddleware('/public'))->toBe([]);
    });
});

describe('SseAwareMiddleware filtering', function (): void {
    test('regular middleware does not implement SseAwareMiddleware', function (): void {
        $mw = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        expect($mw)->not->toBeInstanceOf(SseAwareMiddleware::class);
    });

    test('SseAwareMiddleware is recognized as such', function (): void {
        $mw = new class implements SseAwareMiddleware {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        expect($mw)->toBeInstanceOf(SseAwareMiddleware::class);
        expect($mw)->toBeInstanceOf(MiddlewareInterface::class);
    });

    test('filtering separates SSE-aware from regular middleware', function (): void {
        $regular = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };
        $sseAware = new class implements SseAwareMiddleware {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $all = [$regular, $sseAware];
        $sseOnly = array_values(array_filter($all, fn ($mw) => $mw instanceof SseAwareMiddleware));

        expect($sseOnly)->toHaveCount(1);
        expect($sseOnly[0])->toBe($sseAware);
    });
});
