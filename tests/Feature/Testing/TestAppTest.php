<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Timer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

// Testing\TestApp itself: its lifecycle, what a tab sends and how it reports a failure.

/** A page with a counter, a throwing action and a cookie, for the tests below. */
function harnessPage(Via $via): void {
    $via->page('/p', function (Context $c): void {
        $count = $c->signal(0, 'count');
        $c->action(static fn () => $count->setValue($count->int() + 1), 'bump');
        $c->action(static function () use ($count): void {
            $count->setValue(99);

            throw new RuntimeException('the disk is full');
        }, 'fail');
        $c->action(static function () use ($c): void {
            $c->setCookie('theme', 'dark');
        }, 'theme');
        $c->action(static function () use ($c, $count): void {
            $count->setValue(is_string($c->input('n')) && $c->cookie('theme') === 'dark' ? 1 : -1);
        }, 'inspect');
        $c->view(static fn (): string => '<p id="p">' . $count->int() . '</p>');
    });
}

function harnessApp(?callable $routes = null, ?Config $config = null): TestApp {
    return new TestApp(($config ?? new Config())->withLogLevel('error'), $routes ?? harnessPage(...));
}

describe('the lifecycle', function (): void {
    test('onWorkerStart callbacks run once, as worker 0, and the routes they register are served', function (): void {
        $workers = [];
        $app = harnessApp(static function (Via $via) use (&$workers): void {
            $via->onWorkerStart(static function (int $workerId) use ($via, &$workers): void {
                $workers[] = $workerId;
                harnessPage($via);
            });
        });

        expect($workers)->toBe([0])
            ->and($app->open('/p')->signal('count'))->toBe(0)
        ;
    });

    test('shutdown() ends the streams, then runs onWorkerStop while isShuttingDown(), and clears the timers', function (): void {
        $events = [];
        $timer = null;
        $app = harnessApp(static function (Via $via) use (&$events, &$timer): void {
            $via->page('/ticking', static function (Context $c) use (&$timer): void {
                $timer = $c->setInterval(static fn () => null, 60_000);
                $c->view(static fn (): string => '<p id="t">t</p>');
            });
            $via->onClientDisconnect(static function (Context $c) use (&$events): void {
                $events[] = 'disconnect';
            });
            $via->onWorkerStop(static function (int $workerId) use ($via, &$events): void {
                $events[] = 'stop ' . $workerId . ($via->isShuttingDown() ? ' shutting down' : '');
            });
        });
        $tab = $app->open('/ticking');

        expect(Timer::exists($timer))->toBeTrue();

        $app->shutdown();
        $app->shutdown();

        expect($events)->toBe(['disconnect', 'stop 0 shutting down'])
            ->and(Timer::exists($timer))->toBeFalse()
            ->and($tab->context()->isConnected())->toBeFalse()
            ->and(fn () => $tab->connect())->toThrow(LogicException::class, 'This TestApp is shut down.')
            ->and(fn () => $app->open('/ticking'))->toThrow(LogicException::class, 'This TestApp is shut down.')
        ;
    });

    test('a TestApp that goes out of scope shuts down, also with a tab connected', function (): void {
        $stopped = 0;
        (static function () use (&$stopped): void {
            $app = harnessApp(static function (Via $via) use (&$stopped): void {
                harnessPage($via);
                $via->onWorkerStop(static function () use (&$stopped): void {
                    ++$stopped;
                });
            });
            $app->open('/p')->action('bump');
        })();

        expect($stopped)->toBe(1);
    });

    test('it runs without VIA_TEST_MODE', function (): void {
        $out = (string) shell_exec(
            'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/test_app_no_test_mode.php') . ' 2>&1'
        );

        expect($out)->toBe("test_mode=0\ncount=2\nconnected=1\npatches=elements,signals,elements,signals\nstopped=1\n");
    });
});

