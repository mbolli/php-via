<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Response;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/*
 * Context Revival (end-to-end, in-process)
 *
 * When a backgrounded tab's context is destroyed past the cleanup delay, a returning tab should
 * rebuild an equivalent context (same ID → same signal/action IDs) and re-seed the values the
 * client still holds — instead of hard-reloading. This exercises the full server-side cycle:
 * initial load → user interaction → destroy (records revival snapshot) → reconnect → revive.
 *
 * Most tests drive Via::reviveContextFromClient(), the Request-free core of reviveContext(), directly;
 * the slim POST test runs ActionHandler and SseHandler on fake requests, as ActionThrowableTest does.
 */

/**
 * Register the counter route and return [Via, handler]. The handler is returned so the test can
 * replay it for the simulated initial load (mimicking RequestHandler::doHandlePage()).
 *
 * @return array{Via, callable}
 */
function reviveCounterApp(?Config $config = null): array {
    $app = createVia($config);
    $handler = function (Context $c): void {
        $count = $c->signal(0, 'count');
        $c->action(function () use ($count): void {
            $count->setValue($count->int() + 1);
        }, 'increment');
        $c->view(fn (): string => (string) $count->int());
    };
    $app->page('/counter', $handler);

    return [$app, $handler];
}

/**
 * Simulate an initial page load: mint a context with the route-encoded ID, register it in both
 * the Via and Application layers, and run the handler — mirroring RequestHandler::doHandlePage().
 */
function reviveMintContext(Via $app, callable $handler, string $contextId, string $sessionId): Context {
    $ctx = new Context($contextId, '/counter', $app, null, $sessionId);
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($contextId, $sessionId);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($handler, $ctx, []);

    return $ctx;
}

describe('Deterministic (revival-stable) action IDs', function (): void {
    test('named TAB action IDs are the name, stable across a re-run with the same context ID', function (): void {
        $app = createVia();

        $first = (new Context('/counter_/x', '/counter', $app))->action(fn () => null, 'increment')->id();
        // A revived context re-runs the same handler under the same ID — the ID must be identical.
        $second = (new Context('/counter_/x', '/counter', $app))->action(fn () => null, 'increment')->id();

        expect($first)->toBe('increment');
        expect($second)->toBe('increment');
    });

    test('component actions are namespaced so they never collide with the parent page', function (): void {
        $app = createVia();

        $parentId = (new Context('/p_/x', '/p', $app))->action(fn () => null, 'increment')->id();
        // Component contexts carry a namespace (e.g. "a") — its actions are prefixed with it.
        $componentId = (new Context('/p_/x/_component/y', '/p', $app, 'a'))->action(fn () => null, 'increment')->id();

        expect($parentId)->toBe('increment');
        expect($componentId)->toBe('a-increment');
        expect($componentId)->not->toBe($parentId);
    });

    test('anonymous TAB actions get deterministic sequential IDs', function (): void {
        $app = createVia();
        $ctx = new Context('ctx', '/t', $app);

        expect($ctx->action(fn () => null)->id())->toBe('action0');
        expect($ctx->action(fn () => null)->id())->toBe('action1');
        // A revived context registering in the same order regenerates the same IDs.
        expect((new Context('ctx', '/t', $app))->action(fn () => null)->id())->toBe('action0');
    });
});

