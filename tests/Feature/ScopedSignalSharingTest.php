<?php

declare(strict_types=1);

use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\State\SharedSignalStore;

/*
 * Scoped signal values across real worker processes.
 *
 * The store is allocated in the master before the fork, exactly as Via::start() does it, and
 * each forked worker mounts the same route and mutates the same ROUTE-scoped signal.
 *
 * Measured, 4 workers x 500 mutations on one signal:
 *
 *   increment()                    final=2000 / 2000   (100%)
 *   setValue($signal->int() + 1)   final=802  / 2000   ( 40%)
 *
 * That gap is the whole reason increment() exists. Read-modify-write through setValue() is two
 * round trips to shared memory with a gap in between, so concurrent workers read the same value
 * and each write back the same result. Table::incr() is a single atomic operation on the row.
 *
 * setValue() is still correct for the mutation shape it actually sees in practice: a value
 * assigned wholesale — a name, a status, a rendered snapshot — has no lost update to suffer.
 *
 * Non-integer read-modify-write is the third shape, and needs mutate(): appending to a list
 * through setValue() kept 129 of 240 entries over 4 workers, and 240 of 240 through mutate().
 */

/** @return array{final: int, expected: int} */
function forkScopedSignal(int $workers, int $each, string $mode): array {
    $fixture = dirname(__DIR__) . '/Fixtures/scoped_signal_fork.php';
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
});

test('a scoped signal mutated by forked workers is visible to a process that never mounted the route', function (): void {
    $r = forkScopedSignal(workers: 2, each: 10, mode: 'increment');

    expect($r['final'])->toBe($r['expected']);
});

test('increment() loses nothing under cross-process contention', function (): void {
    $r = forkScopedSignal(workers: 4, each: 500, mode: 'increment');

    expect($r['final'])->toBe(2000);
    expect($r['final'])->toBe($r['expected'], 'atomic increment must not drop a single mutation');
});

test('read-modify-write through setValue() demonstrably does lose updates', function (): void {
    // Pins WHY increment() exists. If this ever stops losing updates the guidance in
    // Signal::increment() and SharedSignalStore should be revisited, not the test.
    $r = forkScopedSignal(workers: 4, each: 500, mode: 'setValue');

    expect($r['final'])->toBeLessThan(
        $r['expected'],
        'setValue() read-modify-write is expected to lose updates across processes'
    );
    expect($r['final'])->toBeGreaterThan(0, 'but it must still be writing through to shared memory');
});

test('increment is atomic within a single process too', function (): void {
    $store = new SharedSignalStore(maxRows: 16);
    $store->initialize('sig', 0);

    for ($i = 0; $i < 100; ++$i) {
        $store->increment('sig', 2);
    }

    expect($store->get('sig'))->toBe(200);
});

test('incrementing a non-integer signal fails loudly rather than silently coercing', function (): void {
    $store = new SharedSignalStore(maxRows: 16);
    $store->initialize('name', 'alice');

    expect(fn () => $store->increment('name'))->toThrow(LogicException::class);
});

test('non-integer values round-trip through the serialized column', function (): void {
    $store = new SharedSignalStore(maxRows: 16);

    foreach ([['s', 'hello'], ['f', 1.5], ['b', true], ['a', ['x' => 1]], ['n', null]] as [$key, $value]) {
        $store->set($key, $value);
        expect($store->get($key))->toBe($value, "value for {$key} must survive the round trip");
    }
});

test('an oversized value is rejected with a shaped exception', function (): void {
    $store = new SharedSignalStore(maxRows: 16, maxValueSize: 128);

    expect(fn () => $store->set('big', str_repeat('x', 500)))->toThrow(OverflowException::class);
});

test('initialize adopts the value already in force instead of reseeding', function (): void {
    $store = new SharedSignalStore(maxRows: 16);

    expect($store->initialize('sig', 0))->toBe(0);
    $store->set('sig', 42);
    expect($store->initialize('sig', 0))->toBe(42, 'a later worker must adopt the live value');
});

test('mutate() keeps every non-integer read-modify-write across processes', function (): void {
    $r = forkScopedSignal(workers: 4, each: 60, mode: 'mutate');

    expect($r['final'])->toBe($r['expected'], 'every append must survive');
});

test('appending through setValue() demonstrably loses entries', function (): void {
    // The reason mutate() exists. Same list, same workers, read-modify-write instead.
    $r = forkScopedSignal(workers: 4, each: 60, mode: 'append');

    expect($r['final'])->toBeLessThan($r['expected']);
    expect($r['final'])->toBeGreaterThan(0);
});

