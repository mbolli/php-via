<?php

declare(strict_types=1);

use OpenSwoole\Coroutine\Channel;

/*
 * The SSE consume loop has failed in both directions historically:
 *
 *   0c05bc7 introduced pop($pollTimeout); fcab883 reverted it two days later
 *   because pop(timeout) returns false IMMEDIATELY on a closed channel, spinning
 *   the loop at 100% CPU after context cleanup.
 *
 *   The replacement — pop(0) plus an explicit sleep — then hid a second bug:
 *   in OpenSwoole a timeout of 0 means "no timeout", so on an OPEN channel the
 *   coroutine parks indefinitely and the loop's isShuttingDown() / isWritable() /
 *   context-destroyed checks never execute. Idle SSE connections were measured
 *   stranded 61s past a 6s deadline.
 *
 * Neither attempt distinguished CHANNEL_TIMEOUT from CHANNEL_CLOSED, which is what
 * makes both safe at once. These tests pin that.
 */

test('Channel::pop(0) parks rather than returning immediately', function (): void {
    // The premise the old docblock got wrong. If this ever changes upstream, the
    // reasoning in SseHandler and PatchManager needs revisiting.
    expect(Channel::CHANNEL_TIMEOUT)->toBe(-1);
    expect(Channel::CHANNEL_CLOSED)->toBe(-2);
});

test('the SSE loop neither spins on a closed channel nor stalls on an idle one', function (): void {
    $fixture = dirname(__DIR__) . '/Fixtures/sse_loop_spin.php';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture));

    $parts = explode(' ', trim((string) $out));
    expect($parts)->toHaveCount(2, 'fixture output: ' . var_export($out, true));

    [$closedIterations, $livenessChecks] = [(int) $parts[0], (int) $parts[1]];

    // fcab883's bug produced ~800k iterations per 50ms. Exiting on CHANNEL_CLOSED
    // means a single pass.
    expect($closedIterations)->toBeLessThan(10);

    // A1's bug produced zero: the coroutine parked and never re-checked liveness.
    // At a 20ms timeout over 150ms we expect roughly 7.
    expect($livenessChecks)->toBeGreaterThan(2);
});

test('the real PatchManager bounds its park and reports a closed channel', function (): void {
    // The Pest suite runs under VIA_TEST_MODE=1, where PatchManager swaps the
    // Channel for a plain array — so the branch that has now failed twice in
    // production is the one branch the suite cannot otherwise reach. This fixture
    // runs with the flag cleared, against a real OpenSwoole Channel.
    $fixture = dirname(__DIR__) . '/Fixtures/patchmanager_channel.php';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture));

    $kv = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $kv[$k] = $v;
        }
    }

    expect($kv)->toHaveKeys(
        ['delivered', 'idle_null', 'idle_ms', 'closed_flag', 'closed_ms'],
        'fixture output: ' . var_export($out, true)
    );

    // A queued patch still comes straight back.
    expect($kv['delivered'])->toBe('1');
    expect($kv['delivered_closed'])->toBe('0');

    // Idle must park for roughly the configured 20ms keep-alive and then RETURN, so the
    // caller's liveness checks run. Parking forever is A1's stranding bug. The fixture sets
    // the poll interval to 1ms, which paces only the Dev Bar stream now.
    expect($kv['idle_null'])->toBe('1');
    expect($kv['idle_closed'])->toBe('0');
    expect((int) $kv['idle_ms'])->toBeGreaterThanOrEqual(15);
    expect((int) $kv['idle_ms'])->toBeLessThan(200);

    // A closed channel must be reported as closed, immediately — the caller exits
    // on this rather than spinning (fcab883).
    expect($kv['closed_null'])->toBe('1');
    expect($kv['closed_flag'])->toBe('1');
    expect((int) $kv['closed_ms'])->toBeLessThan(10);
});

test('a parked PatchManager wakes on demand without closing its channel', function (): void {
    $fixture = dirname(__DIR__) . '/Fixtures/patchmanager_channel.php';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture));

    $kv = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $kv[$k] = $v;
        }
    }

    expect($kv)->toHaveKeys(
        ['wake_unparked_left_nothing', 'woken_null', 'woken_closed', 'woken_ms', 'woken_then_delivered', 'backstop_s'],
        'fixture output: ' . var_export($out, true)
    );

    // A wake with nobody parked must not leave a marker for the next getPatch() to return.
    expect($kv['wake_unparked_left_nothing'])->toBe('1');

    // Woken 50ms into a 5s park: null, not a close, so the loop re-checks and parks again.
    expect($kv['woken_null'])->toBe('1');
    expect($kv['woken_closed'])->toBe('0');
    expect((int) $kv['woken_ms'])->toBeLessThan(1000);

    // The channel stays usable, so patches queued after a disconnect still carry over.
    expect($kv['woken_then_delivered'])->toBe('1');

    // withSseKeepAliveMs(0) keeps a bound on the park.
    expect($kv['backstop_s'])->toBe('60');
});
