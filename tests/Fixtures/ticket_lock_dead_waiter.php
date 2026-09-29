<?php

declare(strict_types=1);

/*
 * Fixture for GlobalStateConcurrencyTest and ScopedSignalSharingTest: a process that dies while
 * WAITING on mutate(), holding a ticket that was never served.
 *
 * A holder takes the lock, a second process queues behind it and is SIGKILLed, then the holder
 * releases. The lock now serves the dead ticket, and nobody holds a lease on it.
 *
 * Prints recovered=<0|1> elapsed_ms=<n> readback=<final value>.
 *
 * argv[1] = "table" (SharedTable) or "signal" (SharedSignalStore)
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\State\SharedTable;
use OpenSwoole\Table;

$storeKind = (string) ($argv[1] ?? 'table');

if ($storeKind === 'signal') {
    $store = new SharedSignalStore(maxRows: 16);
    $store->set('sig', 'start');
    $mutate = static fn (callable $fn): mixed => $store->mutate('sig', $fn);
    $readback = static fn (): mixed => $store->get('sig');
    $rowKey = (new ReflectionMethod(SharedSignalStore::class, 'key'))->invoke(null, 'sig');
} else {
    $store = new SharedTable(maxRows: 16);
    $store->set('k', 'start');
    $mutate = static fn (callable $fn): mixed => $store->mutate('k', $fn);
    $readback = static fn (): mixed => $store->get('k');
    $rowKey = 'k';
}

// The lock columns are private to the store; reading `next` is how the parent knows a ticket was taken.
$lockRow = (new ReflectionProperty($store, 'table'))->getValue($store);
if (!$lockRow instanceof Table) {
    fwrite(STDERR, "the store holds no OpenSwoole table\n");

    exit(1);
}
$ticketsIssued = static fn (): int => (int) $lockRow->get($rowKey, 'next');

$sync = new Table(4);
$sync->column('v', Table::TYPE_INT, 8);
$sync->create();

function waitFor(callable $condition, string $what): void {
    $deadline = microtime(true) + 10;
    while (!$condition()) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "timed out waiting for {$what}\n");

            exit(1);
        }
        usleep(1000);
    }
}

$holder = pcntl_fork();
if ($holder === 0) {
    $mutate(static function () use ($sync): string {
        $sync->set('held', ['v' => 1]);
        waitFor(static fn (): bool => (int) $sync->get('release', 'v') === 1, 'release');

        return 'holder';
    });

    exit(0);
}

waitFor(static fn (): bool => (int) $sync->get('held', 'v') === 1, 'the holder to take the lock');

$waiter = pcntl_fork();
if ($waiter === 0) {
    $mutate(static fn (): string => 'dead waiter');

    exit(0);
}

waitFor(static fn (): bool => $ticketsIssued() >= 2, 'the waiter to take a ticket');
posix_kill($waiter, SIGKILL);
pcntl_waitpid($waiter, $status);

$sync->set('release', ['v' => 1]);
pcntl_waitpid($holder, $status);

$start = microtime(true);
$recovered = 1;

try {
    $mutate(static fn (mixed $current): string => $current . '+later');
} catch (Throwable $e) {
    $recovered = 0;
    echo 'error=', $e::class, ': ', $e->getMessage(), "\n";
}

echo 'recovered=', $recovered, "\n";
echo 'elapsed_ms=', (int) round((microtime(true) - $start) * 1000), "\n";
echo 'readback=', var_export($readback(), true), "\n";