test('mutate() holds up under heavier contention', function (): void {
    $r = forkScopedSignal(workers: 8, each: 100, mode: 'mutate');

    expect($r['final'])->toBe(800);
});

test('a worker that dies holding the lock does not stall the signal forever', function (): void {
    // The callback runs between an acquire and a release, so a process killed inside one would
    // deadlock every later mutate() on that signal without the lease.
    $fixture = dirname(__DIR__) . '/Fixtures/scoped_signal_lock_crash.php';
    $out = (string) shell_exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>&1');

    expect($out)->toMatch('/recovered=1/', 'fixture output: ' . var_export($out, true));
    // Not toContain(a, b) — Pest treats extra arguments as further needles, not a message.
    expect(str_contains($out, "readback='recovered'"))
        ->toBeTrue('the recovering write must actually land; output: ' . var_export($out, true))
    ;

    preg_match('/elapsed_ms=(\d+)/', $out, $m);
    expect((int) $m[1])->toBeLessThan(5000, 'recovery must not take longer than the acquire timeout');
});

test('a worker that dies waiting for the lock does not stall the signal forever', function (): void {
    // The dead process holds a ticket nobody will serve. Before, every later mutate() on the
    // signal threw after the 5 s acquire timeout, for as long as the table lived.
    $fixture = dirname(__DIR__) . '/Fixtures/ticket_lock_dead_waiter.php';
    $out = (string) shell_exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' signal 2>&1');

    expect($out)->toMatch('/recovered=1/', 'fixture output: ' . var_export($out, true));
    expect(str_contains($out, "readback='holder+later'"))
        ->toBeTrue('the recovering write must land on the holder value; output: ' . var_export($out, true))
    ;

    preg_match('/elapsed_ms=(\d+)/', $out, $m);
    expect((int) $m[1])->toBeLessThan(3000, 'recovery must take about one 2 s lease');
});

test('mutate() works without a store and returns the written value', function (): void {
    $signal = new Signal('local', ['a']);

    $result = $signal->mutate(static function (mixed $list): array {
        $list[] = 'b';

        return $list;
    });

    expect($result)->toBe(['a', 'b']);
    expect($signal->array())->toBe(['a', 'b']);
});

test('mutate() sees the value another process wrote', function (): void {
    $store = new SharedSignalStore(maxRows: 16);
    $store->set('m', ['x' => 1]);

    $next = $store->mutate('m', static function (mixed $current): array {
        $current['y'] = 2;

        return $current;
    });

    expect($next)->toBe(['x' => 1, 'y' => 2]);
    expect($store->get('m'))->toBe(['x' => 1, 'y' => 2]);
});

test('mutate() works on an integer signal when used on its own', function (): void {
    // Legal, just not alongside increment() on the same signal — increment() skips the lock.
    $store = new SharedSignalStore(maxRows: 16);
    $store->initialize('n', 10);

    expect($store->mutate('n', static fn (mixed $v): int => (int) $v * 3))->toBe(30);
    expect($store->get('n'))->toBe(30);

    // Still on the integer fast path afterwards.
    expect($store->increment('n', 5))->toBe(35);
});

test('the mutator receives null for a signal nothing has written yet', function (): void {
    $store = new SharedSignalStore(maxRows: 16);

    $seen = 'unset';
    $store->mutate('fresh', static function (mixed $current) use (&$seen): string {
        $seen = $current;

        return 'written';
    });

    expect($seen)->toBeNull();
    expect($store->get('fresh'))->toBe('written');
});

test('the value cap admits a realistically sized collection', function (): void {
    // At the old 4 KB default a growing list overflowed at ~241 short entries, which is inside
    // the range a real chat backlog or todo list would reach. The cap is lazily mapped, so
    // raising it costs nothing until large values are actually stored.
    $store = new SharedSignalStore(maxRows: 16);

    // 1,000 short entries serialize to ~21.8 KB, inside the 32 KB default; the old 4 KB
    // default overflowed at ~241.
    $list = [];
    for ($i = 0; $i < 1000; ++$i) {
        $list[] = 'entry-' . $i;
    }

    $store->set('big', $list);

    expect($store->get('big'))->toHaveCount(1000);
});

test('the cap still fires on a genuinely runaway value', function (): void {
    $store = new SharedSignalStore(maxRows: 16);

    expect(fn () => $store->set('runaway', str_repeat('x', 40_000)))->toThrow(OverflowException::class);
});
