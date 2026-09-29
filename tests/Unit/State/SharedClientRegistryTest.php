<?php

declare(strict_types=1);

use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\Support\IdGenerator;
use OpenSwoole\Atomic;

/*
 * The cross-worker client list. A clone of a registry stands in for another worker: it shares the
 * table and the version like a forked copy does, and keeps its own worker ID and cached list.
 * Rows of another process come from a forked child that dies with SIGKILL, as a crashed worker does.
 */

/**
 * Start $body in a forked child that then dies without cleanup, like a killed worker.
 *
 * @return int the child's PID, for pcntl_waitpid()
 */
function spawnKilledChild(Closure $body): int {
    if (!function_exists('pcntl_fork')) {
        test()->markTestSkipped('pcntl required');
    }

    $pid = pcntl_fork();
    if ($pid === 0) {
        try {
            $body();
        } finally {
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    return $pid;
}

/** Run $body in a forked child that then dies without cleanup, like a killed worker. */
function inKilledChild(Closure $body): void {
    pcntl_waitpid(spawnKilledChild($body), $status);
    expect(pcntl_wifsignaled($status))->toBeTrue('the child ran to its SIGKILL');
}

/** Wait until $counter reaches $value, which forked children set. */
function awaitAtomic(Atomic $counter, int $value): void {
    $deadline = microtime(true) + 10;
    while ($counter->get() < $value) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("children did not report ready: {$counter->get()} of {$value}");
        }
        usleep(10);
    }
}

test('register and unregister are visible at once', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->register('ctx-a', 'client-a', '10.0.0.1', 1000);

    expect($registry->count())->toBe(1);
    expect($registry->all())->toBe([
        'ctx-a' => [
            'id' => 'client-a',
            'identicon' => IdGenerator::generateIdenticon('client-a'),
            'connected_at' => 1000,
            'ip' => '10.0.0.1',
            'context_id' => 'ctx-a',
        ],
    ]);

    $registry->unregister('ctx-a');

    expect($registry->count())->toBe(0);
    expect($registry->all())->toBe([]);
});

test('reading never removes a row, however old it is', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->register('ctx-old', 'client-old', '10.0.0.1', time() - 86_400);
    $registry->register('ctx-new', 'client-new', '10.0.0.2', time());

    for ($i = 0; $i < 5; ++$i) {
        expect(array_keys($registry->all()))->toEqualCanonicalizing(['ctx-old', 'ctx-new']);
    }
    expect($registry->count())->toBe(2);
});

test('the list is rebuilt only after a change on any worker', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->register('ctx-a', 'client-a', '10.0.0.1', 1000);
    $first = $registry->all();

    expect($registry->all())->toBe($first);

    $other = clone $registry;
    $other->claimWorker(1);
    $other->register('ctx-b', 'client-b', '10.0.0.2', 1001);

    $list = $registry->all();
    expect(array_keys($list))->toEqualCanonicalizing(['ctx-a', 'ctx-b']);
    expect($list['ctx-b']['identicon'])->toBe(IdGenerator::generateIdenticon('client-b'));

    $other->unregister('ctx-b');

    expect(array_keys($registry->all()))->toBe(['ctx-a']);
});

test('a context that registers again under a new client ID gets that ID\'s identicon', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->register('ctx-a', 'client-1', '10.0.0.1', 1000);
    $registry->all();

    $registry->register('ctx-a', 'client-2', '10.0.0.1', 1001);

    expect($registry->all()['ctx-a'])->toMatchArray([
        'id' => 'client-2',
        'identicon' => IdGenerator::generateIdenticon('client-2'),
        'connected_at' => 1001,
    ]);
});

