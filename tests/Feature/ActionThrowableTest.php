<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\MessageBroker;
use Mbolli\PhpVia\Broker\NodeIdentity;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\Application;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Rendering\ViewCache;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Timer;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/*
 * A throwable that escapes a request coroutine, a timer or a broadcast kills the whole
 * worker and every context on it, so each entry point must catch \Throwable, not \Exception.
 */

/**
 * @return array{0: string, 1: FakeStaticResponse} captured log output and the response
 */
function postThrowingAction(Throwable $thrown): array {
    $via = createVia();
    $ctx = new Context('ctx-throw', '/throw', $via);
    $via->contexts['ctx-throw'] = $ctx;
    $action = $ctx->action(function () use ($thrown): void {
        throw $thrown;
    }, 'boom');

    $response = new FakeStaticResponse();
    ob_start();

    try {
        (new ActionHandler($via))->handleAction(new FakeActionRequest($action->id(), ['via_ctx' => 'ctx-throw']), $response, $action->id());
    } finally {
        $log = (string) ob_get_clean();
    }

    return [$log, $response];
}

/**
 * @return array{0: string, 1: FakeStaticResponse} captured log output and the response
 */
function getThroughRequestHandler(Via $via, string $path): array {
    // start() hands the routes over; there is no server here.
    $handler = (new ReflectionProperty(Via::class, 'requestHandler'))->getValue($via);
    assert($handler instanceof RequestHandler);
    $handler->setRoutes($via->getRouter()->getRoutes());

    $request = new Request();
    $request->server = ['request_uri' => $path, 'request_method' => 'GET'];
    $request->header = [];
    $response = new FakeStaticResponse();

    ob_start();

    try {
        $handler->handleRequest($request, $response);
    } finally {
        $log = (string) ob_get_clean();
    }

    return [$log, $response];
}

describe('actions', function (): void {
    test('an action throwing an Exception answers 500', function (): void {
        [$log, $response] = postThrowingAction(new RuntimeException('plain exception'));

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Action failed');
        expect($log)->toContain('RuntimeException: plain exception at ' . __FILE__ . ':');
    });

    test('an action throwing an Error answers 500 instead of escaping', function (Throwable $thrown, string $class): void {
        [$log, $response] = postThrowingAction($thrown);

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Action failed');
        expect($log)->toContain("{$class}: ");
    })->with([
        'TypeError' => [new TypeError('wrong type'), 'TypeError'],
        'ValueError' => [new ValueError('bad value'), 'ValueError'],
    ]);

    test('a real TypeError from an action closure answers 500', function (): void {
        $via = createVia();
        $ctx = new Context('ctx-type', '/throw', $via);
        $via->contexts['ctx-type'] = $ctx;
        $action = $ctx->action(function (): void {
            strlen([]); // @phpstan-ignore argument.type
        }, 'strlen');

        $response = new FakeStaticResponse();
        ob_start();
        (new ActionHandler($via))->handleAction(new FakeActionRequest($action->id(), ['via_ctx' => 'ctx-type']), $response, $action->id());
        $log = (string) ob_get_clean();

        expect($response->statusCode)->toBe(500);
        expect($log)->toContain('TypeError: strlen()');
    });
});

