<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;

/*
 * The cross-worker directory record of a streamed context is refreshed by a per-worker timer.
 *
 * The SSE loop used to refresh it only when it woke with nothing to send, so the record of a
 * stream that received a patch at least every 100 ms for the directory TTL (an hour by default)
 * expired, and the tab's actions that landed on another worker answered 400 from then on.
 */

/** @return array<string, string> */
function sseHeartbeatFixture(): array {
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/sse_heartbeat.php') . ' 2>&1');

    $kv = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (preg_match('/^([a-z_]+)=(.*)$/', $line, $m)) {
            $kv[$m[1]] = $m[2];
        }
    }

    expect($kv)->toHaveKeys(
        ['closed_ended_ms', 'others_running', 'disconnects', 'busy_record', 'idle_record', 'closed_record', 'orphan_record'],
        'fixture output: ' . var_export($out, true)
    );

    return $kv;
}

test('the heartbeat refreshes every running stream, busy or idle, and nothing else', function (): void {
    $r = sseHeartbeatFixture();

    // Every record was expired before the heartbeat ran, and the idle one was deleted.
    expect($r['busy_record'])->toBe('1', 'a stream that never goes idle must keep its record');
    expect($r['idle_record'])->toBe('1', 'a record another worker deleted must come back while the stream runs');
    expect($r['closed_record'])->toBe('0', 'an ended stream must not refresh its record');
    expect($r['orphan_record'])->toBe('0', 'a destroyed context must stay expired');
});

test('a closed connection ends only its own stream, at once', function (): void {
    $r = sseHeartbeatFixture();

    expect((int) $r['closed_ended_ms'])->toBeGreaterThanOrEqual(0, 'the stream kept running');
    expect((int) $r['closed_ended_ms'])->toBeLessThan(50);
    expect($r['others_running'])->toBe('1');
    expect($r['disconnects'])->toBe('closed');
});

test('the heartbeat runs at a quarter of the directory TTL or of the revival window, the shorter', function (): void {
    // A worker that destroys its copy of a context shortens the record to the revival window (600 s).
    expect(Via::sseHeartbeatIntervalMs((new Config())->freeze()))->toBe(150_000);
    expect(Via::sseHeartbeatIntervalMs((new Config())->withContextDirectorySize(4096, 1024, 60)->freeze()))->toBe(15_000);
    expect(Via::sseHeartbeatIntervalMs((new Config())->withContextDirectorySize(4096, 1024, 7200)->freeze()))->toBe(150_000);
    expect(Via::sseHeartbeatIntervalMs((new Config())->withContextDirectorySize(4096, 1024, 7200)->withContextTimeouts(revivalWindowMs: 0)->freeze()))->toBe(1_800_000);
    expect(Via::sseHeartbeatIntervalMs((new Config())->withContextTimeouts(revivalWindowMs: 2000)->freeze()))->toBe(1000);
});
