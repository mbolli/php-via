<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\State\SharedTable;
use Mbolli\PhpVia\Via;
use OpenSwoole\Table;

/*
 * GlobalState under real cross-process contention.
 *
 * Signal grew increment() and mutate() when scoped signal VALUES started crossing workers, but
 * GlobalState — the other cross-worker store — kept only get/set. That left
 * `setGlobalState($k, globalState($k) + 1)` as the only way to express a shared counter, and it
 * is a read and a write with a gap in between: concurrent workers read the same value and each
 * write back the same result.
 *
 * Measured here over 8 runs, 4 workers x 500 mutations on one key:
 *
 *   incrementGlobalState()                     2000 / 2000   every run
 *   setGlobalState(globalState() + 1)          772-1238 / 2000
 *   mutateGlobalState() appending to a list    2000 / 2000   every run
 *   setGlobalState() appending to a list        536-735 / 2000
 *
 * The workers are released from a start barrier rather than left to overlap by luck. Without
 * it the integer read-modify-write mode came back 2000/2000 and looked race-free — the first
 * worker simply finished its whole loop before the last was forked.
 */

/** @return array{final: int, expected: int} */
function forkGlobalState(int $workers, int $each, string $mode): array {
    $fixture = dirname(__DIR__) . '/Fixtures/global_state_fork.php';
    $out = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
        . ' ' . $workers . ' ' . $each . ' ' . escapeshellarg($mode) . ' 2>&1'
    );

    expect($out)->toMatch('/final=\d+/', 'fixture output: ' . var_export($out, true));
    preg_match('/final=(\d+)/', $out, $f);
    preg_match('/expected=(\d+)/', $out, $e);

    return ['final' => (int) $f[1], 'expected' => (int) $e[1]];
}

beforeEach(function (): void {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl required to reproduce the worker fork');
    }
    if (!class_exists(Table::class)) {
        $this->markTestSkipped('OpenSwoole\Table required for shared memory');
    }
});

test('incrementGlobalState is visible to a process that never wrote the key', function (): void {
    $r = forkGlobalState(workers: 2, each: 10, mode: 'increment');

    expect($r['final'])->toBe($r['expected']);
});

test('incrementGlobalState loses nothing under cross-process contention', function (): void {
    $r = forkGlobalState(workers: 4, each: 500, mode: 'increment');

    expect($r['final'])->toBe(2000);
});

test('read-modify-write through setGlobalState does lose updates', function (): void {
    $r = forkGlobalState(workers: 4, each: 500, mode: 'setValue');

    // The whole reason incrementGlobalState exists. If this ever stops losing updates the
    // contention has gone away and the comparison above proves nothing.
    expect($r['final'])->toBeLessThan(2000);
});

test('mutateGlobalState keeps every append under contention', function (): void {
    $r = forkGlobalState(workers: 4, each: 500, mode: 'mutate');

    expect($r['final'])->toBe(2000);
});

test('appending through setGlobalState drops entries', function (): void {
    $r = forkGlobalState(workers: 4, each: 500, mode: 'append');

    // Same guard as the integer case: if this stops dropping entries the workers are no longer
    // overlapping and the mutateGlobalState() result above proves nothing.
    expect($r['final'])->toBeLessThan(2000);
});

test('incrementGlobalState works single-process with no shared table', function (): void {
    $app = new Via((new Config())->withLogLevel('error'));

    expect($app->incrementGlobalState('hits'))->toBe(1);
    expect($app->incrementGlobalState('hits', 5))->toBe(6);
    expect($app->globalState('hits'))->toBe(6);
});

test('mutateGlobalState works single-process with no shared table', function (): void {
    $app = new Via((new Config())->withLogLevel('error'));

    $app->mutateGlobalState('list', fn (mixed $v): array => [...(is_array($v) ? $v : []), 'a']);
    $app->mutateGlobalState('list', fn (mixed $v): array => [...(is_array($v) ? $v : []), 'b']);

    expect($app->globalState('list'))->toBe(['a', 'b']);
});

test('incrementGlobalState refuses a key holding a non-integer', function (): void {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->setGlobalState('name', 'not a number');

    expect(fn () => $app->incrementGlobalState('name'))->toThrow(LogicException::class);
});

test('a counter survives a round trip through the durable snapshot and stays incrementable', function (): void {
    // Regression guard: the snapshot stores serialized blobs, so a counter reloaded at start-up
    // must come back on the atomic integer path. Seeded as an opaque string it would still READ
    // as 6, then throw on the next increment — a failure that only shows up after a restart.
    $table = new SharedTable(maxRows: 64);
    $table->increment('visits', 6);

    $dirty = $table->takeDirty();
    expect($dirty)->toHaveKey('visits');

    $reloaded = new SharedTable(maxRows: 64);
    $reloaded->seed('visits', $dirty['visits']);

    expect($reloaded->get('visits'))->toBe(6);
    expect($reloaded->increment('visits'))->toBe(7);
})->skip(!class_exists(Table::class), 'OpenSwoole\Table required');
