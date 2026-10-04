<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/*
 * Testing\TestApp on the 0.14 additions: onError, spawn(), tab state, dispatch(), download(), route(),
 * countClients() and the broadcast throttle. What runs a task runs in Fixtures/test_app_tasks.php.
 */

function additionsApp(callable $routes, ?Config $config = null): TestApp {
    return new TestApp(($config ?? new Config())->withLogLevel('error'), $routes);
}

/**
 * @param callable(ServerRequestInterface): ResponseInterface $handle
 */
function additionsHandler(callable $handle): RequestHandlerInterface {
    return new class($handle) implements RequestHandlerInterface {
        /** @var callable(ServerRequestInterface): ResponseInterface */
        private $handle;

        public function __construct(callable $handle) {
            $this->handle = $handle;
        }

        public function handle(ServerRequestInterface $request): ResponseInterface {
            return ($this->handle)($request);
        }
    };
}

/**
 * @param list<string> $reports
 */
function additionsReportErrors(Via $via, array &$reports): void {
    $via->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use (&$reports): void {
        $reports[] = $phase->value . ' ' . ($c === null ? 'null' : 'ctx') . ' ' . ($action ?? 'null') . ' ' . $e->getMessage();
    });
}

/**
 * @param list<array<string, mixed>> $patches
 *
 * @return list<string> the HTML of the element patches
 */
function additionsElements(array $patches): array {
    return array_values(array_map(
        static fn (array $p): string => (string) $p['html'],
        array_filter($patches, static fn (array $p): bool => $p['type'] === 'elements'),
    ));
}

/**
 * One run of Fixtures/test_app_tasks.php, shared by the tests that read it: tasks run coroutines, after
 * which OpenSwoole disables pcntl_fork() in the process, which other tests need.
 *
 * @return array<string, array<string, mixed>> what each scenario saw
 */
function additionsTaskRuns(): array {
    static $runs = null;
    if ($runs !== null) {
        return $runs;
    }

    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/test_app_tasks.php') . ' 2>&1');
    $runs = [];
    foreach (explode("\n", trim($out)) as $line) {
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("test_app_tasks.php printed something else than a scenario:\n{$out}");
        }
        $runs += $decoded;
    }

    return $runs;
}

/**
 * @return array<string, mixed>
 */
function additionsTaskRun(string $scenario): array {
    $run = additionsTaskRuns()[$scenario] ?? null;
    expect($run)->toBeArray()->not->toHaveKey('fixture_error');

    return $run;
}

describe('onError', function (): void {
    test('sees an action that throws, with the page context and the action id, and what it writes reaches the tab', function (): void {
        $reports = [];
        $app = additionsApp(static function (Via $via) use (&$reports): void {
            additionsReportErrors($via, $reports);
            $via->onError(static function (Throwable $e, ?Context $c): void {
                $c?->getSignal('_error')?->setValue($e->getMessage());
            });
            $via->page('/save', static function (Context $c): void {
                $c->signal('', '_error');
                $c->action(static fn () => throw new RuntimeException('the disk is full'), 'save');
                $c->view(static fn (): string => '<p id="s">s</p>');
            });
        });
        $tab = $app->open('/save');
        $tab->patches();

        expect(static fn () => $tab->action('save'))->toThrow(RuntimeException::class, "Action 'save' answered 500")
            ->and($reports)->toBe(['action ctx save the disk is full'])
            ->and($tab->signal('_error'))->toBe('the disk is full')
        ;
    });

    test('sees a route() handler that throws, with no context and the route path, and the request answers 500', function (): void {
        $reports = [];
        $app = additionsApp(static function (Via $via) use (&$reports): void {
            additionsReportErrors($via, $reports);
            $via->route('GET', '/api/items/{id}', additionsHandler(static fn (): never => throw new LogicException('no such table')));
        });

        $response = $app->request('GET', '/api/items/7');

        expect($response->getStatusCode())->toBe(500)
            ->and($reports)->toBe(['route null /api/items/{id} no such table'])
            ->and(implode("\n", $app->logs()))->toContain('Route handler exception on /api/items/{id}')
        ;
    });

    test('sees a download source that throws midway, with its page context, and the fetch breaks off', function (): void {
        $reports = [];
        $url = '';
        $app = additionsApp(static function (Via $via) use (&$reports, &$url): void {
            additionsReportErrors($via, $reports);
            $via->page('/export', static function (Context $c) use (&$url): void {
                $c->action(static function () use ($c, &$url): void {
                    $url = $c->download(static function (): Generator {
                        yield "id,bytes\n";

                        throw new RuntimeException('the query timed out');
                    }, 'flows.csv', 'text/csv');
                }, 'export');
                $c->view(static fn (): string => '<p id="e">e</p>');
            });
        });
        $tab = $app->open('/export')->action('export');

        expect(static fn () => $tab->request('GET', $url))->toThrow(RuntimeException::class, 'broke off after 9 bytes')
            ->and($reports)->toBe(['render ctx null the query timed out'])
        ;
    });
});

