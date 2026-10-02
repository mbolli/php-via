<?php

declare(strict_types=1);

use OpenSwoole\Coroutine\Http\Client;

/*
 * A tab that leaves and comes back must end up registered exactly once, wherever it lands.
 *
 * - A new stream for a context re-registered the tab, then recreatePatchChannel() ended the old
 *   stream, which still counted as the last one and unregistered it again: the tab dropped out
 *   of its scopes and getClients() while connected. 0.13.0 hit this on every HTTP/1.1 reconnect of
 *   an idle tab, because it never noticed the old connection close.
 * - A browser that cancels one HTTP/2 stream keeps the connection, so no close event fires; a
 *   per-worker check ends such a stream, and a patch queued to it waits for the next stream.
 * - With two workers, the worker that lost the stream shortens the directory record to the
 *   revival window when it destroys its copy. The record must not expire under the live stream.
 */

/** @return array<string, string> key => value from the fixture's key=value lines */
function sseReconnectFixture(string $fixture, string $case): array {
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . "/Fixtures/{$fixture}.php")
        . ' ' . escapeshellarg($case) . ' 2>&1'
    );
    preg_match_all('/^([a-z_]+)=(\S*)$/m', $out, $m, PREG_SET_ORDER);

    $values = ['out' => $out];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }

    return $values;
}

describe('without a server', function (): void {
    test('a stream that replaces a parked one keeps the tab in its scopes and getClients()', function (): void {
        $r = sseReconnectFixture('sse_stream_cases', 'superseded');

        expect($r['first_running'] ?? null)->toBe('0', $r['out']);
        expect($r['scope_members'] ?? null)->toBe('1', $r['out']);
        expect($r['clients'] ?? null)->toBe('1', $r['out']);
        expect($r['broadcast_delivered'] ?? null)->toBe('1', $r['out']);
        expect($r['clients_after_close'] ?? null)->toBe('0', $r['out']);
        // One connected episode: the tab never left.
        expect($r['events'] ?? null)->toBe('connect,disconnect', $r['out']);
    });

    test('the reset check ends a stream the client reset, without writing to it', function (): void {
        $r = sseReconnectFixture('sse_stream_cases', 'reset');

        expect($r['running_before_check'] ?? null)->toBe('1', $r['out']);
        expect((int) ($r['ended_ms'] ?? -1))->toBeGreaterThanOrEqual(0, $r['out']);
        expect((int) ($r['ended_ms'] ?? -1))->toBeLessThan(50, $r['out']);
        expect($r['writes_after_reset'] ?? null)->toBe('0', $r['out']);
        expect($r['events'] ?? null)->toBe('connect,disconnect', $r['out']);
    });

    test('a patch queued to a reset stream goes to the next stream', function (): void {
        $r = sseReconnectFixture('sse_stream_cases', 'returned');

        expect((int) ($r['ended_ms'] ?? -1))->toBeGreaterThanOrEqual(0, $r['out']);
        expect($r['writes_after_reset'] ?? null)->toBe('0', $r['out']);
        expect($r['reconnected_got_patch'] ?? null)->toBe('1', $r['out']);
    });

    test('a stream with nothing to sync still confirms the connection at once', function (): void {
        $r = sseReconnectFixture('sse_stream_cases', 'quiet');

        // The confirmation clears a reconnect banner and flushes the headers; the page stays put.
        expect($r['connect_event'] ?? null)->toBe('1', $r['out']);
        expect($r['resent_page'] ?? null)->toBe('0', $r['out']);
    });

    test('a wake sends no keep-alive comment before the interval has passed', function (): void {
        $r = sseReconnectFixture('sse_stream_cases', 'gate');

        expect($r['woken_running'] ?? null)->toBe('1', $r['out']);
        expect($r['keepalives_after_wake'] ?? null)->toBe('0', $r['out']);
        expect((int) ($r['keepalives_after_interval'] ?? 0))->toBeGreaterThanOrEqual(1, $r['out']);
    });

    test('keep-alive 0 sends no comment', function (): void {
        $r = sseReconnectFixture('sse_stream_cases', 'off');

        expect($r['woken_running'] ?? null)->toBe('1', $r['out']);
        expect($r['keepalives_after_wake'] ?? null)->toBe('0', $r['out']);
    });
});

describe('on a real server', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
        }
    });

    test('an HTTP/2 stream reset ends at once, and a stream reopened in the same write keeps the tab', function (): void {
        $r = sseReconnectFixture('sse_reconnect_server', 'h2');

        // The keep-alive is 15 s; the reset check runs every 250 ms.
        expect((int) ($r['reset_disconnect_ms'] ?? -1))->toBeGreaterThanOrEqual(0, $r['out']);
        expect((int) ($r['reset_disconnect_ms'] ?? -1))->toBeLessThan(1000, $r['out']);

        expect($r['clients'] ?? null)->toBe('reopened', $r['out']);
        expect($r['scope'] ?? null)->toBe('reopened', $r['out']);
        expect($r['reopened_got_broadcast'] ?? null)->toBe('1', $r['out']);
        expect($r['out'])->not->toContain('response is unavailable');
    });

    test('a tab that moved to another worker can still act on the first one after the revival window', function (): void {
        $r = sseReconnectFixture('sse_reconnect_server', 'xworker');

        expect($r['workers'] ?? null)->toBe('1', 'the two streams must land on different workers: ' . $r['out']);
        expect($r['actions_on_a'] ?? null)->toBe('200,200,200', $r['out']);
    });
});
