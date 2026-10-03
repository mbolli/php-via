<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\Via;

/*
 * Scoped signal VALUES across workers.
 *
 * SignalManager holds Signal objects in a plain PHP array, so without shared backing a
 * ROUTE/SESSION/GLOBAL-scoped signal is shared between the contexts of ONE worker and no
 * further — each worker initialises its own copy from the declared default and they diverge
 * from the first mutation.
 *
 * That mattered for sequencing. The visible multi-worker failure is `HTTP 400 Invalid context`:
 * a context lives on the worker that served its page, and ActionHandler — unlike SseHandler —
 * never tries to revive one it has not seen, so action success tracks 1/worker_num (measured
 * 100/51/26/13/6.9% at 1/2/4/8/16 workers, see PERFORMANCE.md). Giving ActionHandler that
 * fallback looks like the fix, but a revived context re-runs the route handler on the receiving
 * worker: without shared values the action would mutate a copy nobody is watching, turning a
 * loud 400 into a silent wrong answer.
 *
 * SharedSignalStore backs the value — and only the value — with an OpenSwoole\Table. Two Via
 * instances stand in for two worker processes here; each gets its own SignalManager, exactly as
 * separate processes do, and they are handed the same store the way a fork inherits one.
 * tests/Feature/ScopedSignalSharingTest.php covers the real cross-process case.
 */

/** A Via instance standing in for one worker process. */
function workerApp(?SharedSignalStore $store = null): Via {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->setSharedSignalStore($store);

    $app->page('/probe', function (Context $c): void {
        $c->scope(Scope::ROUTE);
        $count = $c->signal(0, 'count', Scope::ROUTE);
        $c->action(function (Context $ctx): void {
            $signal = $ctx->getSignal('count');
            $signal->setValue($signal->int() + 1);
        }, 'bump');
        $c->view(fn (): string => 'count=' . $count->int());
    });

    return $app;
}

/** Mount /probe on $app under $contextId, as a page load would. */
function mountProbe(Via $app, string $contextId): Context {
    $ctx = new Context($contextId, '/probe', $app, null, 'sess1');
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($contextId, 'sess1');
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/probe'], $ctx, []);

    return $ctx;
}

test('a ROUTE-scoped signal is shared between contexts on the same worker', function (): void {
    $app = workerApp();
    $first = mountProbe($app, '/probe_/a');
    $second = mountProbe($app, '/probe_/b');

    $first->executeAction('bump');

    expect($second->renderView())->toBe('count=1', 'one worker shares scoped values across its contexts');
});

test('a ROUTE-scoped signal is NOT shared across workers without the store', function (): void {
    $workerA = workerApp();
    $workerB = workerApp();

    // The same context id mounted on both workers: A served the page and holds the client's
    // SSE stream; B is the worker an action happened to land on.
    $onA = mountProbe($workerA, '/probe_/unshared');
    $onB = mountProbe($workerB, '/probe_/unshared');

    for ($i = 0; $i < 3; ++$i) {
        $onB->executeAction('bump');
    }

    expect($onB->renderView())->toBe('count=3', 'the worker that ran the actions sees them');
    expect($onA->renderView())->toBe('count=0', 'and without shared backing, nobody else does');
});

test('a ROUTE-scoped signal IS shared across workers given one store', function (): void {
    $store = new SharedSignalStore(maxRows: 64);

    $workerA = workerApp($store);
    $workerB = workerApp($store);

    $onA = mountProbe($workerA, '/probe_/shared');
    $onB = mountProbe($workerB, '/probe_/shared');

    for ($i = 0; $i < 3; ++$i) {
        $onB->executeAction('bump');
    }

    expect($onB->renderView())->toBe('count=3');
    expect($onA->renderView())->toBe(
        'count=3',
        'the worker holding the client SSE stream must see actions served by another worker'
    );
});

test('a worker joining an existing scope adopts the live value, not the default', function (): void {
    $store = new SharedSignalStore(maxRows: 64);

    $workerA = workerApp($store);
    $first = mountProbe($workerA, '/probe_/late-a');
    $first->executeAction('bump');
    $first->executeAction('bump');

    // A second worker mounts the same route only now. Re-running the handler declares
    // signal(0, 'count') again — which must NOT reset the scope to zero.
    $workerB = workerApp($store);
    $late = mountProbe($workerB, '/probe_/late-b');

    expect($late->renderView())->toBe('count=2');
    expect($first->renderView())->toBe('count=2', 'and the first worker is not disturbed by the join');
});

test('scopes that differ only by punctuation get their own id and value across workers', function (): void {
    $store = new SharedSignalStore(maxRows: 64);
    $workerA = workerApp($store);
    $workerB = workerApp($store);

    $ctxA = new Context('ctx-a', '/probe', $workerA, null, 'sess-a');
    $ctxB = new Context('ctx-b', '/probe', $workerB, null, 'sess-b');
    $draftA = $ctxA->signal('', 'draft', 'user:a-b@x.com');
    $draftB = $ctxB->signal('', 'draft', 'user:a.b@x.com');

    expect($draftA->id())->not->toBe($draftB->id());

    $draftA->setValue('private to a-b', false);

    expect($draftB->string())->toBe('');
    expect($draftA->string())->toBe('private to a-b');
});

