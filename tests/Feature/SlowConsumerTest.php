<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Via;

/*
 * Slow-consumer backpressure.
 *
 * Measured against a real server with a client that never reads: writes 0-100
 * returned in ~0.05ms each (~6.4MB buffered), then write 101 PARKED for 20s and
 * returned false only when the client finally disconnected. isWritable() stayed
 * true throughout, and worker memory delta was 0KB: the buffering happens in the
 * master reactor, not the worker heap (POOL_MODE sends go through the IPC pipe).
 *
 * So send_yield already provides backpressure, but as an unbounded coroutine park.
 * That undoes the liveness work in 1af5888: a coroutine parked in write() is not
 * observing isShuttingDown(), isWritable(), or the context-destroyed safety valve
 * any more than one parked in pop() was.
 *
 * getClientInfo() exposes send_queued_bytes, so the connection's backlog can be
 * checked BEFORE writing: 0.316us per call, negligible against a patch write.
 * Element patches are idempotent full-fragment morphs where the latest supersedes
 * the rest, so dropping one for a backed-up client is safe. Signals and scripts
 * are never dropped: signals are deltas (self-healing only because delivery is
 * acknowledged, c88a8d5) and scripts are one-shot side effects with no resend path.
 */

test('only element patches are droppable', function (): void {
    // The whole safety argument rests on idempotence, so it is asserted per type.
    expect(SseHandler::shouldDropFrame('elements', 2_000_000, 1_000_000))->toBeTrue();
    expect(SseHandler::shouldDropFrame('signals', 2_000_000, 1_000_000))->toBeFalse();
    expect(SseHandler::shouldDropFrame('script', 2_000_000, 1_000_000))->toBeFalse();
});

test('nothing is dropped while the connection is keeping up', function (): void {
    expect(SseHandler::shouldDropFrame('elements', 0, 1_000_000))->toBeFalse();
    expect(SseHandler::shouldDropFrame('elements', 999_999, 1_000_000))->toBeFalse();
    expect(SseHandler::shouldDropFrame('elements', 1_000_000, 1_000_000))->toBeFalse();
    expect(SseHandler::shouldDropFrame('elements', 1_000_001, 1_000_000))->toBeTrue();
});

test('a non-positive threshold disables dropping entirely', function (): void {
    expect(SseHandler::shouldDropFrame('elements', PHP_INT_MAX, 0))->toBeFalse();
    expect(SseHandler::shouldDropFrame('elements', PHP_INT_MAX, -1))->toBeFalse();
});

test('a connection stays backed up until its backlog is empty', function (): void {
    // OpenSwoole wakes a write parked on a full backlog only once it is empty, and the backlog can shrink below the
    // threshold before that as the kernel takes more of it.
    expect(SseHandler::shouldDropFrame('elements', 400_000, 1_000_000, backedUp: true))->toBeTrue()
        ->and(SseHandler::shouldDropFrame('elements', 0, 1_000_000, backedUp: true))->toBeFalse()
        ->and(SseHandler::shouldDropFrame('elements', 400_000, 1_000_000))->toBeFalse()
        ->and(SseHandler::shouldDropFrame('signals', 400_000, 1_000_000, backedUp: true))->toBeFalse()
    ;
});

test('the threshold stays at most half of the socket buffer', function (): void {
    expect(SseHandler::dropThreshold(1_048_576, 2_097_152))->toBe(1_048_576)
        ->and(SseHandler::dropThreshold(1_048_576, 1_048_576))->toBe(524_288)
        ->and(SseHandler::dropThreshold(0, 2_097_152))->toBe(0)
        ->and(SseHandler::dropThreshold(1_048_576, (int) Via::serverSettings((new Config())->freeze())['socket_buffer_size']))->toBe(1_048_576)
    ;
});

test('a slow client no longer parks the SSE coroutine', function (): void {
    $fixture = dirname(__DIR__) . '/Fixtures/slow_consumer_server.php';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1');

    $kv = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (preg_match('/^([a-z_]+)=(-?\d+)$/', trim($line), $m)) {
            $kv[$m[1]] = (int) $m[2];
        }
    }

    expect($kv)->toHaveKeys(['iterations', 'dropped', 'max_park_ms'], 'fixture output: ' . var_export($out, true));

    // Without the drop the loop parks on write and completes a handful of
    // iterations before the client goes away. With it, the loop keeps running.
    expect($kv['iterations'])->toBeGreaterThan(300);
    expect($kv['dropped'])->toBeGreaterThan(0);

    // No single write may park for anything like the 20s measured before.
    expect($kv['max_park_ms'])->toBeLessThan(1000);
});