test('a worker does not remove a row another worker wrote for the same context', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->claimWorker(0);
    $registry->register('ctx-a', 'client-a', '10.0.0.1', 1000);

    // The tab reconnected to worker 1 before worker 0 saw its old stream end.
    $other = clone $registry;
    $other->claimWorker(1);
    $other->register('ctx-a', 'client-a2', '10.0.0.1', 1001);

    expect($registry->all())->toHaveCount(1, 'the tab is listed once while it has a row on each worker');
    expect($registry->all()['ctx-a']['id'])->toBe('client-a2', 'as its newer connection');

    $registry->unregister('ctx-a');

    expect($registry->count())->toBe(1);
    expect($registry->all()['ctx-a']['id'])->toBe('client-a2');
});

test('a process does not remove a row that a later process of the same worker wrote', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->claimWorker(3);
    $registry->register('ctx-a', 'client-a', '10.0.0.1', 1000);

    // The process being replaced, still draining its streams.
    inKilledChild(static function () use ($registry): void {
        $registry->unregister('ctx-a');
    });

    expect($registry->count())->toBe(1);
});

test('claiming a worker ID removes the rows an earlier process with that ID left, and only those', function (): void {
    $registry = new SharedClientRegistry(64);

    // Worker 3 crashes while holding two streams; worker 4 lives on.
    inKilledChild(static function () use ($registry): void {
        $registry->claimWorker(3);
        $registry->register('ctx-dead-1', 'client-1', '10.0.0.1', 1000);
        $registry->register('ctx-dead-2', 'client-2', '10.0.0.2', 1000);
        $registry->claimWorker(4);
        $registry->register('ctx-other-worker', 'client-3', '10.0.0.3', 1000);
    });

    expect(array_keys($registry->all()))->toEqualCanonicalizing(['ctx-dead-1', 'ctx-dead-2', 'ctx-other-worker']);

    // Its replacement starts under the same ID.
    expect($registry->claimWorker(3))->toBe(2);
    expect(array_keys($registry->all()))->toBe(['ctx-other-worker']);

    $registry->register('ctx-mine', 'client-mine', '10.0.0.9', 1000);

    expect($registry->claimWorker(3))->toBe(0, 'its own rows stay');
    expect(array_keys($registry->all()))->toEqualCanonicalizing(['ctx-mine', 'ctx-other-worker']);
});

test('claiming a worker ID removes every row of the dead process while other workers keep connecting and leaving', function (): void {
    // A delete by another worker in the hash chain a scan is walking makes it step past a row.
    // With one scan, about a quarter of these rounds left rows that nothing removed later.
    for ($round = 0; $round < 30; ++$round) {
        $registry = new SharedClientRegistry(2048);
        inKilledChild(static function () use ($registry): void {
            $registry->claimWorker(1);
            for ($i = 0; $i < 1500; ++$i) {
                $registry->register("dead-{$i}", "client-{$i}", '10.0.0.1', 1000);
            }
        });

        $ready = new Atomic(0);
        $stop = new Atomic(0);
        $churners = [];
        $removed = 0;

        try {
            foreach ([2, 3, 4, 5] as $workerId) {
                $churners[] = spawnKilledChild(static function () use ($registry, $workerId, $ready, $stop): void {
                    $registry->claimWorker($workerId);
                    for ($i = 0; $i < 300; ++$i) {
                        $registry->register("live-{$workerId}-{$i}", "client-{$i}", '10.0.0.2', 1000);
                    }
                    $ready->add(1);
                    for ($i = 0; $stop->get() === 0; ++$i) {
                        $registry->unregister("live-{$workerId}-" . ($i % 300));
                        $registry->register("live-{$workerId}-" . ($i % 300), "client-{$i}", '10.0.0.2', 1000);
                    }
                });
            }
            awaitAtomic($ready, 4);

            $removed = $registry->claimWorker(1);
        } finally {
            $stop->set(1);
            foreach ($churners as $pid) {
                pcntl_waitpid($pid, $status);
            }
        }

        $left = array_filter(array_keys($registry->all()), static fn (string $ctx): bool => str_starts_with($ctx, 'dead-'));
        expect($left)->toBe([], "round {$round} removed {$removed} of 1500");
    }
});

