<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * Known limitation: scoped signal VALUES are per-worker.
 *
 * SignalManager::$signals is a plain PHP array, so a ROUTE/SESSION/GLOBAL-scoped signal is
 * shared between the contexts of ONE worker and no further. Two Via instances stand in for two
 * worker processes here — each gets its own SignalManager, exactly as separate processes do.
 *
 * This pins the constraint because it inverts the order the multi-worker work has to land in.
 *
 * The visible multi-worker failure today is `HTTP 400 Invalid context`: a context lives on the
 * worker that served its page, and ActionHandler — unlike SseHandler — never tries to revive one
 * it has not seen, so action success tracks 1/worker_num (measured: 100/51/26/13/6.9% at
 * 1/2/4/8/16 workers, see PERFORMANCE.md).
 *
 * Giving ActionHandler that revival fallback looks like the fix, and it is the next item in the
 * backlog. But a revived context re-runs the route handler on the receiving worker, which
 * re-initialises its signals from their declared defaults in THAT worker's SignalManager. The
 * action then mutates a copy nobody is watching: the client's SSE stream is attached to the
 * originating worker, which still renders its own value.
 *
 * So the fallback on its own would convert a loud 400 into a silent wrong answer — strictly
 * worse. Shared scoped-signal VALUES have to land with it, not after it.
 *
 * When that happens, this test should start failing and be rewritten to assert sharing.
 */

/** A Via instance standing in for one worker process. */
function workerApp(): Via {
    $app = new Via((new Config())->withLogLevel('error'));

    $app->page('/probe', function (Context $c): void {
        $c->scope(Scope::ROUTE);
        $count = $c->signal(0, 'count');
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

test('a ROUTE-scoped signal is NOT shared across workers', function (): void {
    $workerA = workerApp();
    $workerB = workerApp();

    // The same context id mounted on both workers: A served the page and holds the client's
    // SSE stream; B is the worker an action happened to land on.
    $onA = mountProbe($workerA, '/probe_/shared');
    $onB = mountProbe($workerB, '/probe_/shared');

    for ($i = 0; $i < 3; ++$i) {
        $onB->executeAction('bump');
    }

    expect($onB->renderView())->toBe('count=3', 'the worker that ran the actions sees them');
    expect($onA->renderView())->toBe(
        'count=0',
        'KNOWN LIMITATION: the worker holding the client SSE stream sees none of them. '
        . 'When shared scoped values land, rewrite this to expect count=3.'
    );
});