test('the shared row is per scope even for two signals with the same id', function (): void {
    $store = new SharedSignalStore(maxRows: 64);
    $workerA = workerApp($store);
    $workerB = workerApp($store);

    $draftA = new Signal('draft', '', 'user:a', true, null, $workerA);
    $draftB = new Signal('draft', '', 'user:b', true, null, $workerB);
    $workerA->registerScopedSignal('user:a', $draftA);
    $workerB->registerScopedSignal('user:b', $draftB);

    $draftA->setValue('private to a', false);

    expect($draftB->string())->toBe('');
    expect($draftA->string())->toBe('private to a');
});

/*
 * Read snapshots on the synchronous broadcast path (outside a coroutine). A fan-out reads each
 * scoped signal from shared memory once and reuses it for every context; a direct $store->set()
 * stands in for another worker's write. Fixtures/read_snapshot_cases.php covers the flush path.
 */

/**
 * A worker whose /fan contexts each render the ROUTE-scoped count, then run $afterRead.
 *
 * @param Closure(Context, Signal): void $afterRead
 */
function fanWorker(SharedSignalStore $store, Closure $afterRead): Via {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->setSharedSignalStore($store);

    $app->page('/fan', function (Context $c) use ($afterRead): void {
        $c->addScope(Scope::routeScope('/fan'));
        $count = $c->signal(1, 'count', Scope::ROUTE);
        $c->view(function () use ($c, $count, $afterRead): string {
            $html = 'count=' . $count->int();
            $afterRead($c, $count);

            return $html;
        });
    });

    return $app;
}

/** @return list<Context> three /fan contexts, rendered in this order by a fan-out */
function mountFans(Via $app): array {
    $contexts = [];
    foreach (['1', '2', '3'] as $n) {
        $ctx = new Context('/fan_/' . $n, '/fan', $app, null, 'sess1');
        $app->contexts[$ctx->getId()] = $ctx;
        $app->getApp()->registerContext($ctx);
        $app->registerContextInScope($ctx, Scope::TAB);
        $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/fan'], $ctx, []);
        $contexts[] = $ctx;
    }

    return $contexts;
}

/**
 * @param list<Context> $contexts
 *
 * @return list<list<string>> the elements frames each context got since the last call
 */
function fanFrames(array $contexts): array {
    return array_map(static function (Context $ctx): array {
        $frames = [];
        while (($patch = $ctx->getPatch()) !== null) {
            if ($patch['type'] === 'elements') {
                $frames[] = (string) $patch['content'];
            }
        }

        return $frames;
    }, $contexts);
}

test('a fan-out renders every context from one read of each scoped signal', function (): void {
    $store = new SharedSignalStore(maxRows: 64);
    $writeOnce = true;
    $app = fanWorker($store, function (Context $c, Signal $count) use ($store, &$writeOnce): void {
        if ($writeOnce) {
            $writeOnce = false;
            $store->set($count->sharedKey(), 99);
        }
    });
    $contexts = mountFans($app);

    $app->broadcast(Scope::routeScope('/fan'));

    expect(fanFrames($contexts))->toBe([['count=1'], ['count=1'], ['count=1']], 'one frame, one value');
    expect($store->readEpoch())->toBe(0);

    $app->broadcast(Scope::routeScope('/fan'));

    expect(fanFrames($contexts))->toBe([['count=99'], ['count=99'], ['count=99']]);
});

test('a write in a view is read back by the rest of the fan-out', function (Closure $write): void {
    $store = new SharedSignalStore(maxRows: 64);
    $writeOnce = true;
    $app = fanWorker($store, function (Context $c, Signal $count) use ($write, &$writeOnce): void {
        if ($writeOnce) {
            $writeOnce = false;
            $write($count);
        }
    });
    $contexts = mountFans($app);

    $app->broadcast(Scope::routeScope('/fan'));

    expect(fanFrames($contexts))->toBe([['count=1'], ['count=5'], ['count=5']]);
})->with([
    'setValue' => [static fn (Signal $s) => $s->setValue(5, true, false)],
    'increment' => [static fn (Signal $s) => $s->increment(4, false)],
    'mutate' => [static fn (Signal $s) => $s->mutate(static fn (mixed $v): int => (int) $v + 4, false)],
]);

test('a view that broadcasts its own scope gets a re-run that reads shared memory again', function (): void {
    $store = new SharedSignalStore(maxRows: 64);
    $once = true;
    $app = null;
    $app = fanWorker($store, function (Context $c, Signal $count) use ($store, &$once, &$app): void {
        if ($once) {
            $once = false;
            $store->set($count->sharedKey(), 7);
            $app->broadcast(Scope::routeScope('/fan'));
        }
    });
    $contexts = mountFans($app);

    $app->broadcast(Scope::routeScope('/fan'));

    expect(fanFrames($contexts))->toBe([['count=1', 'count=7'], ['count=1', 'count=7'], ['count=1', 'count=7']]);
    expect($store->readEpoch())->toBe(0);
});

test('a throwing view leaves no snapshot open, and later reads go to shared memory', function (): void {
    $store = new SharedSignalStore(maxRows: 64);
    $app = fanWorker($store, function (Context $c): void {
        if ($c->getId() === '/fan_/2') {
            throw new RuntimeException('view failed');
        }
    });
    $contexts = mountFans($app);
    $count = $contexts[0]->getSignal('count');

    ob_start();
    $app->broadcast(Scope::routeScope('/fan'));
    $log = (string) ob_get_clean();

    expect($log)->toContain('view failed');
    expect($store->readEpoch())->toBe(0);
    $store->set($count->sharedKey(), 3);
    expect($count->int())->toBe(3);
});
