<?php

declare(strict_types=1);

/*
 * Fixture for TicketLockTest: the lock driven from coroutines, on a Table of its own so the
 * `next` and `serving` columns can be read directly.
 *
 * Prints one "key=value" per line.
 *
 * argv[1] = "queue"     20 coroutines queue behind a holder in one process
 *           "crash"     a process dies inside its callback, then 10 coroutines of another mutate
 *           "inversion" two coroutines take keys a and b in opposite order
 *           "park"      coroutines wait out a 300 ms holder in another process
 *           "overload"  callers of three workers time out behind a remote holder, and one queued
 *                       at a gate outlives them
 *           "stuck"     a coroutine holds the lock past its lease, and another of its worker calls in
 *           "order"     20 coroutines of one process queue behind a holder that yields
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\State\TicketLock;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Table;

$mode = (string) ($argv[1] ?? 'queue');

$table = new Table(64);
$table->column('next', Table::TYPE_INT, 8);
$table->column('serving', Table::TYPE_INT, 8);
$table->column('lease', Table::TYPE_INT, 8);
$table->column('v', Table::TYPE_INT, 8);
$table->create();
foreach (['k', 'a', 'b', 'held'] as $row) {
    $table->set($row, ['v' => 0]);
}

$lock = new TicketLock($table, static fn (string $key): string => "timed out on {$key}");

// Read, yield, write: two callers inside at once lose an update.
$bump = static function (string $key) use ($table): void {
    $v = (int) $table->get($key, 'v');
    Coroutine::getCid() > 0 ? Coroutine::usleep(1) : usleep(10);
    $table->set($key, ['v' => $v + 1]);
};

$cpuMs = static function (): float {
    $r = getrusage();

    return ($r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec']) * 1000 + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1000;
};

$waitForHolder = static function () use ($table): void {
    $deadline = microtime(true) + 10;
    while ((int) $table->get('held', 'v') !== 1) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "holder never took the lock\n");

            exit(1);
        }
        usleep(1000);
    }
};

if ($mode === 'queue') {
    $outstanding = -1;
    $ran = 0;
    Coroutine::run(static function () use ($lock, $table, $bump, &$outstanding, &$ran): void {
        Coroutine::create(static function () use ($lock, $table, $bump, &$outstanding, &$ran): void {
            $lock->run('k', static function () use ($table, $bump, &$outstanding, &$ran): void {
                Coroutine::usleep(50_000);
                $outstanding = (int) $table->get('k', 'next') - (int) $table->get('k', 'serving');
                Coroutine::usleep(50_000);
                $bump('k');
                ++$ran;
            });
        });
        for ($c = 0; $c < 20; ++$c) {
            Coroutine::create(static function () use ($lock, $bump, &$ran): void {
                $lock->run('k', static function () use ($bump, &$ran): void {
                    $bump('k');
                    ++$ran;
                });
            });
        }
    });

    echo 'outstanding=', $outstanding, "\n";
    echo 'ran=', $ran, "\n";
    echo 'value=', (int) $table->get('k', 'v'), "\n";
    echo 'next=', (int) $table->get('k', 'next'), "\n";
    echo 'serving=', (int) $table->get('k', 'serving'), "\n";
    echo 'gates=', count((new ReflectionProperty($lock, 'gates'))->getValue($lock)), "\n";
} elseif ($mode === 'crash') {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $lock->run('k', static function (): void {
            posix_kill(posix_getpid(), SIGKILL);
        });

        exit(0);
    }
    pcntl_waitpid($pid, $status);

    $start = microtime(true);
    $errors = 0;
    Coroutine::run(static function () use ($lock, $bump, &$errors): void {
        for ($c = 0; $c < 10; ++$c) {
            Coroutine::create(static function () use ($lock, $bump, &$errors): void {
                try {
                    $lock->run('k', static fn () => $bump('k'));
                } catch (Throwable) {
                    ++$errors;
                }
            });
        }
    });

    echo 'value=', (int) $table->get('k', 'v'), "\n";
    echo 'errors=', $errors, "\n";
    echo 'elapsed_ms=', (int) round((microtime(true) - $start) * 1000), "\n";
} elseif ($mode === 'inversion') {
    $start = microtime(true);
    $done = [];
    Coroutine::run(static function () use ($lock, $bump, &$done): void {
        foreach ([['a', 'b'], ['b', 'a']] as [$first, $second]) {
            Coroutine::create(static function () use ($lock, $bump, $first, $second, &$done): void {
                try {
                    $lock->run($first, static function () use ($lock, $bump, $first, $second): void {
                        Coroutine::usleep(5000);
                        $lock->run($second, static fn () => $bump($second));
                        $bump($first);
                    });
                    $done[] = 'ok';
                } catch (Throwable $e) {
                    $done[] = $e::class;
                }
            });
        }
    });

    sort($done);
    echo 'done=', implode(',', $done), "\n";
    echo 'elapsed_ms=', (int) round((microtime(true) - $start) * 1000), "\n";
} elseif ($mode === 'park') {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $lock->run('k', static function () use ($table): void {
            $table->set('held', ['v' => 1]);
            usleep(300_000);
        });

        exit(0);
    }
    $waitForHolder();

    $start = microtime(true);
    $cpuBefore = $cpuMs();
    Coroutine::run(static function () use ($lock, $bump): void {
        for ($c = 0; $c < 5; ++$c) {
            Coroutine::create(static fn () => $lock->run('k', static fn () => $bump('k')));
        }
    });
    $cpu = $cpuMs() - $cpuBefore;
    pcntl_waitpid($pid, $status);

    echo 'value=', (int) $table->get('k', 'v'), "\n";
    echo 'wait_ms=', (int) round((microtime(true) - $start) * 1000), "\n";
    echo 'cpu_ms=', (int) round($cpu), "\n";
} elseif ($mode === 'overload') {
    // Ticket 0 stands for a live remote holder whose lease outlasts every caller's budget.
    $table->incr('k', 'next', 1);
    $table->set('k', ['lease' => (int) (microtime(true) * 1000) + 60_000]);

    // One TicketLock per worker: the three take a ticket each and time out behind the holder.
    $workers = [$lock];
    for ($w = 1; $w < 3; ++$w) {
        $workers[] = new TicketLock($table, static fn (string $key): string => "timed out on {$key}");
    }

    $timeouts = 0;
    $gated = 'none';
    $gatedMs = -1;
    $afterMs = -1;
    Coroutine::run(static function () use ($workers, $table, $bump, &$timeouts, &$gated, &$gatedMs, &$afterMs): void {
        foreach ($workers as $worker) {
            Coroutine::create(static function () use ($worker, $bump, &$timeouts): void {
                try {
                    $worker->run('k', static fn () => $bump('k'));
                } catch (RuntimeException) {
                    ++$timeouts;
                }
            });
        }

        // Queued at the first worker's gate, it gives up on the gate after one lease and takes a
        // ticket behind the three that will time out.
        $gatedDone = new Channel(1);
        Coroutine::create(static function () use ($workers, $bump, $gatedDone, &$gated, &$gatedMs): void {
            $start = microtime(true);

            try {
                $workers[0]->run('k', static fn () => $bump('k'));
                $gated = 'ok';
            } catch (RuntimeException) {
                $gated = 'timeout';
            }
            $gatedMs = (int) round((microtime(true) - $start) * 1000);
            $gatedDone->push(true);
        });

        Coroutine::usleep(5_500_000);
        $table->set('k', ['lease' => 0]);
        $table->incr('k', 'serving', 1);
        $gatedDone->pop();

        $start = microtime(true);

        try {
            $workers[1]->run('k', static fn () => $bump('k'));
        } catch (RuntimeException) {
        }
        $afterMs = (int) round((microtime(true) - $start) * 1000);
    });

    echo 'timeouts=', $timeouts, "\n";
    echo 'gated=', $gated, "\n";
    echo 'gated_ms=', $gatedMs, "\n";
    echo 'after_ms=', $afterMs, "\n";
    echo 'value=', (int) $table->get('k', 'v'), "\n";
    echo 'next=', (int) $table->get('k', 'next'), "\n";
    echo 'serving=', (int) $table->get('k', 'serving'), "\n";
} elseif ($mode === 'stuck') {
    $callMs = -1;
    Coroutine::run(static function () use ($lock, $bump, &$callMs): void {
        Coroutine::create(static fn () => $lock->run('k', static fn () => Coroutine::usleep(3_000_000)));
        Coroutine::create(static function () use ($lock, $bump, &$callMs): void {
            Coroutine::usleep(2_200_000);
            $start = microtime(true);
            $lock->run('k', static fn () => $bump('k'));
            $callMs = (int) round((microtime(true) - $start) * 1000);
        });
    });

    echo 'call_ms=', $callMs, "\n";
} elseif ($mode === 'order') {
    $ran = [];
    $returned = [];
    Coroutine::run(static function () use ($lock, &$ran, &$returned): void {
        for ($c = 0; $c < 20; ++$c) {
            Coroutine::create(static function () use ($lock, $c, &$ran, &$returned): void {
                $lock->run('k', static function () use ($c, &$ran): void {
                    if ($c === 0) {
                        Coroutine::usleep(20_000);
                    }
                    $ran[] = $c;
                });
                $returned[] = $c;
            });
        }
    });

    echo 'ran=', implode(',', $ran), "\n";
    echo 'returned=', implode(',', $returned), "\n";
} else {
    fwrite(STDERR, "unknown mode {$mode}\n");

    exit(2);
}