describe('Context revival', function (): void {
    test('a returning tab revives to the same view with seeded state and a working button', function (): void {
        [$app, $handler] = reviveCounterApp();
        $sessionId = 'a11ce000a11ce000a11ce000a11ce000';
        $contextId = '/counter_/init1';

        // Initial load.
        $ctx = reviveMintContext($app, $handler, $contextId, $sessionId);
        $signalId = $ctx->getSignal('count')->id();
        $actionId = $ctx->getAction('increment')->id();

        // User clicked a few times; count is now 42 client-side.
        $ctx->getSignal('count')->setValue(42);

        // Tab backgrounded past the cleanup delay → destroyed (captures a revival snapshot).
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);
        expect($app->contexts[$contextId] ?? null)->toBeNull();

        // Tab returns: the /_sse reconnect carries the same via_ctx and the client's live signals.
        $revived = $app->reviveContextFromClient($contextId, $sessionId, [$signalId => 42]);

        expect($revived)->not->toBeNull();
        expect($revived->getId())->toBe($contextId);                      // same ID → DOM stays wired
        expect($revived->getSignal('count')->id())->toBe($signalId);       // signal ID regenerated identically
        expect($revived->getSignal('count')->int())->toBe(42);             // seeded from the client
        expect($revived->getAction('increment')->id())->toBe($actionId);   // action URL is stable

        // The already-loaded DOM's button (baked with $actionId) still dispatches.
        $revived->executeAction($actionId);
        expect($revived->getSignal('count')->int())->toBe(43);
    });

    test('revival does not restore a server-owned TAB signal from the browser', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->signal(0, 'count', clientWritable: false);
            $c->view(fn (): string => '');
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/owned';

        $ctx = reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        $signalId = $ctx->getSignal('count')->id();
        $ctx->getSignal('count')->setValue(42);
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);

        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', [$signalId => 42]);

        expect($revived)->not->toBeNull()
            ->and($revived->getSignal('count')->int())->toBe(0)
        ;
    });

    test('revival restores a signal of a component with an explicit namespace', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->component(function (Context $k): void {
                $k->signal('', 'q');
                $k->view(fn (): string => '');
            }, 'search');
            $c->view(fn (): string => '');
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/component';

        reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);

        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['search_q____n' => 'typed']);
        $component = array_values($revived->getComponentManager()->getComponents())[0];

        expect($component->getSignal('q')->id())->toBe('search_q____n')
            ->and($component->getSignal('q')->getValue())->toBe('typed')
        ;
    });

    test('revival is denied when the requester session does not own the context', function (): void {
        [$app, $handler] = reviveCounterApp();
        $contextId = '/counter_/init2';

        reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        $app->getApp()->destroyContext($contextId);
        unset($app->contexts[$contextId]);

        expect($app->reviveContextFromClient($contextId, 'sess_attacker', []))->toBeNull();
    });

    test('revival is disabled when the window is 0 (reconnect falls back to reload)', function (): void {
        [$app, $handler] = reviveCounterApp((new Config())->withContextTimeouts(revivalWindowMs: 0));
        $contextId = '/counter_/init3';

        reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        $app->getApp()->destroyContext($contextId); // window 0 → no snapshot recorded
        unset($app->contexts[$contextId]);

        expect($app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', []))->toBeNull();
    });

    test('an unknown / never-recorded context ID cannot be revived', function (): void {
        [$app] = reviveCounterApp();

        expect($app->reviveContextFromClient('/counter_/never', 'a11ce000a11ce000a11ce000a11ce000', []))->toBeNull();
    });
});

/**
 * Destroy a context past its cleanup delay, leaving the record a returning tab revives it from.
 */
function reviveDropContext(Via $app, string $contextId): void {
    $app->getApp()->destroyContext($contextId);
    unset($app->contexts[$contextId]);
}

/**
 * Run an SSE connect of the owner's session through SseHandler and return what reached the wire.
 * The stream closes after a few polls, as a tab that goes away would.
 *
 * @param array<string, mixed> $signals
 */
function reviveConnect(Via $app, string $contextId, array $signals): string {
    $connect = new FakeActionRequest('unused', []);
    $connect->server = ['request_uri' => '/_sse', 'request_method' => 'GET'];
    $connect->get = ['datastar' => (string) json_encode(['via_ctx' => $contextId] + $signals)];
    $connect->cookie = ['via_session_id' => 'a11ce000a11ce000a11ce000a11ce000'];
    $stream = new class extends Response {
        public string $written = '';
        private int $polls = 0;

        public function header(string $key, mixed $value, bool $ucwords = true): bool {
            return true;
        }

        public function status(int $statusCode, string $reason = ''): bool {
            return true;
        }

        public function write(string $data): bool {
            $this->written .= $data;

            return true;
        }

        public function isWritable(): bool {
            return ++$this->polls <= 5;
        }

        public function end(mixed $data = null): bool {
            return true;
        }
    };

    try {
        (new SseHandler($app))->handleSSE($connect, $stream);
    } finally {
        $app->getApp()->cancelContextCleanup($contextId);
    }

    return $stream->written;
}

/**
 * What the logger printed while $fn ran.
 */
function reviveLogOutput(callable $fn): string {
    ob_start();

    try {
        $fn();
    } finally {
        $out = (string) ob_get_clean();
    }

    return $out;
}