describe('spawn() and runTasks()', function (): void {
    test('runTasks() runs a task past its first wait, and each sync() it makes reaches the tab', function (): void {
        $run = additionsTaskRun('progress');

        expect($run['before'])->toBe(['renders' => [], 'running' => 1])
            ->and($run['renders'])->toBe(['<p id="q">50%</p>', '<p id="q">100%</p>'])
            ->and($run['signal'])->toBe(100)
            ->and($run['running'])->toBe(0)
            ->and($run['logs'])->toBe([])
        ;
    });

    test('a task that throws is reported as Task, and its log line reaches logs() instead of the output', function (): void {
        $run = additionsTaskRun('throw');

        expect($run['reports'])->toBe(['task ctx the import failed'])
            ->and($run['logs'])->toHaveCount(1)
            ->and($run['logs'][0])->toContain('Task failed: RuntimeException: the import failed')
        ;
    });

    test('a destroyed context turns sync() into a no-op, and the task ends on isDestroyed()', function (): void {
        expect(additionsTaskRun('destroyed'))->toBe(['steps' => ['stopped'], 'running' => 0, 'logs' => []]);
    });

    test('shutdown() waits for a task that stops on isShuttingDown(), before onWorkerStop', function (): void {
        expect(additionsTaskRun('shutdown'))->toBe(['runningBefore' => 1, 'events' => ['task stopped', 'onWorkerStop'], 'running' => 0, 'logs' => []]);
    });

    test('Via::setInterval() timers are armed as worker 0 arms them and fire while runTasks() runs the loop', function (): void {
        expect(additionsTaskRun('server-interval'))->toBe([
            'before' => ['leader' => 0, 'every' => 0],
            'idleRunFired' => false,
            'leader' => true,
            'every' => true,
            'timersLeft' => 0,
        ]);
    });

    test('runTasks() throws for a task still running after its timeout, which a later call still runs', function (): void {
        expect(additionsTaskRun('timeout'))->toBe(['error' => '1 Context::spawn() task(s) still running after 0.0 s.', 'running' => 0]);
    });

    test('a throttle holds back the broadcasts a task makes, and the last still renders', function (): void {
        $run = additionsTaskRun('throttle');

        expect($run['free']['broadcasts'])->toBe(array_fill(0, 6, 'room:a'))
            ->and($run['throttled']['broadcasts'])->toBe($run['free']['broadcasts'])
            ->and(count($run['free']['renders']))->toBeGreaterThanOrEqual(4)
            ->and(count($run['throttled']['renders']))->toBeLessThanOrEqual(3)
            ->and(end($run['throttled']['renders']))->toBe('<p id="n">6</p>')
        ;
    });
});

describe('tab state and input()', function (): void {
    test('a revival keeps the tab state an action set and the page query input() read', function (): void {
        $app = additionsApp(static function (Via $via): void {
            $via->page('/search', static function (Context $c): void {
                $q = (string) $c->input('q', '');
                $c->action(static fn () => $c->setTabState('cursor', ['page' => 3]), 'next');
                $c->view(static fn (): string => '<p id="r">' . $q . ' page ' . ($c->tabState('cursor')['page'] ?? 1) . '</p>');
            });
        });
        $tab = $app->open('/search', ['q' => 'tcp']);

        expect(additionsElements($tab->patches()))->toBe(['<p id="r">tcp page 1</p>']);

        $tab->action('next')->disconnect(expire: true)->connect();

        expect(additionsElements($tab->patches()))->toBe(['<p id="r">tcp page 3</p>'])
            ->and($tab->context()->tabState('cursor'))->toBe(['page' => 3])
        ;
    });
});

describe('dispatch()', function (): void {
    test('sends a script that fires the event with its JSON detail, which no value breaks out of', function (): void {
        $app = additionsApp(static function (Via $via): void {
            $via->page('/toast', static function (Context $c): void {
                $c->action(static fn () => $c->dispatch('toast', ['text' => '</script><b>']), 'toast');
                $c->view(static fn (): string => '<p id="t">t</p>');
            });
        });
        $tab = $app->open('/toast');
        $tab->patches();
        $tab->action('toast');

        $scripts = additionsElements($tab->patches());

        expect($scripts)->toHaveCount(1)
            ->and($scripts[0])->toContain('CustomEvent', '"toast"', '\\u003C/script\\u003E')
            ->and(substr_count($scripts[0], '</script>'))->toBe(1)
        ;
    });
});