test('claiming a worker ID leaves the rows other workers write meanwhile for the same tabs', function (): void {
    // The dead worker's tabs reconnect to worker 2 while its successor claims. With one row per
    // tab, the claim deleted between 369 and 1065 of the 2000 reconnected tabs in each round.
    for ($round = 0; $round < 3; ++$round) {
        $registry = new SharedClientRegistry(8192);
        inKilledChild(static function () use ($registry): void {
            $registry->claimWorker(1);
            for ($i = 0; $i < 2000; ++$i) {
                $registry->register("tab-{$i}", "client-{$i}", '10.0.0.1', 1000);
            }
        });

        $go = new Atomic(0);
        $pid = spawnKilledChild(static function () use ($registry, $go): void {
            $registry->claimWorker(2);
            $go->set(1);
            while ($go->get() !== 2) {
                // Start with the claim.
            }
            for ($i = 0; $i < 2000; ++$i) {
                $registry->register("tab-{$i}", "client-{$i}", '10.0.0.2', 1001);
            }
        });
        awaitAtomic($go, 1);
        $go->set(2);
        $registry->claimWorker(1);
        pcntl_waitpid($pid, $status);

        expect($registry->count())->toBe(2000, "round {$round}");
        expect($registry->all())->toHaveCount(2000);
    }
});

test('the rows of processes that no longer exist are removed, whatever their worker ID', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->claimWorker(0);
    $registry->register('ctx-mine', 'client-mine', '10.0.0.1', 1000);

    // Worker 1 was stopping: it registered a stream after its successor claimed, then was killed.
    inKilledChild(static function () use ($registry): void {
        $registry->claimWorker(1);
        $registry->register('ctx-dead-1', 'client-dead-1', '10.0.0.2', 1000);
        $registry->register('ctx-dead-2', 'client-dead-2', '10.0.0.3', 1000);
    });

    $ready = new Atomic(0);
    $done = new Atomic(0);
    $alive = spawnKilledChild(static function () use ($registry, $ready, $done): void {
        $registry->claimWorker(2);
        $registry->register('ctx-alive', 'client-alive', '10.0.0.4', 1000);
        $ready->set(1);
        while ($done->get() === 0) {
            usleep(1000);
        }
    });

    try {
        awaitAtomic($ready, 1);

        expect($registry->removeDeadProcesses())->toBe(2);
        expect(array_keys($registry->all()))->toEqualCanonicalizing(['ctx-mine', 'ctx-alive']);
        expect($registry->removeDeadProcesses())->toBe(0);
    } finally {
        $done->set(1);
        pcntl_waitpid($alive, $status);
    }
});

test('reads under one read epoch share one list until this process changes it', function (): void {
    $registry = new SharedClientRegistry(64);
    $registry->register('ctx-a', 'client-a', '10.0.0.1', 1000);
    $other = clone $registry;
    $other->claimWorker(1);

    expect($registry->all(7))->toHaveCount(1);
    $other->register('ctx-b', 'client-b', '10.0.0.2', 1001);

    expect($registry->all(7))->toHaveCount(1, 'the epoch keeps the list it read');
    expect($registry->all(8))->toHaveCount(2, 'a new epoch reads again');
    expect($registry->all(8))->toHaveCount(2);

    $other->unregister('ctx-b');
    expect($registry->all(8))->toHaveCount(2);
    expect($registry->all())->toHaveCount(1, 'outside an epoch every call checks for changes');

    expect($registry->all(9))->toHaveCount(1);
    $registry->register('ctx-c', 'client-c', '10.0.0.3', 1002);
    expect($registry->all(9))->toHaveCount(2, 'a change by this process ends the pin');
});

test('a full table refuses a client instead of throwing', function (): void {
    $registry = new SharedClientRegistry(1);

    $accepted = 0;
    while ($accepted < 100_000 && $registry->register("ctx-{$accepted}", "client-{$accepted}", '10.0.0.1', 1000)) {
        ++$accepted;
    }

    expect($accepted)->toBeLessThan(100_000);
    expect($registry->count())->toBe($accepted);
    expect($registry->all())->toHaveCount($accepted);
});