describe('a tab', function (): void {
    test('sends its input as strings and the cookies of its browser, which other browsers do not share', function (): void {
        $app = harnessApp();
        $tab = $app->open('/p');

        $tab->action('theme')->action('inspect', ['n' => 5]);
        $sibling = $tab->open('/p');
        $sibling->action('inspect', ['n' => 5]);
        $stranger = $app->open('/p');
        $stranger->action('inspect', ['n' => 5]);

        expect($tab->signal('count'))->toBe(1)
            ->and($sibling->signal('count'))->toBe(1)
            ->and($stranger->signal('count'))->toBe(-1)
        ;
    });

    test('an action that throws fails with the error php-via logged, and its writes still reach the tab', function (): void {
        $app = harnessApp();
        $tab = $app->open('/p');
        $tab->patches();

        expect(fn () => $tab->action('fail'))->toThrow(
            RuntimeException::class,
            "Action 'fail' answered 500 Action failed\n[ERROR] Action fail failed: RuntimeException: the disk is full at ",
        )
            ->and($tab->patches())->toBe([['type' => 'signals', 'signals' => ['count' => 99]]])
            ->and($app->logs())->toHaveCount(1)
        ;
    });

    test('an unknown action, page or signal fails with what is missing', function (): void {
        $app = harnessApp();
        $tab = $app->open('/p');

        expect(fn () => $tab->action('nope'))->toThrow(RuntimeException::class, 'Action not found: nope')
            ->and(fn () => $app->open('/nope'))->toThrow(RuntimeException::class, 'GET /nope answered 404: Not Found')
            ->and(fn () => $tab->signal('cuont'))->toThrow(InvalidArgumentException::class, "The page of this tab has no signal 'cuont'. It has: count")
            ->and(fn () => $tab->action('bump', signals: ['cuont' => 1]))->toThrow(InvalidArgumentException::class, "no signal 'cuont'")
        ;
    });

    test('a page a middleware answers is no page', function (): void {
        $app = harnessApp(static function (Via $via): void {
            harnessPage($via);
            $via->middleware(new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                    return $request->getUri()->getPath() === '/p' ? new Psr7Response(200, [], 'mcp') : $handler->handle($request);
                }
            });
        });

        expect(fn () => $app->open('/p'))->toThrow(RuntimeException::class, 'GET /p answered 200 without a page');
    });

    test('goes through the middleware, which can set the CSP nonce, on page loads and actions', function (): void {
        $seen = [];
        $app = harnessApp(static function (Via $via) use (&$seen): void {
            harnessPage($via);
            $via->middleware(new class($seen) implements MiddlewareInterface {
                /** @param list<string> $seen */
                public function __construct(private array &$seen) {}

                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                    $this->seen[] = $request->getMethod() . ' ' . $request->getUri()->getPath();

                    return $handler->handle($request->withAttribute('via.csp_nonce', 'n0nce'));
                }
            });
        });
        $tab = $app->open('/p');
        $tab->action('bump');

        expect($seen)->toBe(['GET /p', 'POST /_action/bump'])
            ->and($tab->html())->toContain('nonce="n0nce"')
        ;
    });

    test('sends a trusted origin when the app trusts only some', function (): void {
        $app = harnessApp(config: (new Config())->withTrustedOrigins(['https://app.example']));
        $tab = $app->open('/p');

        $tab->action('bump');

        expect($tab->signal('count'))->toBe(1);
    });

    test('holds the patches of a disconnected tab for its next connect', function (): void {
        $app = harnessApp();
        $tab = $app->open('/p', connect: false);

        expect($tab->context()->isConnected())->toBeFalse();

        $tab->action('bump')->disconnect();

        expect($tab->patches())->toBe([])
            ->and($tab->signal('count'))->toBe(0)
        ;

        $tab->connect();

        expect($tab->context()->isConnected())->toBeTrue()
            ->and($tab->patches()[0])->toBe(['type' => 'signals', 'signals' => ['count' => 1]])
            ->and($tab->signal('count'))->toBe(1)
            ->and(fn () => $tab->connect())->toThrow(LogicException::class, 'This tab is connected already')
        ;

        $tab->disconnect();
        $tab->action('bump');
        $tab->connect();

        expect($tab->patches()[0])->toBe(['type' => 'signals', 'signals' => ['count' => 2]]);
    });

    test('a connect whose initial sync throws fails, and leaves the tab disconnected', function (): void {
        $broken = false;
        $app = harnessApp(static function (Via $via) use (&$broken): void {
            $via->page('/fragile', static function (Context $c) use (&$broken): void {
                $c->view(static function () use (&$broken): string {
                    return $broken ? throw new RuntimeException('render failed') : '<p id="f">ok</p>';
                });
            });
        });
        $tab = $app->open('/fragile', connect: false);
        $broken = true;

        expect(fn () => $tab->connect())->toThrow(RuntimeException::class, 'The SSE connect answered 500')
            ->and($tab->context()->isConnected())->toBeFalse()
        ;

        $broken = false;
        $tab->connect();

        expect($tab->context()->isConnected())->toBeTrue();
    });
});

describe('what the app records', function (): void {
    test('broadcasts() lists the scopes broadcast since the last call, in call order', function (): void {
        $app = harnessApp(static function (Via $via): void {
            $via->page('/room', static function (Context $c) use ($via): void {
                $c->action(static function () use ($via): void {
                    $via->broadcast('room:lobby');
                    $via->broadcast(Scope::GLOBAL);
                }, 'shout');
                $c->action(static fn () => $c->broadcast(), 'self');
                $c->view(static fn (): string => '<p id="r">r</p>');
            });
        });
        $tab = $app->open('/room');

        $tab->action('shout')->action('self');
        $app->via()->broadcast('room:other');

        expect($app->broadcasts())->toBe(['room:lobby', Scope::GLOBAL, 'room:other'])
            ->and($app->broadcasts())->toBe([])
            ->and(fn () => $app->via()->broadcast(Scope::TAB))->toThrow(InvalidArgumentException::class)
            ->and($app->broadcasts())->toBe([])
        ;
    });

    test('logs() has what php-via logged during the requests, and nothing is printed', function (): void {
        $app = new TestApp((new Config())->withLogLevel('warn'), static function (Via $via): void {
            $via->page('/chatty', static function (Context $c) use ($via): void {
                $via->log('warn', 'page built');
                $c->view(static fn (): string => '<p id="c">c</p>');
            });
        });

        $app->open('/chatty');

        expect($app->logs())->toBe(['[WARN] page built'])
            ->and($app->logs())->toBe([])
        ;
    });
});
