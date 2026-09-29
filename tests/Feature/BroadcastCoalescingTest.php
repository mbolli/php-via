<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use OpenSwoole\Http\Request;
use Tests\Support\FakeStaticResponse;

/*
 * Plain Pest calls broadcast() outside any coroutine, which takes the synchronous path, so the code
 * production runs is only reached here: each scenario in Fixtures/broadcast_coalescing_cases.php runs
 * inside Coroutine::run in a process of its own (the fixture says why).
 */

/** @return array<string, mixed> what the scenario observed */
function coalescingCase(string $case): array {
    $fixture = dirname(__DIR__) . '/Fixtures/broadcast_coalescing_cases.php';
    $out = trim((string) shell_exec(
        'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($case) . ' 2>&1'
    ));
    $lines = explode("\n", $out);
    $data = json_decode((string) end($lines), true);

    expect($data)->toBeArray('fixture output: ' . var_export($out, true));
    expect($data)->not->toHaveKey('error', 'fixture output: ' . var_export($out, true));

    return $data;
}

const COALESCE_IDLE = ['flushScheduled' => false, 'flushTimerId' => null, 'runningFlushes' => [], 'publishing' => false, 'dirtyScopes' => [], 'unpublishedScopes' => []];

describe('inside a coroutine', function (): void {
    test('broadcasts in one turn render once, with the state at the end of the turn', function (): void {
        $r = coalescingCase('one-turn');

        expect($r['before'])->toBeNull('broadcast() must not render before the turn ends');
        expect($r['renders'])->toBe(['obs' => 1]);
        expect($r['patches'])->toHaveCount(1);
        expect($r['patches'][0]['content'])->toContain('v=3');
    });

    test('scoped signal writes in one action fan out once', function (): void {
        $r = coalescingCase('signal-writes');

        expect($r['renders'])->toBe(['o1' => 1, 'o2' => 1]);
        foreach ($r['patches'] as $patches) {
            $signals = array_values(array_filter($patches, static fn (array $p): bool => $p['type'] === 'signals'));
            expect($signals)->toHaveCount(1);
            expect(json_encode($signals[0]['content']))->toContain('5');
        }
    });

    test('a #[Signal] action that writes five shared properties fans out once', function (): void {
        // Each of the five GLOBAL writes in syncBack() used to fan out to every context.
        expect(coalescingCase('mount-writes')['renders'])->toBe(['o1' => 1, 'o2' => 1]);
    });

    test('broadcasts from several coroutines in one turn share one fan-out and one publish', function (): void {
        $r = coalescingCase('many-coroutines');

        expect($r['renders'])->toBe(['obs' => 1]);
        expect($r['published'])->toBe(['publish room:many'], 'TAB is never published');
    });

    test('received broadcasts coalesce and are never published again', function (): void {
        $r = coalescingCase('received');

        expect($r['renders'])->toBe(['obs' => 1]);
        expect($r['published'])->toBe([]);
    });

    test('a context in several broadcast scopes renders once per flush', function (): void {
        expect(coalescingCase('several-scopes')['renders'])->toBe(['both' => 1, 'onlyB' => 1]);
    });

    test('a broadcast invalidates the cached view before the flush, so an earlier sync() is not stale', function (): void {
        expect(coalescingCase('eager-invalidation')['content'])->toContain('v=2');
    });

    test('a broadcast during a pass invalidates the cached view once the pass ends', function (): void {
        expect(coalescingCase('invalidate-after-pass')['content'])->toContain('v=2');
    });

    test('a context reached through another scope does not render a primary-scope cache refilled after the mark', function (): void {
        expect(coalescingCase('pre-invalidation')['multiLast'])->toContain('v=2');
    });

    test('each broadcast() call leaves a broadcast.schedule span in the action trace', function (): void {
        $traces = coalescingCase('trace-schedule')['traces'];

        expect($traces['POST /_action/send'])->toContain(['broadcast.schedule', ['scope' => 'room:trace']]);
        expect(array_keys($traces))->toContain('broadcast room:trace');
    });

    test('a context released before the flush is skipped', function (): void {
        expect(coalescingCase('released')['renders'])->toBe([]);
    });

    test('flushBroadcasts() delivers the fan-out before a script queued after it', function (): void {
        $r = coalescingCase('flush-order');

        expect($r['without']['types'])->toBe(['script', 'elements']);
        expect($r['with']['types'])->toBe(['elements', 'script']);
        expect($r['with']['renders'])->toBe(['obs' => 1], 'the flush consumed the pending broadcast');
    });

    test('flushBroadcasts() does not wait for another scope\'s flush that waits on I/O', function (): void {
        $r = coalescingCase('flush-beside-slow');

        expect($r['fast'])->toBe(1);
        expect($r['returnedMs'])->toBeLessThan(50.0, 'the other flush takes 500 ms');
    });

    test('flushBroadcasts() waits for a running fan-out of its own scope, then renders the latest state', function (): void {
        $r = coalescingCase('flush-own-scope');

        expect(array_slice($r['types'], -2))->toBe(['elements', 'script']);
        expect($r['beforeScript'])->toContain('v=2');
    });

    test('flushBroadcasts() gives up on a hung fan-out of its scope after 1 s and says so', function (): void {
        $r = coalescingCase('flush-gives-up');

        expect($r['returnedMs'])->toBeGreaterThan(900.0);
        expect($r['returnedMs'])->toBeLessThan(1_500.0);
        $log = implode("\n", $r['logs']);
        expect($log)->toContain('flushBroadcasts() stopped waiting for the running fan-out of "room:hung"');
        expect($log)->toContain('The fan-out of scope "room:hung" has been running for 1.');
        expect($r['renders'])->toBe(2, 'the latest state renders once the hung pass ends');
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('with coalescing off every broadcast renders before it returns', function (): void {
        expect(coalescingCase('coalescing-off')['seen'])->toBe([1, 2, 3]);
    });
});

describe('broadcast tick', function (): void {
    test('an idle worker flushes at the end of the turn without waiting for the tick', function (): void {
        $r = coalescingCase('idle-leading-edge');

        expect($r['idleDeferred'])->toBeTrue('scheduled with Event::defer, not a timer');
        expect($r['afterTurn'])->toBe(1, 'flushed 1 ms later with a 300 ms tick');
        // Right after that flush the next broadcast waits for the tick.
        expect($r['busyTimer'])->toBeTrue();
        expect($r['after100ms'])->toBe(1);
        expect($r['final'])->toBe(2);
    });

    test('a burst faster than the tick flushes at least one tick apart', function (): void {
        $r = coalescingCase('tick-gap');

        // 200 ms of broadcasts every 2 ms against a 20 ms tick and a 3 ms fan-out: about 80 sent, 10 flushes.
        expect($r['sent'])->toBeGreaterThan(20);
        expect($r['flushes'])->toBeGreaterThanOrEqual(3, 'the burst is flushed as it goes, not only at its end');
        expect($r['flushes'])->toBeLessThanOrEqual(12);
        // OpenSwoole timers count whole milliseconds and fire up to about 1 ms early (measured 18.9 to 21 ms).
        expect($r['minGapMs'])->toBeGreaterThanOrEqual(18.0);

        expect($r['stats']['flushes'])->toBe($r['flushes']);
        expect($r['stats']['scheduled'])->toBe($r['sent']);
        expect($r['stats']['coalesced'])->toBe($r['sent'] - $r['flushes']);
    });

    test('a flush whose views wait on I/O still starts the next one a tick after it ended', function (): void {
        $r = coalescingCase('tick-gap-yielding');

        expect($r['flushes'])->toBeGreaterThanOrEqual(3);
        // Broadcasts made during the 10 ms pass must not start the next flush a tick after it started.
        expect($r['minGapMs'])->toBeGreaterThanOrEqual(18.0);
    });

    test('a tick of 0 flushes every turn with no gap', function (): void {
        expect(coalescingCase('tick-zero'))->toBe(['timer' => false, 'renders' => 2]);
    });

    test('a tick timer dropped by Timer::clearAll() does not strand later broadcasts', function (): void {
        $r = coalescingCase('clear-all');

        expect($r['armed'])->toBeTrue();
        expect($r['stranded'])->toBe(1, 'the dropped timer never fires');
        expect($r['renders'])->toBe(2, 'the next broadcast notices and schedules again');
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('shutdown flushes and publishes what waits for the tick before the timers are cleared', function (): void {
        $r = coalescingCase('shutdown-drain');

        expect($r['armed'])->toBeTrue();
        expect($r['rendered'])->toBe([1, 2, 'callback'], 'the pending frame rendered before the channels closed');
        expect($r['events'])->toBe(['publish room:drain', 'publish room:drain', 'disconnect']);
        expect($r['elapsedMs'])->toBeLessThan(2_000.0, 'nothing waited for the 5 s tick');
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('shutdown does not wait for a flush whose views wait on I/O', function (): void {
        $r = coalescingCase('shutdown-budget');

        expect($r['callbackMs'])->toBeLessThan(200.0, 'the running flush takes 500 ms');
        expect($r['disconnectMs'])->toBeLessThan(300.0);
        expect($r['events'])->toBe(['publish room:io', 'publish room:io', 'disconnect'], 'the owed publish still goes out');
    });

    test('shutdown lets a running publisher send what the shutdown callbacks broadcast before disconnecting', function (): void {
        expect(coalescingCase('shutdown-publisher')['events'])->toBe(['publish room:busy', 'publish room:bye', 'disconnect']);
    });

    test('flush stats count overruns of the tick', function (): void {
        $stats = coalescingCase('metrics')['stats'];

        expect($stats['scheduled'])->toBe(3);
        expect($stats['coalesced'])->toBe(2);
        expect($stats['flushes'])->toBe(1);
        expect($stats['flush_overruns'])->toBe(1, 'an 8 ms flush against a 5 ms tick');
        expect($stats['flush_max_ms'])->toBeGreaterThanOrEqual(8.0);
        expect($stats['flush_last_ms'])->toBe($stats['flush_max_ms']);
    });
});

describe('serialization', function (): void {
    test('a fan-out is not interleaved by a second broadcast of its scope', function (): void {
        $r = coalescingCase('split');

        $twoPasses = [0, 1, 2, 3, 4, 5, 0, 1, 2, 3, 4, 5];
        expect($r['nested'])->toBe($twoPasses);
        // The second pass waits for the first; the other scope does not.
        expect($r['suspended'])->toBe([0, 1, 'other', 2, 3, 4, 5, 0, 1, 2, 3, 4, 5]);
    });

    test('a flush waiting on its views\' I/O holds up neither other scopes nor their publishes', function (): void {
        $r = coalescingCase('head-of-line');

        expect($r['fast'])->toBe(1, 'rendered one tick after the slow flush started');
        expect($r['published'])->toBe(['publish room:slow', 'publish room:fast']);
        expect($r['slow'])->toBe(5);
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('broadcasts from actions during a pass that waits on I/O go to the next tick, not a re-run of that flush', function (): void {
        $r = coalescingCase('yielding-steady');

        expect($r['logs'])->toBe([]);
        expect($r['passes'])->toBeLessThanOrEqual($r['flushes']);
        // 300 ms of broadcasts every 2 ms, a 3 ms pass and a 20 ms tick: about 13 passes.
        expect($r['passes'])->toBeLessThanOrEqual(20);
        expect($r['lastSeen'])->toBe([$r['final']], 'every client ends on the last write');
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('a view broadcasting another scope under steady load is not taken for a chain', function (): void {
        $r = coalescingCase('chain-no-cycle');

        expect($r['logs'])->toBe([]);
        expect($r['bLast'])->toBe($r['final']);
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('a view that broadcasts its own scope stops after 8 passes', function (): void {
        $r = coalescingCase('self-broadcast');

        expect($r['renders'])->toBe(8);
        expect(implode("\n", $r['logs']))->toContain('Broadcast re-entrancy limit reached for scope "room:self"');
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('views broadcasting each other\'s scopes stop after 8 flushes, and later broadcasts still flush', function (): void {
        $r = coalescingCase('ping-pong');

        expect($r['chain'])->toBe(['ping' => 4, 'pong' => 4]);
        expect(implode("\n", $r['logs']))->toContain('Broadcast chain limit reached for scope "room:ping"');
        expect($r['calm'])->toBe(1);
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });
});

describe('failures', function (): void {
    test('a failing publish or fan-out is logged, and the next broadcast still flushes', function (): void {
        $r = coalescingCase('failures');

        expect($r['returned'])->toBe('action finished');
        $log = implode("\n", $r['logs']);
        expect($log)->toContain('Broker publish of room:fail failed: RuntimeException: broker down');
        expect($log)->toContain('Broadcast of room:fail failed: LogicException: fan-out bookkeeping failed');
        expect($r['flagsAfterFailure'])->toBe(COALESCE_IDLE);

        expect($r['renders'])->toBe(2);
        expect($r['patches'][0]['content'] ?? '')->toContain('v=2');
        expect($r['published'])->toBe(['publish room:fail']);
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('only one coroutine publishes at a time, even when publish() yields', function (): void {
        $expected = array_map(static fn (int $i): string => "publish room:p{$i}", range(0, 19));
        sort($expected);

        // Coalescing off is the synchronous path, which must not share the connection either.
        foreach (coalescingCase('single-publisher') as $mode => $r) {
            expect($r['maxInFlight'])->toBe(1, $mode);
            $published = array_unique($r['published']);
            sort($published);
            expect($published)->toBe($expected, $mode);
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('broadcasts made during shutdown reach the broker before it disconnects', function (): void {
        expect(coalescingCase('shutdown')['events'])->toBe(['publish room:bye', 'disconnect']);
    });
});

test('on a real server, broadcasts publish once per turn and received messages fan out once', function (): void {
    $fixture = dirname(__DIR__) . '/Fixtures/broadcast_coalescing_server.php';
    $out = sys_get_temp_dir() . '/via_coalescing_' . bin2hex(random_bytes(6));

    try {
        shell_exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($out) . ' > /dev/null 2>&1');
        $raw = (string) @file_get_contents($out);
    } finally {
        @unlink($out);
    }

    expect($raw)->toMatch('/phase1=\d+\s+phase2=\d+/', 'fixture output: ' . var_export($raw, true));
    preg_match('/phase1=(\d+)/', $raw, $p1);
    preg_match('/phase2=(\d+)/', $raw, $p2);

    // Ten broadcasts in one turn are one publish, so one message and one render over there.
    expect((int) $p1[1])->toBe(1);
    // Ten pipe messages in a burst, which reach the worker over several turns: the leading-edge
    // flush and then one more a tick later.
    expect((int) $p2[1] - (int) $p1[1])->toBeLessThanOrEqual(2);
});

test('the dev-mode /_stats endpoint reports the worker\'s broadcast flush stats', function (): void {
    $via = createVia((new Config())->withDevMode()->withBroadcastTickMs(40));
    $request = new Request();
    $request->server = ['request_uri' => '/_stats', 'request_method' => 'GET'];
    $request->header = [];
    $response = new FakeStaticResponse();

    (new RequestHandler($via, new SseHandler($via), new ActionHandler($via)))->handleRequest($request, $response);

    expect($response->statusCode)->toBe(200);
    // JSON turns the 0.0 timings into 0.
    expect(json_decode($response->body, true)['broadcast_stats'] ?? null)->toEqual(['tick_ms' => 40, ...$via->getStats()->getBroadcastStats()]);
});
