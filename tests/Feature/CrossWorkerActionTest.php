<?php

declare(strict_types=1);
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\State\SharedContextDirectory;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine\Http\Client;

/*
 * Actions must succeed on any worker, not just the one that served the page.
 *
 * A context lives in the worker that created it. ActionHandler used to answer HTTP 400
 * "Invalid context" for anything else, so action success tracked 1/worker_num — measured
 * 100/51/26/13/6.9% at 1/2/4/8/16 workers (PERFORMANCE.md).
 *
 * SseHandler always rebuilt an unknown context by re-running its route handler under the same
 * ID; ActionHandler now does the same. That needs two things the old revival records could not
 * supply:
 *
 *   - a record written at context CREATION, not destruction — a context alive on another worker
 *     has no revival record at all (SharedContextDirectory);
 *   - scoped signal values that cross a process boundary, or the rebuilt context would mutate a
 *     copy nobody is watching, turning the 400 into a silent wrong answer (SharedSignalStore).
 *
 * This drives a real multi-worker server end to end: one page load pins the context to one
 * worker, then N actions go over fresh connections so dispatch spreads them everywhere, and the
 * counter is read back through yet another connection.
 *
 * N is kept modest deliberately. At 200 concurrent actions the test still passed standalone
 * every time but flaked roughly one run in four inside the full suite, where it competes for
 * CPU with other server fixtures — a machine-load artefact, not a routing failure. 60 still
 * forces every worker to revive the context, which is the property under test.
 */

/** @return array{ok: int, failed: int, final: int, expected: int} */
function crossWorkerActions(int $workers, int $actions, string $mode): array {
    $fixture = dirname(__DIR__) . '/Fixtures/cross_worker_action.php';

    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
        . ' ' . $workers . ' ' . $actions . ' ' . escapeshellarg($mode) . ' 2>&1'
    );

    $parsed = [];
    foreach (['ok', 'failed', 'final', 'expected'] as $field) {
        expect($out)->toMatch("/{$field}=-?\\d+/", 'fixture output: ' . var_export($out, true));
        preg_match("/{$field}=(-?\\d+)/", $out, $m);
        $parsed[$field] = (int) $m[1];
    }

    return $parsed;
}

beforeEach(function (): void {
    if (!class_exists(Client::class)) {
        $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
    }
});

test('every action is served regardless of which worker it lands on', function (): void {
    $r = crossWorkerActions(workers: 4, actions: 60, mode: 'increment');

    expect($r['failed'])->toBe(0, 'no action may answer 400 Invalid context');
    expect($r['ok'])->toBe(60);
});

test('the mutation is visible from a worker that did not serve the action', function (): void {
    // The read-back is a fresh connection, so it lands on an arbitrary worker. Serving the
    // action is only half the fix; the value has to be shared or the client never sees it.
    $r = crossWorkerActions(workers: 4, actions: 60, mode: 'increment');

    // Compared against actions SERVED, not actions sent. Every served action must be reflected
    // in the shared counter — that is the property under test. Comparing against the sent count
    // instead folds in the driver's own transport, and a single client-side timeout under 200
    // concurrent requests then reads as a lost update, which it is not.
    expect($r['ok'])->toBeGreaterThan(0);
    expect($r['final'])->toBe($r['ok'], 'every served action must be visible in the shared value');
});

test('read-modify-write over HTTP is served, whatever it does to the value', function (): void {
    // Routing must be fixed regardless of mutation shape. This deliberately does NOT assert
    // that updates are lost: at this request volume the writes often do not overlap, so an
    // assertion that the race occurs is itself a race. The lost-update behaviour and the
    // mutate() fix are pinned deterministically in ScopedSignalSharingTest, which drives
    // forked workers in tight loops instead of HTTP.
    $r = crossWorkerActions(workers: 4, actions: 60, mode: 'setValue');

    expect($r['failed'])->toBe(0);
    expect($r['final'])->toBeGreaterThan(0);
    expect($r['final'])->toBeLessThanOrEqual($r['ok'], 'a lossy path must never gain updates');
});

test('reviving a context does not consume the directory entry other workers need', function (): void {
    // reviveContextFromClient() dropped the revival record after rebuilding — correct when a
    // record only ever described a DESTROYED context, since rebuilding made it redundant. With
    // the shared directory the same entry is how every OTHER worker rebuilds the context, so
    // consuming it meant only the first worker to revive succeeded and the rest went back to
    // answering 400. Measured before the fix: 197/200 concurrent actions at 16 workers.
    $app = new Via((new Config())->withLogLevel('error'));
    $app->getApp()->setContextDirectory(new SharedContextDirectory(maxRows: 64));

    $app->page('/probe', function (Context $c): void {
        $c->signal(0, 'n');
        $c->view(fn (): string => 'ok');
    });

    $contextId = '/probe_/revive-me';
    $ctx = new Context($contextId, '/probe', $app, null, 'sess1');
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($contextId, 'sess1');
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/probe'], $ctx, []);

    expect($app->getApp()->getRevivable($contextId))->not->toBeNull('the page load must publish a record');

    // Simulate the context being absent on this worker, then revived by an incoming action.
    unset($app->contexts[$contextId]);
    expect($app->reviveContextFromClient($contextId, 'sess1', []))->not->toBeNull();

    // A second worker must still be able to rebuild it.
    expect($app->getApp()->getRevivable($contextId))->not->toBeNull(
        'the directory entry must survive revival — other workers still need it'
    );
});
