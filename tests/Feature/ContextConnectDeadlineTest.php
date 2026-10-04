<?php

declare(strict_types=1);

/*
 * A context with no SSE stream on its worker is destroyed after Config::withContextTimeouts(connectMs:).
 * Without it, a page whose stream never connected, and every copy an action rebuilt on a worker the
 * tab does not stream from, stayed in memory until the worker stopped. Such actions now go to the
 * streaming worker, so they leave no copy at all.
 */

/** @return array<string, string> the key=value lines of a Fixtures/connect_deadline_server.php run, plus its raw output */
function connectDeadlineFixture(string $case): array {
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/connect_deadline_server.php')
        . ' ' . escapeshellarg($case) . ' 2>&1'
    );
    preg_match_all('/^([a-z_]+)=(\S*)$/m', $out, $m, PREG_SET_ORDER);

    $values = ['out' => $out];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }

    return $values;
}

test('a page whose stream never connects is destroyed after the connect timeout, and its cleanup runs', function (): void {
    $r = connectDeadlineFixture('page');

    expect($r['idle_kept'] ?? null)->toBe('0', $r['out'])
        ->and($r['idle_cleanup_ran'] ?? null)->toBe('1', $r['out'])
        ->and($r['connected_kept'] ?? null)->toBe('1', $r['out'])
    ;
});

test('a connect timeout of 0 keeps a page that never connects', function (): void {
    $r = connectDeadlineFixture('off');

    expect($r['idle_kept'] ?? null)->toBe('1', $r['out'])
        ->and($r['idle_cleanup_ran'] ?? null)->toBe('0', $r['out'])
    ;
});

test('an action on a worker the tab does not stream from leaves no copy there, the streaming worker keeps the context', function (): void {
    $r = connectDeadlineFixture('xworker');

    expect($r['workers'] ?? null)->toBe('1', $r['out'])
        ->and($r['actions'] ?? null)->toBe('200,200,200', $r['out'])
        ->and($r['copy_after_action'] ?? null)->toBe('0', 'the action goes to the streaming worker: ' . $r['out'])
        ->and($r['copy_kept_while_active'] ?? null)->toBe('0', $r['out'])
        ->and($r['stream_kept'] ?? null)->toBe('1', $r['out'])
        ->and($r['action_after_free'] ?? null)->toBe('200', $r['out'])
    ;
});

test('a tab an action revived after its stream dropped waits for the reconnect past the connect timeout, and gets the queued patch', function (): void {
    $r = connectDeadlineFixture('revived');

    expect($r['freed_after_drop'] ?? null)->toBe('1', 'the cleanup delay frees a dropped tab: ' . $r['out'])
        ->and($r['action'] ?? null)->toBe('200', $r['out'])
        ->and($r['kept_until_reconnect'] ?? null)->toBe('1', 'the reconnect timeout, not the connect timeout, applies: ' . $r['out'])
        ->and($r['patch_delivered'] ?? null)->toBe('1', $r['out'])
    ;
});

test('a revived tab that reconnects after the reconnect timeout is freed with its queued patch', function (): void {
    $r = connectDeadlineFixture('revived-late');

    expect($r['action'] ?? null)->toBe('200', $r['out'])
        ->and($r['kept_until_reconnect'] ?? null)->toBe('0', $r['out'])
        ->and($r['patch_delivered'] ?? null)->toBe('0', 'the patch went with the freed context: ' . $r['out'])
    ;
});
