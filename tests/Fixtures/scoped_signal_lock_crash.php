<?php

declare(strict_types=1);

/*
 * Fixture for ScopedSignalSharingTest: a worker killed while holding the mutate() lock.
 *
 * The callback runs on the calling worker between an acquire and a release, so a process that
 * dies mid-callback would stall every later mutate() on that signal forever unless the lease
 * lets waiters break in.
 *
 * Prints recovered=<0|1> elapsed_ms=<n> value=<final value>.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\State\SharedSignalStore;

$store = new SharedSignalStore(maxRows: 16);
$store->initialize('sig', 'start');

$pid = pcntl_fork();

if ($pid === 0) {
    // Child: take the lock and never give it back.
    $store->mutate('sig', static function (mixed $current): string {
        posix_kill(posix_getpid(), SIGKILL);

        return 'never';
    });

    exit(0);
}

pcntl_waitpid($pid, $status);

// The lock row is now held by a process that no longer exists.
$start = microtime(true);
$recovered = 1;
$value = null;

try {
    $value = $store->mutate('sig', static fn (mixed $current): string => 'recovered');
} catch (Throwable $e) {
    $recovered = 0;
    $value = $e->getMessage();
}

echo 'recovered=', $recovered, "\n";
echo 'elapsed_ms=', (int) round((microtime(true) - $start) * 1000), "\n";
echo 'value=', var_export($value, true), "\n";
echo 'readback=', var_export($store->get('sig'), true), "\n";