describe('download()', function (): void {
    test('the tab fetches its URL once, and another browser gets 403 without using it up', function (): void {
        $url = '';
        $app = additionsApp(static function (Via $via) use (&$url): void {
            $via->page('/export', static function (Context $c) use (&$url): void {
                $c->action(static function () use ($c, &$url): void {
                    $url = $c->download(static function (): Generator {
                        yield "a,b\n";

                        yield "1,2\n";
                    }, 'flows.csv', 'text/csv; charset=utf-8');
                }, 'export');
                $c->view(static fn (): string => '<p id="e">e</p>');
            });
        });
        $tab = $app->open('/export')->action('export');

        $stranger = $app->open('/export')->request('GET', $url);
        $response = $tab->request('GET', $url);

        expect($stranger->getStatusCode())->toBe(403)
            ->and($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe("a,b\n1,2\n")
            ->and($response->getHeaderLine('Content-Type'))->toBe('text/csv; charset=utf-8')
            ->and($response->getHeaderLine('Content-Disposition'))->toContain('filename="flows.csv"')
            ->and($tab->request('GET', $url)->getStatusCode())->toBe(404)
        ;
    });
});

describe('route()', function (): void {
    test('a client without cookies gets the handler response, with path parameters and query', function (): void {
        $app = additionsApp(static function (Via $via): void {
            $via->route(['POST'], '/mcp/{tool}', additionsHandler(static fn (ServerRequestInterface $r): ResponseInterface => new Psr7Response(
                200,
                ['Content-Type' => 'application/json'],
                (string) json_encode([
                    'tool' => $r->getAttribute('tool'),
                    'query' => $r->getQueryParams(),
                    'body' => json_decode((string) $r->getBody(), true),
                ]),
            )));
        });

        $response = $app->request('POST', '/mcp/search?limit=5', '{"q":"tcp"}', ['Content-Type' => 'application/json']);

        expect($response->getStatusCode())->toBe(200)
            ->and(json_decode((string) $response->getBody(), true))->toBe(['tool' => 'search', 'query' => ['limit' => '5'], 'body' => ['q' => 'tcp']])
            ->and($response->hasHeader('Set-Cookie'))->toBeFalse()
            ->and($app->request('GET', '/mcp/search')->getStatusCode())->toBe(405)
        ;
    });

    test('a form-urlencoded body reaches getParsedBody(), as on a server, and a JSON body does not', function (): void {
        $app = additionsApp(static function (Via $via): void {
            $via->route('POST', '/login', additionsHandler(static fn (ServerRequestInterface $r): ResponseInterface => new Psr7Response(200, [], (string) json_encode($r->getParsedBody()))));
        });

        $form = $app->request('POST', '/login', 'user=ada&password=love+lace&roles%5B%5D=a', ['Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8']);
        $json = $app->request('POST', '/login', '{"user":"ada"}', ['Content-Type' => 'application/json']);

        expect(json_decode((string) $form->getBody(), true))->toBe(['user' => 'ada', 'password' => 'love lace', 'roles' => ['a']])
            ->and((string) $json->getBody())->toBe('null')
        ;
    });

    test('a tab request carries its session in via.session', function (): void {
        $app = additionsApp(static function (Via $via): void {
            $via->page('/', static fn (Context $c) => $c->view(static fn (): string => '<p id="h">h</p>'));
            $via->route('GET', '/api/whoami', additionsHandler(static fn (ServerRequestInterface $r): ResponseInterface => new Psr7Response(200, [], (string) $r->getAttribute('via.session'))));
        });
        $tab = $app->open('/');

        expect((string) $tab->request('GET', '/api/whoami')->getBody())->toBe($tab->context()->getSessionId());
    });
});

describe('countClients()', function (): void {
    test('counts the connected tabs a broadcast of the scope reaches, with wildcards', function (): void {
        $app = additionsApp(static function (Via $via): void {
            $via->page('/room/{name}', static function (Context $c, string $name): void {
                $c->addScope('room:' . $name);
                $c->view(static fn (): string => '<p id="r">' . $name . '</p>');
            });
        });
        $via = $app->via();
        $a1 = $app->open('/room/a');
        $app->open('/room/a');
        $app->open('/room/b');
        $app->open('/room/b', connect: false);

        expect($via->countClients('room:a'))->toBe(2)
            ->and($via->countClients('room:b'))->toBe(1)
            ->and($via->countClients('room:*'))->toBe(3)
            ->and($via->countClients(Scope::GLOBAL))->toBe(3)
            ->and($via->countClients(Scope::routeScope('/room/{name}')))->toBe(3)
        ;

        $a1->disconnect();

        expect($via->countClients('room:a'))->toBe(1);
    });
});
