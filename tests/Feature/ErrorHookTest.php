<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/*
 * Via::onError() observes the throws php-via catches from actions, renders, timers and tasks.
 * Timers need a reactor, so they run in tests/Fixtures/tab_interval_throw.php.
 */

/**
 * @return ArrayObject<int, array{0: Throwable, 1: ?Context, 2: ErrorPhase, 3: ?string}> each report, in order
 */
function errorHookRecorder(Via $via): ArrayObject {
    $seen = new ArrayObject();
    $via->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use ($seen): void {
        $seen[] = [$e, $c, $phase, $action];
    });

    return $seen;
}

/**
 * @return array{0: string, 1: FakeStaticResponse} the log output and the response
 */
function errorHookPost(Via $via, Context $page, string $actionId): array {
    $response = new FakeStaticResponse();
    ob_start();

    try {
        (new ActionHandler($via))->handleAction(new FakeActionRequest($actionId, ['via_ctx' => $page->getId()]), $response, $actionId);
    } finally {
        $log = (string) ob_get_clean();
    }

    return [$log, $response];
}

/**
 * The signal values the queued patches send to the tab, by signal id.
 *
 * @return array<string, mixed>
 */
function errorHookSentSignals(Context $page): array {
    $sent = [];
    while (($patch = $page->getPatch()) !== null) {
        if ($patch['type'] === 'signals') {
            $sent = array_replace_recursive($sent, (array) $patch['content']);
        }
    }

    return $sent;
}

/**
 * @return array{0: string, 1: FakeStaticResponse} the log output and the response
 */
function errorHookGet(Via $via, string $path): array {
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

function errorHookPage(Via $via, string $id = 'ctx-err', string $route = '/err'): Context {
    $page = new Context($id, $route, $via);
    $via->contexts[$id] = $page;

    return $page;
}

describe('actions', function (): void {
    test('a throwing action reaches onError with its page, the phase and the action id, and still answers 500', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $page = errorHookPage($via);
        $thrown = new RuntimeException('save failed');
        $action = $page->action(function () use ($thrown): void {
            throw $thrown;
        }, 'save');

        [$log, $response] = errorHookPost($via, $page, $action->id());

        expect($response->statusCode)->toBe(500)
            ->and($response->body)->toBe('Action failed')
            ->and($log)->toContain('Action save failed: RuntimeException: save failed')
            ->and($seen)->toHaveCount(1)
        ;
        [$e, $c, $phase, $actionId] = $seen[0];
        expect($e)->toBe($thrown)
            ->and($c)->toBe($page)
            ->and($phase)->toBe(ErrorPhase::Action)
            ->and($actionId)->toBe('save')
        ;
    });

    test('a successful action reaches no callback', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $page = errorHookPage($via);
        $action = $page->action(function (): void {}, 'fine');

        [, $response] = errorHookPost($via, $page, $action->id());

        expect($response->statusCode)->toBe(200)->and($seen)->toHaveCount(0);
    });

    test('a component action reports the page and the id with the namespace', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $page = errorHookPage($via);
        $actionId = null;
        $page->component(function (Context $c) use (&$actionId): void {
            $actionId = $c->action(function (): void {
                throw new LogicException('component action failed');
            }, 'remove')->id();
            $c->view(fn (): string => '<div>row</div>');
        }, 'row');

        [, $response] = errorHookPost($via, $page, (string) $actionId);

        expect($response->statusCode)->toBe(500)
            ->and($seen)->toHaveCount(1)
            ->and($seen[0][1])->toBe($page)
            ->and($seen[0][3])->toBe('row-remove')
        ;
    });

    test('a view that throws in a sync() the action calls reports Action, not Render', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $page = errorHookPage($via);
        $page->view(function (): string {
            throw new RuntimeException('view failed');
        });
        $action = $page->action(function (Context $c): void {
            $c->sync();
        }, 'refresh');

        errorHookPost($via, $page, $action->id());

        expect($seen)->toHaveCount(1)
            ->and($seen[0][2])->toBe(ErrorPhase::Action)
            ->and($seen[0][0]->getMessage())->toBe('view failed')
        ;
    });

    test('every callback runs in order, and one that throws is logged and does not stop the next', function (): void {
        $via = createVia();
        $calls = [];
        $via->onError(function () use (&$calls): void {
            $calls[] = 'first';

            throw new LogicException('reporter down');
        });
        $via->onError(function () use (&$calls): void {
            $calls[] = 'second';
        });
        $page = errorHookPage($via);
        $action = $page->action(function (): void {
            throw new RuntimeException('action failed');
        }, 'go');

        [$log, $response] = errorHookPost($via, $page, $action->id());

        expect($calls)->toBe(['first', 'second'])
            ->and($response->statusCode)->toBe(500)
            ->and($log)->toContain('onError callback failed (phase action): LogicException: reporter down')
        ;
    });

    test('a callback whose sync() hits the same broken view is not called again', function (): void {
        $via = createVia();
        $calls = 0;
        $via->onError(function (Throwable $e, ?Context $c) use (&$calls): void {
            ++$calls;
            $c?->sync();
        });
        $page = errorHookPage($via);
        $page->view(function (): string {
            throw new RuntimeException('always broken');
        });
        $action = $page->action(function (Context $c): void {
            $c->sync();
        }, 'refresh');

        [$log] = errorHookPost($via, $page, $action->id());

        expect($calls)->toBe(1)
            ->and($log)->toContain('onError callback failed (phase action): RuntimeException: always broken')
        ;
    });
});

