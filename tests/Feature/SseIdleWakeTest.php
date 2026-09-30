<?php

declare(strict_types=1);

use OpenSwoole\Coroutine\Http\Client;

/*
 * Idle SSE streams no longer poll every 100 ms: they wake on a patch, a closed channel, a closed
 * connection or the keep-alive interval.
 *
 * The poll also never detected a closed tab. OpenSwoole keeps isWritable() true after the peer
 * closes, so an idle stream stayed listed in getClients(), skipped onClientDisconnect and kept its
 * context until a write failed: with nothing broadcast, onClientDisconnect had not run 2 s after the
 * close. The close event now ends the stream within a millisecond.
 */

/** @return array<string, string> key => value from the fixture's key=value lines */
function sseIdleWake(string ...$args): array {
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/sse_idle_wake.php')
        . ' ' . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>&1'
    );
    preg_match_all('/^([a-z_]+)=(\S*)$/m', $out, $m, PREG_SET_ORDER);

    $values = [];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }

    expect($values)->not->toBe([], 'fixture output: ' . var_export($out, true));

    return $values + ['out' => $out];
}

/** @return list<int> */
function csvInts(string $csv): array {
    return array_map('intval', array_filter(explode(',', $csv), 'strlen'));
}

beforeEach(function (): void {
    if (!class_exists(Client::class)) {
        $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
    }
});

test('closing an idle stream fires onClientDisconnect at once and leaves getClients() on every worker', function (int $workers): void {
    $r = sseIdleWake('disconnect', (string) $workers, '3');
    $context = $r['out'];

    expect((int) $r['connected'])->toBe(3, $context);
    expect(array_unique(csvInts($r['before'])))->toBe([3], $context);
    expect((int) $r['disconnect_ms'])->toBeGreaterThanOrEqual(0, 'onClientDisconnect never ran: ' . $context);
    expect((int) $r['disconnect_ms'])->toBeLessThan(500, $context);
    expect(array_unique(csvInts($r['after'])))->toBe([2], $context);

    if ($workers > 1) {
        expect((int) $r['afterpids'])->toBeGreaterThan(1, 'the probes must reach more than one worker');
    }

    // Every probed worker runs the directory heartbeat timer, and one worker has no directory.
    expect($r['heartbeat'])->toBe($workers > 1 ? '1' : '0', $context);
})->with([1, 2]);

test('without close events, a closed idle stream ends at its next wake', function (): void {
    // dispatch_mode 3 rejects the close event. With the keep-alive off no write fails either, so only
    // the exists() check can end the stream when the fixture wakes it.
    $r = sseIdleWake('disconnect', '1', '2', '3');
    $context = $r['out'];

    expect((int) $r['disconnect_ms'])->toBeGreaterThanOrEqual(0, 'onClientDisconnect never ran: ' . $context);
    expect((int) $r['disconnect_ms'])->toBeLessThan(1000, $context);
    expect(array_unique(csvInts($r['after'])))->toBe([1], $context);
    expect($context)->not->toContain("cannot set 'onClose'");
});

test('an idle stream gets keep-alive comments and a busy one does not', function (string $encoding): void {
    if ($encoding === 'br' && !function_exists('brotli_uncompress_init')) {
        $this->markTestSkipped('ext-brotli required');
    }

    $r = sseIdleWake('keepalive', '1', $encoding);
    $context = $r['out'];

    // 200 ms keep-alive over a 700 ms read. A Brotli stream is decoded first, so a comment written
    // around the encoder would break the decode and lose the initial sync event too.
    expect((int) $r['idle_events'])->toBeGreaterThanOrEqual(1, $context);
    expect((int) $r['idle_keepalives'])->toBeGreaterThanOrEqual(2, $context);

    // A patch every 50 ms: never silent for 200 ms.
    expect((int) $r['busy_events'])->toBeGreaterThanOrEqual(5, $context);
    expect((int) $r['busy_keepalives'])->toBe(0, $context);
})->with(['plain' => 'plain', 'br' => 'br']);
