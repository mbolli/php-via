<?php

declare(strict_types=1);

use Mbolli\PhpVia\State\TicketLock;
use OpenSwoole\Table;

/*
 * TicketLock driven from coroutines. The suite runs outside a coroutine, so each case runs the
 * fixture in a subprocess and reads its "key=value" output.
 */

/** @return array<string, string> */
function ticketLockFixture(string $mode): array {
    $fixture = dirname(__DIR__, 2) . '/Fixtures/ticket_lock_coroutines.php';
    $out = (string) shell_exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($mode) . ' 2>&1');

    $kv = ['output' => $out];
    foreach (explode("\n", trim($out)) as $line) {
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $kv[$k] = $v;
        }
    }

    return $kv;
}

test('release leaves the lease of a holder that took over a broken turn alone', function (): void {
    if (!class_exists(Table::class)) {
        $this->markTestSkipped('OpenSwoole\Table required for shared memory');
    }

    $table = new Table(8);
    $table->column('next', Table::TYPE_INT, 8);
    $table->column('serving', Table::TYPE_INT, 8);
    $table->column('lease', Table::TYPE_INT, 8);
    $table->create();
    $table->incr('k', 'next', 0);
    $newLease = 4_102_444_800_000;

    $lock = new TicketLock($table, static fn (string $key): string => "timed out on {$key}");
    $lock->run('k', static function () use ($table, $newLease): void {
        // A waiter broke this turn, and the next holder took the lock and wrote its lease.
        $table->set('k', ['lease' => 0]);
        $table->incr('k', 'serving', 1);
        $table->incr('k', 'next', 1);
        $table->set('k', ['lease' => $newLease]);
    });

    expect((int) $table->get('k', 'serving'))->toBe(1, 'the broken turn must not be advanced twice');
    expect((int) $table->get('k', 'lease'))->toBe($newLease);
});

describe('TicketLock', function (): void {
    beforeEach(function (): void {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl required to fork the competing process');
        }
        if (!class_exists(Table::class)) {
            $this->markTestSkipped('OpenSwoole\Table required for shared memory');
        }
    });

    test('coroutines of one worker share a single ticket', function (): void {
        $r = ticketLockFixture('queue');

        expect($r)->toHaveKeys(['outstanding', 'ran', 'gates'], 'fixture output: ' . var_export($r['output'], true));
        // One ticket each would leave 21 outstanding, and 21 pollers on the row.
        expect((int) $r['outstanding'])->toBe(1);
        expect((int) $r['ran'])->toBe(21);
        expect((int) $r['value'])->toBe(21);
        expect((int) $r['serving'])->toBe((int) $r['next']);
        expect((int) $r['gates'])->toBe(0, 'every gate must be dropped once its queue drains');
    });

    test('coroutines get past a holder that died in its callback, and every write lands', function (): void {
        $r = ticketLockFixture('crash');

        expect($r)->toHaveKeys(['value', 'errors', 'elapsed_ms'], 'fixture output: ' . var_export($r['output'], true));
        expect((int) $r['value'])->toBe(10);
        expect((int) $r['errors'])->toBe(0);
        expect((int) $r['elapsed_ms'])->toBeLessThan(3000, 'recovery must take about one 2 s lease');
    });

    test('a lock-order inversion between two coroutines of one worker gets through', function (): void {
        $r = ticketLockFixture('inversion');

        expect($r)->toHaveKeys(['done', 'elapsed_ms'], 'fixture output: ' . var_export($r['output'], true));
        expect($r['done'])->toBe('ok,ok');
        expect((int) $r['elapsed_ms'])->toBeLessThan(3000, 'the gate must give way after one 2 s lease');
    });

    test('waiters park instead of spinning while the holder stalls', function (): void {
        $r = ticketLockFixture('park');

        expect($r)->toHaveKeys(['value', 'wait_ms', 'cpu_ms'], 'fixture output: ' . var_export($r['output'], true));
        expect((int) $r['value'])->toBe(5);
        expect((int) $r['wait_ms'])->toBeGreaterThan(200, 'the waiters must actually have waited on the holder');
        // A waiter that only yields burns the whole 300 ms hold; parking brings it to a few ms.
        expect((int) $r['cpu_ms'])->toBeLessThan(150);
    });

    test('callers that time out pass their turn on, and a gate wait does not use up the budget', function (): void {
        $r = ticketLockFixture('overload');

        expect($r)->toHaveKeys(['timeouts', 'gated', 'after_ms', 'next', 'serving'], 'fixture output: ' . var_export($r['output'], true));
        expect((int) $r['timeouts'])->toBe(3);
        // It bypassed its gate after 2 s and was served at 5.5 s, 3.5 s after taking its ticket.
        expect($r['gated'])->toBe('ok');
        // Left behind, each timed-out ticket stalls the queue for 2 s, and three outlast the budget.
        expect((int) $r['after_ms'])->toBeLessThan(500);
        expect((int) $r['value'])->toBe(2);
        expect((int) $r['serving'])->toBe((int) $r['next']);
    });

    test('later callers of a worker bypass a local holder that is past its lease', function (): void {
        $r = ticketLockFixture('stuck');

        expect($r)->toHaveKeys(['call_ms'], 'fixture output: ' . var_export($r['output'], true));
        // Queued at the gate, the call would wait until the holder leaves at 3 s, 800 ms after it came.
        expect((int) $r['call_ms'])->toBeLessThan(400);
    });

    test('callers of one worker return in the order they ran', function (): void {
        $r = ticketLockFixture('order');

        expect($r)->toHaveKeys(['ran', 'returned'], 'fixture output: ' . var_export($r['output'], true));
        $inOrder = implode(',', range(0, 19));
        expect($r['ran'])->toBe($inOrder);
        // Without a yield, each handoff runs the next caller inside the releasing one, and they return last to first.
        expect($r['returned'])->toBe($inOrder);
    });
});
