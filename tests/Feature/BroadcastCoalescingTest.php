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

    test('a burst faster than the tick starts a flush one tick after the previous one started', function (): void {
        $r = coalescingCase('tick-gap');

        // 200 ms of broadcasts every 2 ms against a 20 ms tick and an 8 ms fan-out: about 11 flushes.
        expect($r['sent'])->toBeGreaterThan(20);
        expect($r['flushes'])->toBeGreaterThanOrEqual(3, 'the burst is flushed as it goes, not only at its end');
        expect($r['flushes'])->toBeLessThanOrEqual(13);
        // OpenSwoole timers count whole milliseconds and fire up to about 1 ms early (measured 18.9 to 21 ms).
        expect($r['minStartGapMs'])->toBeGreaterThanOrEqual(18.0);
        // Counting the tick from the end of the flush makes it 28 ms.
        expect($r['medianStartGapMs'])->toBeLessThan(25.0);

        expect($r['stats']['flushes'])->toBe($r['flushes']);
        expect($r['stats']['scheduled'])->toBe($r['sent']);
        expect($r['stats']['coalesced'])->toBe($r['sent'] - $r['flushes']);
    });

    test('a flush whose views wait on I/O starts the next one a tick after it started, once it has ended', function (): void {
        $r = coalescingCase('tick-gap-yielding');

        expect($r['flushes'])->toBeGreaterThanOrEqual(3);
        expect($r['minStartGapMs'])->toBeGreaterThanOrEqual(18.0);
        expect($r['medianStartGapMs'])->toBeLessThan(25.0, 'a tick from the end of the 10 ms pass would be 30 ms');
        expect($r['minIdleGapMs'])->toBeGreaterThanOrEqual(0.0, 'two passes of one scope never overlap');
    });

    test('a flush longer than half the tick is followed by half a tick for the rest of the worker', function (): void {
        $r = coalescingCase('tick-floor');

        // A 15 ms fan-out against a 20 ms tick: a tick from its start would leave 5 ms.
        expect($r['minIdleGapMs'])->toBeGreaterThanOrEqual(8.0, 'half the tick, less timer slack');
        expect($r['medianStartGapMs'])->toBeLessThan(30.0, 'a whole tick after the end would be 35 ms');
    });

    test('flushes longer than the tick leave half a tick to the rest of the worker and still coalesce', function (): void {
        $r = coalescingCase('tick-overrun');

        // A 30 ms fan-out against a 20 ms tick, under 300 ms of broadcasts every 2 ms: a flush every 40 ms.
        expect($r['minIdleGapMs'])->toBeGreaterThanOrEqual(8.0, 'half the tick, less timer slack');
        expect($r['medianIdleGapMs'])->toBeLessThan(16.0, 'a whole tick after the end would be 20 ms');
        // Back-to-back flushes gave each other coroutine one step per flush: 1 broadcast and 1 wake.
        expect($r['sent'] / $r['flushes'])->toBeGreaterThanOrEqual(3.0);
        expect($r['wakes'] / $r['flushes'])->toBeGreaterThanOrEqual(4.0);
        expect($r['flushes'])->toBeLessThanOrEqual(10);
    });

    test('a broadcast after a flush that overran the tick waits half a tick after that flush ended', function (): void {
        $r = coalescingCase('overrun-follow-up');

        expect($r['flushes'])->toBe(2);
        expect($r['timer'])->toBeTrue('the tick counted from the start of the 60 ms flush has passed, the floor has not');
        expect($r['waitMs'])->toBeGreaterThan(20.0, 'half the 50 ms tick after the flush ended');
        expect($r['waitMs'])->toBeLessThan(35.0, 'a whole tick after the flush ended would be 50 ms');
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('a broadcast made while an overrunning flush waits on I/O is flushed half a tick after that flush ends', function (): void {
        $r = coalescingCase('overrun-pending');

        expect($r['flushes'])->toBe(2);
        expect($r['minIdleGapMs'])->toBeGreaterThanOrEqual(8.0, 'half the 20 ms tick, less timer slack');
        expect($r['minIdleGapMs'])->toBeLessThan(16.0, 'a whole tick after the 40 ms pass would be 20 ms');
        expect($r['flags'])->toBe(COALESCE_IDLE);
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

    test('with coalescing off, a view that broadcasts its own scope stops after 8 passes and is not handed on', function (): void {
        $r = coalescingCase('self-broadcast-sync');

        expect($r['renders'])->toBe(8);
        expect($r['logs'])->toHaveCount(1);
        expect($r['logs'][0])->toContain('Broadcast re-entrancy limit reached for scope "room:self"');
        expect($r['flags'])->toBe(COALESCE_IDLE);
    });

    test('a write that other actions broadcast during the last capped re-run still reaches clients', function (): void {
        foreach (coalescingCase('writes-at-cap') as $mode => $r) {
            // Coalescing off: 8 re-runs of the pass, then the next flush renders the last write.
            expect($r['renders'])->toBe(9, $mode);
            expect($r['lastSeen'])->toBe($r['final'], $mode);
            expect($r['logs'])->toBe([], "{$mode}: no view broadcasts its own scope");
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('a writer the action started before it broadcast counts as another action', function (): void {
        foreach (coalescingCase('writer-started-by-action') as $mode => $r) {
            expect($r['renders'])->toBe(9, $mode);
            expect($r['lastSeen'])->toBe($r['final'], $mode);
            expect($r['logs'])->toBe([], $mode);
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('during shutdown a broadcast still owed after 8 passes is dropped and leaves nothing marked', function (): void {
        foreach (coalescingCase('shutdown-at-cap') as $mode => $r) {
            expect($r['renders'])->toBe(8, $mode);
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('a view that broadcasts its own scope on its first render only renders twice, with no warning', function (): void {
        foreach (coalescingCase('self-broadcast-once') as $mode => $r) {
            expect($r['renders'])->toBe(2, $mode);
            expect($r['logs'])->toBe([], $mode);
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('a view that broadcasts its own scope from a coroutine it starts stops after 8 passes', function (): void {
        foreach (coalescingCase('self-broadcast-spawned') as $mode => $r) {
            expect($r['renders'])->toBe(8, $mode);
            expect($r['logs'])->toHaveCount(1);
            expect($r['logs'][0])->toContain('Broadcast re-entrancy limit reached for scope "room:spawn"');
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('a view that broadcasts its own scope in 7 of 8 passes is not a loop, and its last broadcast renders', function (): void {
        foreach (coalescingCase('self-broadcast-after-outside-rerun') as $mode => $r) {
            // Coalescing off: another action re-runs pass 1, the view re-runs passes 2 to 8, and the next flush renders the rest.
            expect($r['renders'])->toBe(9, $mode);
            expect($r['lastSeen'])->toBe($r['final'], $mode);
            expect($r['logs'])->toBe([], $mode);
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('a context that newer fan-outs finish first 8 times in a row is rendered again by the next flush', function (): void {
        foreach (coalescingCase('overtaken-at-cap') as $mode => $r) {
            expect(end($r['frames']))->toBe("<div id=\"c\">{$r['final']}</div>", $mode);
            expect($r['logs'])->toHaveCount(1);
            expect($r['logs'][0])->toContain('Newer fan-outs finished context c first 8 times');
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
    });

    test('a view that broadcasts its own scope under load from other actions stops, and their last write still renders', function (): void {
        foreach (coalescingCase('self-broadcast-under-load') as $mode => $r) {
            // 8 passes while the writes come in, then 8 for the last one, each ending in the warning.
            expect($r['renders'])->toBe(16, $mode);
            expect($r['lastSeen'])->toBe($r['final'], $mode);
            expect($r['logs'])->toHaveCount(2);
            expect(implode("\n", $r['logs']))->toContain('Broadcast re-entrancy limit reached for scope "room:load"');
            expect($r['flags'])->toBe(COALESCE_IDLE, $mode);
        }
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

describe('Config::withBroadcastThrottle()', function (): void {
    test('renders a scope that broadcasts every 5 ms once per interval, with the last state, and leaves other scopes alone', function (): void {
        $r = coalescingCase('throttle-burst');

        // 400 ms of broadcasts with a 100 ms throttle: the leading render and one per interval after it.
        expect($r['renders'])->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(6)
            ->and($r['minGapMs'])->toBeGreaterThanOrEqual(99.0)
            ->and($r['lastSeen'])->toBe($r['final'], 'the last broadcast in the burst is delivered')
            ->and($r['freeRenders'])->toBeGreaterThan(20)
            ->and($r['flags'])->toBe(COALESCE_IDLE)
            ->and($r['throttleTimer'])->toBeNull()
        ;
    });

    test('renders the first broadcast at once and holds one right after it until the interval is over', function (): void {
        $r = coalescingCase('throttle-trailing');

        expect($r['renders'])->toBe(2)
            ->and($r['trailingAfterMs'])->toBeGreaterThanOrEqual(99.0)->toBeLessThan(160.0)
        ;
    });

    test('throttles the broadcasts of scoped signal writes', function (): void {
        // Five writes 10 ms apart: the leading render and the trailing one 100 ms later.
        expect(coalescingCase('throttle-signal-writes')['renders'])->toBe(2);
    });

    test('flushBroadcasts() renders a held broadcast at once', function (): void {
        expect(coalescingCase('throttle-flush'))->toBe(['first' => 1, 'held' => 1, 'flushed' => 2, 'final' => 2]);
    });

    test('throttles broadcasts received from other workers', function (): void {
        // 250 ms of received broadcasts with a 100 ms throttle: at 0, 100 and 200 ms, and the trailing one.
        expect(coalescingCase('throttle-received')['renders'])->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(4);
    });

    test('coalesces a throttled scope under withBroadcastCoalescing(false), which renders the others at once', function (): void {
        expect(coalescingCase('throttle-coalescing-off'))->toBe(['sync' => ['free' => 5], 'final' => ['free' => 5, 'imp' => 1]]);
    });
});