describe('replacing nfsen-ng style catch blocks', function (): void {
    // nfsen-ng's backend/actions/RangeActions.php wraps each action like this, and 17 of its 53
    // catch (Throwable) blocks do nothing else: log, and put a message into the _error signal.
    $wrapped = static function (Context $c, Closure $work, array &$log): void {
        try {
            $work($c);
        } catch (Throwable $e) {
            $log[] = 'apply-globals failed: ' . $e->getMessage();
            $c->getSignal('_error')?->setValue('Could not apply the selection: ' . $e->getMessage());
        }
    };
    $work = static function (Context $c): void {
        $c->getSignal('range')?->setValue('24h');

        throw new RuntimeException('nfdump is not installed');
    };

    test('one onError callback sends the tab what the per-action catch block sent', function () use ($wrapped, $work): void {
        $before = createVia();
        $beforePage = errorHookPage($before, 'ctx-before');
        $beforePage->signal('', '_error');
        $beforePage->signal('7d', 'range');
        $beforeLog = [];
        $beforeAction = $beforePage->action(function (Context $c) use ($wrapped, $work, &$beforeLog): void {
            $wrapped($c, $work, $beforeLog);
        }, 'apply-globals');

        $after = createVia();
        $afterLog = [];
        $after->onError(function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use (&$afterLog): void {
            if ($phase !== ErrorPhase::Action || $c === null) {
                return;
            }
            $afterLog[] = "{$action} failed: " . $e->getMessage();
            $c->getSignal('_error')?->setValue('Could not apply the selection: ' . $e->getMessage());
        });
        $afterPage = errorHookPage($after, 'ctx-before');
        $afterPage->signal('', '_error');
        $afterPage->signal('7d', 'range');
        $afterAction = $afterPage->action($work, 'apply-globals');

        [, $beforeResponse] = errorHookPost($before, $beforePage, $beforeAction->id());
        [$afterOutput, $afterResponse] = errorHookPost($after, $afterPage, $afterAction->id());

        $sent = errorHookSentSignals($afterPage);
        expect($sent)->toBe(errorHookSentSignals($beforePage))
            ->and($sent)->toContain('Could not apply the selection: nfdump is not installed')
            ->and($sent)->toContain('24h')
            ->and($afterLog)->toBe($beforeLog)
            ->and($afterOutput)->toContain('Action apply-globals failed: RuntimeException: nfdump is not installed')
        ;
        // The wrapper hid the failure from the client; the callback only observes, so the POST still fails.
        expect($beforeResponse->statusCode)->toBe(200)->and($afterResponse->statusCode)->toBe(500);
    });

    test('a callback that only logs replaces a catch block that only logs', function (): void {
        $via = createVia();
        $logged = [];
        $via->onError(function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use (&$logged): void {
            $logged[] = "{$phase->value} {$action}: {$e->getMessage()}";
        });
        $page = errorHookPage($via);
        $select = $page->action(function (): void {
            throw new RuntimeException('talkers query failed');
        }, 'talkers-select');
        $refresh = $page->action(function (): void {
            throw new RuntimeException('health checks failed');
        }, 'health-refresh');

        errorHookPost($via, $page, $select->id());
        errorHookPost($via, $page, $refresh->id());

        expect($logged)->toBe([
            'action talkers-select: talkers query failed',
            'action health-refresh: health checks failed',
        ]);
    });
});