describe('page render', function (): void {
    test('a view that throws on the initial render answers 500 and leaves no context behind', function (): void {
        $via = createVia();
        $renders = 0;
        $via->page('/viewthrow', function (Context $c) use (&$renders): void {
            $c->scope(Scope::ROUTE);
            $c->view(function () use (&$renders): string {
                ++$renders;

                throw new RuntimeException('view failed');
            });
        });

        for ($i = 0; $i < 3; ++$i) {
            [$log, $response] = getThroughRequestHandler($via, '/viewthrow');
        }

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Internal Server Error');
        expect($log)->toContain('Page render exception on /viewthrow: RuntimeException: view failed');
        expect($via->contexts)->toBe([]);
        expect($via->contextSessions)->toBe([]);
        expect($via->getApp()->getAllContexts())->toBe([]);
        expect($via->getContextsByScope(Scope::routeScope('/viewthrow')))->toBe([]);

        $renders = 0;
        ob_start();
        $via->broadcast(Scope::routeScope('/viewthrow'));
        ob_end_clean();
        expect($renders)->toBe(0);
    });

    test('a page handler that throws clears the timers and scopes it registered', function (): void {
        $via = createVia();
        $timerId = null;
        $via->page('/handlerthrow', function (Context $c) use (&$timerId): void {
            $c->scope('room:doomed');
            $timerId = $c->setInterval(function (): void {}, 60_000);

            throw new RuntimeException('handler failed');
        });

        try {
            [$log, $response] = getThroughRequestHandler($via, '/handlerthrow');
            $alive = Timer::exists($timerId);
        } finally {
            if ($timerId !== null) {
                Timer::clear($timerId);
            }
        }

        expect($response->statusCode)->toBe(500);
        expect($log)->toContain('Page handler exception on /handlerthrow: RuntimeException: handler failed');
        expect($alive)->toBeFalse();
        expect($via->contextSessions)->toBe([]);
        expect($via->getContextsByScope('room:doomed'))->toBe([]);
    });
});

describe('request guard', function (): void {
    test('a throw outside the page and action guards answers 500', function (): void {
        $via = createVia();
        $via->notFound(function (): void {
            throw new LogicException('not found handler failed');
        });

        [$log, $response] = getThroughRequestHandler($via, '/missing');

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Internal Server Error');
        expect($log)->toContain('Unhandled exception on /missing: LogicException: not found handler failed at ' . __FILE__ . ':');
    });
});

describe('broadcast fan-out', function (): void {
    test('a context whose view throws does not stop the others from getting the frame', function (string $route, string $scope, string $broadcast): void {
        $via = createVia();
        $count = 0;

        $broken = new Context('ctx-broken', $route, $via);
        $healthy = new Context('ctx-healthy', $route, $via);
        foreach ([$broken, $healthy] as $ctx) {
            $ctx->scope($scope);
            $via->contexts[$ctx->getId()] = $ctx;
        }
        $broken->view(function () use (&$count): string {
            if ($count > 0) {
                throw new RuntimeException('broken view');
            }

            return '<div id="a">ok</div>';
        });
        $healthy->view(function () use (&$count): string {
            return '<div id="b">Count: ' . $count . '</div>';
        });
        $broken->renderView();
        $healthy->renderView();

        $count = 1;
        ob_start();
        $via->broadcast($broadcast);
        $log = (string) ob_get_clean();

        $patch = $healthy->getPatch();
        expect($patch)->not->toBeNull();
        expect($patch['content'])->toContain('Count: 1');
        expect($log)->toContain("[ctx-broken] Sync failed during broadcast of {$broadcast} for 1 context(s): RuntimeException: broken view");
    })->with([
        'global' => ['/a', Scope::GLOBAL, Scope::GLOBAL],
        'route' => ['/r', Scope::ROUTE, Scope::routeScope('/r')],
        'custom' => ['/c', 'room:lobby', 'room:lobby'],
    ]);

    test('contexts failing the same way log one line per broadcast', function (): void {
        $via = createVia();
        for ($i = 0; $i < 5; ++$i) {
            $ctx = new Context("ctx-{$i}", '/many', $via);
            $ctx->scope(Scope::ROUTE);
            $ctx->view(function (): string {
                throw new RuntimeException('same failure');
            });
            $via->contexts[$ctx->getId()] = $ctx;
        }

        ob_start();
        $via->broadcast(Scope::routeScope('/many'));
        $log = (string) ob_get_clean();

        expect(substr_count($log, 'Sync failed during broadcast'))->toBe(1);
        expect($log)->toContain('Sync failed during broadcast of route:/many for 5 context(s): RuntimeException: same failure');
    });

    test('a broker message whose fan-out throws is logged instead of escaping the receive loop', function (): void {
        $broker = new class implements MessageBroker {
            use NodeIdentity;

            /** @var null|callable(string): void */
            public $handler;

            public function connect(): void {}

            public function disconnect(): void {}

            public function publish(string $scope): void {}

            public function subscribe(callable $handler): void {
                $this->handler = $handler;
            }

            public function isConnected(): bool {
                return true;
            }
        };
        $via = createVia((new Config())->withBroker($broker));
        (new ReflectionProperty(Via::class, 'viewCache'))->setValue($via, new class extends ViewCache {
            public function invalidate(string $scope): void {
                throw new RuntimeException('cache failed');
            }
        });

        ob_start();
        ($broker->handler)(Scope::GLOBAL);
        $log = (string) ob_get_clean();

        expect($log)->toContain('Broker sync failed for scope "global": RuntimeException: cache failed at ' . __FILE__ . ':');
    });
});

