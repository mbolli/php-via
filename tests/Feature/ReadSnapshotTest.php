<?php

declare(strict_types=1);

/*
 * Read snapshots of scoped signals on the broadcast path. A flush only runs inside a coroutine,
 * so each scenario in Fixtures/read_snapshot_cases.php runs in a process of its own.
 */

/** @return array<string, mixed> what the scenario observed */
function readSnapshotCase(string $case, string ...$args): array {
    $fixture = dirname(__DIR__) . '/Fixtures/read_snapshot_cases.php';
    $argv = implode(' ', array_map(escapeshellarg(...), [$case, ...$args]));
    $out = trim((string) shell_exec(
        'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $argv . ' 2>&1'
    ));
    $lines = explode("\n", $out);
    $data = json_decode((string) end($lines), true);

    expect($data)->toBeArray('fixture output: ' . var_export($out, true));
    expect($data)->not->toHaveKey('error', 'fixture output: ' . var_export($out, true));

    return $data;
}

test('a flush reads each scoped signal once for all its scopes and contexts', function (): void {
    $r = readSnapshotCase('flush-shares-reads');

    expect($r['flushes'])->toBe(1);
    expect($r['reads'])->toBe(2, 'two signals, five contexts in two dirty scopes');
    expect($r['renders'])->toBe(['a1' => 1, 'a2' => 1, 'ab' => 1, 'b1' => 1, 'b2' => 1]);
    foreach ($r['last'] as $id => $frame) {
        expect($frame)->toBe("<div id=\"{$id}\">x,y n=7</div>");
    }
    expect($r['open'])->toBe([]);
});

test('without coalescing each fan-out reads each scoped signal once', function (): void {
    $r = readSnapshotCase('sync-reads');

    expect($r['reads'])->toBe([2, 2]);
    expect($r['renders'])->toBe(['a1' => 2, 'a2' => 2, 'a3' => 2]);
    expect($r['open'])->toBe([]);
});

test('a coroutine running while a flush waits on I/O reads shared memory, and the flush reads again after it', function (): void {
    $r = readSnapshotCase('interleaved-reader');

    expect($r['flushEpoch'])->toBeGreaterThan(0);
    expect($r['readerEpoch'])->toBe(0);
    expect($r['readerValue'])->toBe(42);
    expect($r['last'])->toBe([
        'r1' => '<div id="r1">a n=1</div>',
        'r2' => '<div id="r2">a n=42</div>',
        'r3' => '<div id="r3">a n=42</div>',
    ]);
    expect($r['open'])->toBe([]);
});

test('a local write while a flush waits on I/O reaches every context', function (): void {
    $r = readSnapshotCase('interleaved-local-write');

    foreach ($r['last'] as $id => $frame) {
        expect($frame)->toBe("<div id=\"{$id}\">local n=1</div>");
    }
    expect($r['open'])->toBe([]);
});

test('a scope another worker writes and broadcasts during a flush is read again when that flush reaches it', function (): void {
    $r = readSnapshotCase('foreign-write-mid-flush');

    // The second frame is the next flush, which the mark still schedules.
    expect($r['frames'])->toBe([
        'a1' => ['<div id="a1">a n=1</div>'],
        'b1' => ['<div id="b1">a n=99</div>', '<div id="b1">a n=99</div>'],
        'b2' => ['<div id="b2">a n=99</div>', '<div id="b2">a n=99</div>'],
    ]);
    expect($r['open'])->toBe([]);
});

test('a frame from an older fan-out that lands after a newer one is followed by a fresh one', function (string $variant): void {
    $r = readSnapshotCase('overtaken-frame', $variant);

    expect($r['frames'])->toBe([
        'c0' => ['<div id="c0">a n=1</div>', '<div id="c0">a n=2</div>'],
        'c1' => ['<div id="c1">a n=2</div>', '<div id="c1">a n=1</div>', '<div id="c1">a n=2</div>'],
    ]);
    expect($r['open'])->toBe([]);
})->with([
    'another worker writes after the flush read the signal' => ['snapshot'],
    'one worker writes after the view read it' => ['local'],
]);

test('the contexts after an overtaken one render once, under the renewed epoch', function (string $variant): void {
    $r = readSnapshotCase('overtaken-frame', $variant, 'next');

    expect($r['renders']['c2'])->toBe(2);
    expect($r['frames']['c2'])->toBe(['<div id="c2">a n=2</div>', '<div id="c2">a n=2</div>']);
    expect($r['frames']['c1'])->toBe(['<div id="c1">a n=2</div>', '<div id="c1">a n=1</div>', '<div id="c1">a n=2</div>']);
    expect($r['open'])->toBe([]);
})->with([
    'another worker writes after the flush read the signal' => ['snapshot'],
    'one worker writes after the view read it' => ['local'],
]);

test('a fan-out whose epoch a nested fan-out renewed renders its next context once', function (string $variant): void {
    $r = readSnapshotCase('nested-renewal', $variant);

    // ab: room:b's pass, its re-run, then room:a's pass under the re-run's epoch, with no overtaken retry.
    expect($r['renders'])->toBe(['a0' => 1, 'ab' => 3, 'a2' => 1]);
    expect($r['renewals'])->toBe(1, "only room:b's re-run renews");
    expect($r['open'])->toBe([]);
})->with([
    'coalescing off' => ['off'],
    'outside a coroutine' => ['outside'],
]);

test('a scope marked after a flush began, whose mark another flush took, is read and rendered again in full', function (): void {
    $r = readSnapshotCase('mark-taken-by-another-flush');

    expect($r['frames'])->toBe([
        'x1' => ['<div id="x1">a n=1</div>', '<div id="x1">a n=99</div>'],
        'a2' => ['<div id="a2">a n=1</div>'],
        'b1' => ['<div id="b1">a n=99</div>'],
    ]);
    expect($r['open'])->toBe([]);
});

test('a scope marked mid-flush renders a context the flush rendered before the mark, even after the epoch moved on', function (): void {
    $r = readSnapshotCase('mark-after-renewal');

    // x: room:a before the write, room:c in the same flush, room:c again in the flush its mark schedules.
    expect($r['frames'])->toBe([
        'x' => ['<div id="x">a n=1</div>', '<div id="x">a n=99</div>', '<div id="x">a n=99</div>'],
        'b1' => ['<div id="b1">a n=99</div>', '<div id="b1">a n=99</div>'],
        'c1' => ['<div id="c1">a n=99</div>', '<div id="c1">a n=99</div>'],
    ]);
    expect($r['open'])->toBe([]);
});