describe('renders', function (): void {
    test('a page handler that throws reports Render with the context it was building', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $via->page('/handler-throws', function (Context $c): void {
            throw new RuntimeException('handler failed');
        });

        [, $response] = errorHookGet($via, '/handler-throws');

        expect($response->statusCode)->toBe(500)
            ->and($seen)->toHaveCount(1)
            ->and($seen[0][2])->toBe(ErrorPhase::Render)
            ->and($seen[0][1]?->getRoute())->toBe('/handler-throws')
            ->and($seen[0][3])->toBeNull()
        ;
    });

    test('a view that throws on page load reports Render', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $via->page('/view-throws', function (Context $c): void {
            $c->view(function (): string {
                throw new RuntimeException('view failed');
            });
        });

        [, $response] = errorHookGet($via, '/view-throws');

        expect($response->statusCode)->toBe(500)
            ->and($seen)->toHaveCount(1)
            ->and($seen[0][2])->toBe(ErrorPhase::Render)
            ->and($seen[0][0]->getMessage())->toBe('view failed')
        ;
    });

    test('a broadcast reports each distinct failure once per pass, with the first context, and the others still get the frame', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $broken = [];
        for ($i = 0; $i < 3; ++$i) {
            $ctx = errorHookPage($via, "ctx-broken-{$i}", '/room');
            $ctx->scope('room:a');
            $ctx->view(function (): string {
                throw new RuntimeException('same failure');
            });
            $broken[] = $ctx;
        }
        $other = errorHookPage($via, 'ctx-other', '/room');
        $other->scope('room:a');
        $other->view(function (): string {
            throw new LogicException('other failure');
        });
        $healthy = errorHookPage($via, 'ctx-healthy', '/room');
        $healthy->scope('room:a');
        $healthy->view(fn (): string => '<div id="ok">ok</div>');

        ob_start();
        $via->broadcast('room:a');
        ob_end_clean();

        expect($seen)->toHaveCount(2)
            ->and($seen[0][2])->toBe(ErrorPhase::Render)
            ->and($seen[0][1])->toBe($broken[0])
            ->and($seen[0][0]->getMessage())->toBe('same failure')
            ->and($seen[1][1])->toBe($other)
            ->and($healthy->getPatch()['content'] ?? null)->toContain('ok')
        ;
    });

    test('a callback that broadcasts into another broken view is not called for that one', function (): void {
        $via = createVia();
        $calls = 0;
        $via->onError(function () use ($via, &$calls): void {
            ++$calls;
            $via->broadcast('room:b');
        });
        foreach (['room:a', 'room:b'] as $scope) {
            $ctx = errorHookPage($via, "ctx-{$scope}", '/room');
            $ctx->scope($scope);
            $ctx->view(function () use ($scope): string {
                throw new RuntimeException("{$scope} broken");
            });
        }

        ob_start();
        $via->broadcast('room:a');
        $log = (string) ob_get_clean();

        expect($calls)->toBe(1)
            ->and($log)->toContain('Sync failed during broadcast of room:a')
            ->and($log)->toContain('Sync failed during broadcast of room:b')
        ;
    });

    test("a stream's first sync that throws reports Render", function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $page = errorHookPage($via, 'ctx-sse', '/sse');
        $page->view(function (): string {
            throw new RuntimeException('first sync failed');
        });

        $request = new FakeActionRequest('unused', ['via_ctx' => 'ctx-sse']);
        $request->server = ['request_uri' => '/_sse', 'request_method' => 'GET'];
        $response = new FakeStaticResponse();
        ob_start();

        try {
            (new SseHandler($via))->handleSSE($request, $response);
        } finally {
            ob_end_clean();
            $via->getApp()->cancelContextCleanup('ctx-sse');
        }

        expect($response->statusCode)->toBe(500)
            ->and($seen)->toHaveCount(1)
            ->and($seen[0][1])->toBe($page)
            ->and($seen[0][2])->toBe(ErrorPhase::Render)
        ;
    });

    test('a page handler that throws on revival reports Render', function (): void {
        $via = createVia();
        $seen = errorHookRecorder($via);
        $fail = false;
        $via->page('/revive', function (Context $c) use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('revival failed');
            }
            $c->view(fn (): string => '<div id="r">r</div>');
        });
        $page = new Context('/revive_/abc', '/revive', $via, null, 'session-1');
        $via->invokeHandlerWithParams($via->getRouter()->getRoutes()['/revive'], $page, []);
        $via->contexts[$page->getId()] = $page;
        $via->getApp()->registerContext($page);
        $via->getApp()->destroyContext($page->getId());
        unset($via->contexts[$page->getId()]);

        $fail = true;
        ob_start();
        $revived = $via->reviveContextFromClient($page->getId(), 'session-1', []);
        ob_end_clean();

        expect($revived)->toBeNull()
            ->and($seen)->toHaveCount(1)
            ->and($seen[0][2])->toBe(ErrorPhase::Render)
            ->and($seen[0][0]->getMessage())->toBe('revival failed')
        ;
    });
});

describe('without callbacks', function (): void {
    test('a broadcast of a broken view logs as before', function (): void {
        $via = createVia();
        $ctx = errorHookPage($via, 'ctx-plain', '/plain');
        $ctx->scope(Scope::routeScope('/plain'));
        $ctx->view(function (): string {
            throw new RuntimeException('plain failure');
        });

        ob_start();
        $via->broadcast(Scope::routeScope('/plain'));
        $log = (string) ob_get_clean();

        expect($log)->toContain('Sync failed during broadcast of route:/plain for 1 context(s): RuntimeException: plain failure');
    });
});

describe('timers and tasks', function (): void {
    test('a throwing Context::setInterval() callback reaches onError on every tick, with its context', function (): void {
        $output = [];
        exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/tab_interval_throw.php') . ' 2>&1', $output);

        expect(implode("\n", $output))->toContain("ticks=3\n", 'reports=3 timer ctx-interval null tab interval failed');
    });
});