describe('SSE initial sync', function (): void {
    test('a stream whose first sync throws answers 500, skips the client callbacks and still schedules cleanup', function (): void {
        $via = createVia();
        $ctx = new Context('ctx-sse', '/sse', $via);
        $via->contexts['ctx-sse'] = $ctx;
        $ctx->view(function (): string {
            throw new RuntimeException('sync failed');
        });

        $events = [];
        $via->onClientConnect(function () use (&$events): void {
            $events[] = 'connect';
        });
        $via->onClientDisconnect(function () use (&$events): void {
            $events[] = 'disconnect';
        });

        $request = new FakeActionRequest('unused', ['via_ctx' => 'ctx-sse']);
        $request->server = ['request_uri' => '/_sse', 'request_method' => 'GET'];
        $response = new FakeStaticResponse();

        $handler = new SseHandler($via);
        ob_start();

        try {
            $handler->handleSSE($request, $response);
        } finally {
            $log = (string) ob_get_clean();
            $timers = (new ReflectionProperty(Application::class, 'cleanupTimers'))->getValue($via->getApp());
            $via->getApp()->cancelContextCleanup('ctx-sse');
        }

        expect($response->statusCode)->toBe(500);
        expect($response->ended)->toBeTrue();
        expect($events)->toBe([]);
        expect($via->activeSseCount)->not->toHaveKey('ctx-sse');
        expect((new ReflectionProperty(SseHandler::class, 'streams'))->getValue($handler))->toBe([]);
        expect($timers)->toHaveKey('ctx-sse');
        expect($log)->toContain('Initial SSE sync failed: RuntimeException: sync failed');
    });
});

describe('SSE loop', function (): void {
    test('a throw inside the stream loop still releases the stream and schedules cleanup', function (): void {
        $via = createVia();
        $ctx = new class('ctx-loop', '/loop', $via) extends Context {
            public function getPatch(): ?array {
                throw new LogicException('loop failed');
            }
        };
        $via->contexts['ctx-loop'] = $ctx;
        $ctx->view(fn (): string => '<div id="ok">ok</div>');

        $events = [];
        $via->onClientDisconnect(function () use (&$events): void {
            $events[] = 'disconnect';
        });

        $request = new FakeActionRequest('unused', ['via_ctx' => 'ctx-loop']);
        $request->server = ['request_uri' => '/_sse', 'request_method' => 'GET'];
        $handler = new SseHandler($via);
        ob_start();

        try {
            $handler->handleSSE($request, new FakeStaticResponse());
        } catch (LogicException) {
            // The request guard in RequestHandler::handleRequest() answers this one.
        } finally {
            ob_end_clean();
            $timers = (new ReflectionProperty(Application::class, 'cleanupTimers'))->getValue($via->getApp());
            $via->getApp()->cancelContextCleanup('ctx-loop');
        }

        expect($via->activeSseCount)->not->toHaveKey('ctx-loop');
        expect((new ReflectionProperty(SseHandler::class, 'streams'))->getValue($handler))->toBe([]);
        expect((new ReflectionProperty(SseHandler::class, 'streamsByFd'))->getValue($handler))->toBe([]);
        expect($events)->toBe(['disconnect']);
        expect($timers)->toHaveKey('ctx-loop');
    });
});

describe('per-tab interval', function (): void {
    test('a throwing Context::setInterval() callback is logged and the timer keeps running', function (): void {
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Fixtures/tab_interval_throw.php') . ' 2>&1', $output, $exit);
        $out = implode("\n", $output);

        expect($exit)->toBe(0, $out);
        expect($out)->toContain('Interval callback failed: RuntimeException: tab interval failed');
        expect($out)->toContain('ticks=3');
    });
});