describe('Revival from an action without signals', function (): void {
    test('a revival from via_ctx alone waits for a seed and holds the default', function (): void {
        [$app, $handler] = reviveCounterApp();
        $contextId = '/counter_/slim1';
        reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000')->getSignal('count')->setValue(42);
        reviveDropContext($app, $contextId);

        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);

        expect($revived->isAwaitingSeed())->toBeTrue()
            ->and($revived->getSignal('count')->int())->toBe(0)
        ;
    });

    test('a context waiting for a seed queues no sync, but an element patch still goes out', function (): void {
        [$app, $handler] = reviveCounterApp();
        $contextId = '/counter_/slim2';
        reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        reviveDropContext($app, $contextId);
        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);

        $revived->sync();
        $revived->syncSignals();
        expect($revived->getPatch())->toBeNull();

        $revived->getPatchManager()->queuePatch(['type' => 'elements', 'content' => '<div id="window"></div>']);
        expect($revived->getPatch())->toBe(['type' => 'elements', 'content' => '<div id="window"></div>']);
    });

    test('the SSE connect seeds the waiting context and the next sync sends the client value', function (): void {
        [$app, $handler] = reviveCounterApp();
        $contextId = '/counter_/slim3';
        $signalId = reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000')->getSignal('count')->id();
        reviveDropContext($app, $contextId);
        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);

        $app->seedFromConnect($revived, ['via_ctx' => $contextId, $signalId => 42]);

        expect($revived->getSignal('count')->int())->toBe(42)
            ->and($revived->isAwaitingSeed())->toBeFalse()
        ;

        $revived->sync();
        expect($revived->getPatch())->toBe(['type' => 'elements', 'content' => '42'])
            ->and($revived->getPatch())->toMatchArray(['type' => 'signals', 'content' => [$signalId => 42]])
            ->and($revived->getPatch())->toBeNull()
        ;
    });

    test('a signal an action wrote between the revival and the connect keeps the action value', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $count = $c->signal(0, 'count');
            $c->signal('', 'label');
            $c->action(function () use ($count): void {
                $count->setValue($count->int() + 1);
            }, 'increment');
            $c->view(fn (): string => '');
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/slim4';
        $ctx = reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        $countId = $ctx->getSignal('count')->id();
        $labelId = $ctx->getSignal('label')->id();
        reviveDropContext($app, $contextId);
        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);

        // The slim POST that revived it: ActionHandler injects its body, then runs the action.
        $revived->injectSignals(['via_ctx' => $contextId]);
        $revived->executeAction('increment');
        expect($revived->isAwaitingSeed())->toBeTrue();

        $app->seedFromConnect($revived, ['via_ctx' => $contextId, $countId => 42, $labelId => 'typed']);

        expect($revived->getSignal('count')->int())->toBe(1)
            ->and($revived->getSignal('label')->string())->toBe('typed')
            ->and($revived->isAwaitingSeed())->toBeFalse()
        ;
    });

    test('a revival with client signals does not wait and ignores the connect', function (): void {
        [$app, $handler] = reviveCounterApp();
        $contextId = '/counter_/slim5';
        $signalId = reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000')->getSignal('count')->id();
        reviveDropContext($app, $contextId);

        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId, $signalId => 42]);
        expect($revived->isAwaitingSeed())->toBeFalse();

        $app->seedFromConnect($revived, ['via_ctx' => $contextId, $signalId => 7]);
        expect($revived->getSignal('count')->int())->toBe(42);
    });

    test('an action that posts a signal besides via_ctx ends the wait', function (): void {
        [$app, $handler] = reviveCounterApp();
        $contextId = '/counter_/slim6';
        $signalId = reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000')->getSignal('count')->id();
        reviveDropContext($app, $contextId);
        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);

        $revived->injectSignals(['via_ctx' => $contextId, $signalId => 5]);
        expect($revived->isAwaitingSeed())->toBeFalse();

        $app->seedFromConnect($revived, ['via_ctx' => $contextId, $signalId => 9]);
        expect($revived->getSignal('count')->int())->toBe(5);
    });

    test('a server-owned signal keeps the server value through the seed', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->signal(0, 'count', clientWritable: false);
            $c->view(fn (): string => '');
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/slim7';
        $signalId = reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000')->getSignal('count')->id();
        reviveDropContext($app, $contextId);
        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);

        $app->seedFromConnect($revived, ['via_ctx' => $contextId, $signalId => 42]);

        expect($revived->getSignal('count')->int())->toBe(0)
            ->and($revived->isAwaitingSeed())->toBeFalse()
        ;
    });

    test('the seed reaches a component signal, and a component sync waits with its page', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->component(function (Context $k): void {
                $k->signal('', 'q');
                $k->view(fn (): string => '');
            }, 'search');
            $c->view(fn (): string => '');
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/slim8';
        reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        reviveDropContext($app, $contextId);
        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);
        $component = array_values($revived->getComponentManager()->getComponents())[0];

        $component->sync();
        expect($revived->getPatch())->toBeNull();

        $app->seedFromConnect($revived, ['via_ctx' => $contextId, $component->getSignal('q')->id() => 'typed']);
        expect($component->getSignal('q')->getValue())->toBe('typed');
    });

    test('a slim action POST revives the context and its SSE connect seeds it before the first sync', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $count = $c->signal(0, 'count');
            $c->action(fn () => null, 'window');
            $c->view(fn (): string => (string) $count->int());
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/slim9';
        $signalId = reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000')->getSignal('count')->id();
        reviveDropContext($app, $contextId);

        $post = new FakeActionRequest('window', ['via_ctx' => $contextId]);
        $post->cookie = ['via_session_id' => 'a11ce000a11ce000a11ce000a11ce000'];
        $posted = new FakeStaticResponse();
        (new ActionHandler($app))->handleAction($post, $posted, 'window');
        $revived = $app->contexts[$contextId];

        expect($posted->statusCode)->toBe(200)
            ->and($revived->isAwaitingSeed())->toBeTrue()
        ;

        $written = reviveConnect($app, $contextId, [$signalId => 42]);

        expect($revived->isAwaitingSeed())->toBeFalse()
            ->and($revived->getSignal('count')->int())->toBe(42)
            ->and($written)->toContain("data: elements 42\n")
            ->and($written)->toContain('data: signals {"' . $signalId . '":42}')
            ->and($written)->not->toContain('"' . $signalId . '":0')
        ;
    });

    test('an SSE connect that revives the context seeds it itself and holds nothing', function (): void {
        $app = new Via((new Config())->withLogLevel('info'));
        $handler = function (Context $c): void {
            $count = $c->signal(0, 'count');
            $c->view(fn (): string => 'count ' . $count->int());
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/slim10';
        reviveLogOutput(fn () => reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000'));
        reviveDropContext($app, $contextId);

        $written = '';
        $log = reviveLogOutput(function () use ($app, $contextId, &$written): void {
            $written = reviveConnect($app, $contextId, []);
        });

        expect($app->contexts[$contextId]->isAwaitingSeed())->toBeFalse()
            ->and($log)->toContain("Revived context {$contextId} on route")
            ->and($log)->not->toContain('waiting for its SSE connect')
            ->and($log)->not->toContain('Seeded context')
            ->and($written)->toContain("data: elements count 0\n")
        ;
    });

    test('a revival without signals of a page without TAB signals does not wait', function (): void {
        $app = new Via((new Config())->withLogLevel('info'));
        $handler = function (Context $c): void {
            $c->view(fn (): string => 'static');
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/slim11';
        reviveLogOutput(fn () => reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000'));
        reviveDropContext($app, $contextId);

        $revived = null;
        $log = reviveLogOutput(function () use ($app, $contextId, &$revived): void {
            $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);
        });
        $revived->sync();

        expect($revived->isAwaitingSeed())->toBeFalse()
            ->and($log)->not->toContain('waiting for its SSE connect')
            ->and($revived->getPatch())->toBe(['type' => 'elements', 'content' => 'static'])
        ;
    });

    test('a component signal an action wrote keeps its value, and the seed still reaches a sibling whose sanitised id was the same', function (): void {
        $app = createVia();
        $handler = function (Context $c): void {
            $c->component(function (Context $k): void {
                $k->signal('', 'b_c');
                $k->view(fn (): string => '');
            }, 'a');
            $c->component(function (Context $k): void {
                $k->signal('', 'c');
                $k->view(fn (): string => '');
            }, 'a_b');
            $c->view(fn (): string => '');
        };
        $app->page('/counter', $handler);
        $contextId = '/counter_/slim12';
        reviveMintContext($app, $handler, $contextId, 'a11ce000a11ce000a11ce000a11ce000');
        reviveDropContext($app, $contextId);
        $revived = $app->reviveContextFromClient($contextId, 'a11ce000a11ce000a11ce000a11ce000', ['via_ctx' => $contextId]);
        [$first, $second] = array_values($revived->getComponentManager()->getComponents());

        $first->getSignal('b_c')->setValue('action');
        $app->seedFromConnect($revived, [
            'via_ctx' => $contextId,
            $first->getSignal('b_c')->id() => 'typed',
            $second->getSignal('c')->id() => 'typed',
        ]);

        expect($first->getSignal('b_c')->id())->not->toBe($second->getSignal('c')->id())
            ->and($first->getSignal('b_c')->getValue())->toBe('action')
            ->and($second->getSignal('c')->getValue())->toBe('typed')
        ;
    });
});

/**
 * Run a scenario of Fixtures/revival_seed_cases.php: a flush only coalesces inside a coroutine,
 * which needs a process of its own (see BroadcastCoalescingTest).
 *
 * @return array<string, mixed> what the scenario observed
 */
function revivalSeedCase(string $case, string ...$args): array {
    $fixture = dirname(__DIR__) . '/Fixtures/revival_seed_cases.php';
    $argv = implode(' ', array_map(escapeshellarg(...), [$case, ...$args]));
    $out = trim((string) shell_exec(
        'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $argv . ' 2>&1'
    ));
    $lines = explode("\n", $out);
    $data = json_decode((string) end($lines), true);

    expect($data)->toBeArray('fixture output: ' . var_export($out, true));
    expect($data)->not->toHaveKey('error', 'fixture output: ' . var_export($out, true));

    return $data;
}

describe('Revival from an action without signals, with coalesced broadcasts', function (): void {
    test('a context held through a coalesced flush gets the seeded frame from its SSE connect', function (): void {
        $r = revivalSeedCase('flush-before-connect');

        expect($r['status'])->toBe(200)
            ->and($r['awaiting'])->toBeTrue()
            ->and($r['observer'])->toBe(['<div id="page">count=0 total=1</div>'], 'the flush ran while the context was held')
            ->and($r['whileHeld'])->toBe([])
            ->and($r['rendersWhileHeld'])->toBe(0)
            ->and($r['written'])->toContain("data: elements <div id=\"page\">count=42 total=1</div>\n")
            ->and($r['written'])->toContain('data: signals {"' . $r['countId'] . '":42}')
            ->and($r['written'])->not->toContain('count=0')
            ->and($r['written'])->not->toContain('"' . $r['countId'] . '":0')
        ;
    });

    test('a connect that seeds before the pending flush gets the flush frame with the seeded values', function (): void {
        $r = revivalSeedCase('connect-before-flush');

        expect($r['awaiting'])->toBeTrue()
            ->and($r['seededBeforeFlush'])->toBeTrue()
            ->and($r['observer'])->toBe(['<div id="page">count=0 total=1</div>'])
            ->and($r['renders'])->toBe(2, 'the initial sync, then the flush')
            ->and(substr_count($r['written'], "data: elements <div id=\"page\">count=42 total=1</div>\n"))->toBe(2)
            ->and($r['written'])->not->toContain('count=0')
            ->and($r['written'])->not->toContain('"' . $r['countId'] . '":0')
        ;
    });

    test('an older flush that renders a context after its wait ended is followed by a fresh frame', function (): void {
        // The flush that passed the held context still took its epoch, so the older flush renders h again.
        $r = revivalSeedCase('older-flush-after-held-skip', 'seeded');

        expect($r['awaiting'])->toBeTrue()
            ->and($r['skippedBy'])->toBeGreaterThan(0)
            ->and($r['queuedBySkip'])->toBeNull()
            ->and($r['awaitingWhenF1Resumes'])->toBeFalse()
            ->and($r['frames'])->toBe(['<div id="h">n=1 mine=typed</div>', '<div id="h">n=2 mine=typed</div>'])
            ->and($r['renders'])->toBe(['g' => 1, '/r_/h' => 2])
            ->and($r['renewals'])->toBe(1)
            ->and($r['warnings'])->toBe([])
        ;
    });

    test('an older flush that reaches a context still held retries once, queues nothing, and the connect sends the current frame', function (): void {
        $r = revivalSeedCase('older-flush-after-held-skip', 'held');

        expect($r['awaitingWhenF1Resumes'])->toBeTrue()
            ->and($r['frames'])->toBe([])
            ->and($r['renders'])->toBe(['g' => 1])
            ->and($r['renewals'])->toBe(1, 'one retry under a renewed epoch, then the held sync takes it')
            ->and($r['written'])->toContain("data: elements <div id=\"h\">n=2 mine=typed</div>\n")
            ->and($r['warnings'])->toBe([])
        ;
    });
});
