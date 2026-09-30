<?php

declare(strict_types=1);

/*
 * Fixture for SharedSessionDataTest: forked workers writing different keys of ONE session.
 *
 * The store is allocated before the fork, as Via::start() does it. Every worker writes its own
 * keys through a Via of its own, released together from a start barrier so the writes overlap.
 *
 * Prints "kept=<n> expected=<n> us_per_write=<n>".
 *
 * argv[1] = worker count, argv[2] = writes per worker
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\State\SharedSessionStore;
use Mbolli\PhpVia\Via;
use OpenSwoole\Atomic;

$workers = (int) ($argv[1] ?? 4);
$each = (int) ($argv[2] ?? 500);

const SESSION = 'ses_shared_by_every_worker';

// Room for every key in one map: the test is about lost writes, not the byte cap.
$store = new SharedSessionStore(maxRows: 64, maxSessionBytes: 1_048_576);
$ready = new Atomic(0);
$start = new Atomic(0);

$pids = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $via = new Via((new Config())->withLogLevel('error'));
        $via->getApp()->setSessionStore($store);

        $ready->add(1);
        while ($start->get() !== 1) {
            usleep(100);
        }

        for ($i = 0; $i < $each; ++$i) {
            $via->setSessionData(SESSION, "w{$w}:{$i}", $i);
        }

        posix_kill(posix_getpid(), SIGKILL);
    }
    $pids[] = $pid;
}

$deadline = microtime(true) + 10;
while ($ready->get() < $workers && microtime(true) < $deadline) {
    usleep(100);
}
$startedAt = hrtime(true);
$start->set(1);

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}
$elapsedUs = (hrtime(true) - $startedAt) / 1000;

// Read back from the master, which wrote nothing.
$via = new Via((new Config())->withLogLevel('error'));
$via->getApp()->setSessionStore($store);
$kept = 0;
for ($w = 0; $w < $workers; ++$w) {
    for ($i = 0; $i < $each; ++$i) {
        if ($via->getSessionData(SESSION, "w{$w}:{$i}") === $i) {
            ++$kept;
        }
    }
}

echo 'kept=', $kept, ' expected=', $workers * $each, ' us_per_write=', (int) round($elapsedUs / ($workers * $each)), "\n";
