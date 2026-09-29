<?php

declare(strict_types=1);

/*
 * Fixture for GlobalStateConcurrencyTest.
 *
 * Reproduces the real lifecycle: the SharedTable is allocated in the master process, before
 * the workers are forked, exactly as Via::start() does it. Each forked worker then hammers the
 * same GlobalState key through an Application of its own.
 *
 * Prints "final=<value> expected=<value>".
 *
 * argv[1] = worker count
 * argv[2] = mutations per worker
 * argv[3] = "increment" (atomic incrementGlobalState)
 *           "setValue"  (integer read-modify-write through setGlobalState)
 *           "mutate"    (non-integer read-modify-write through mutateGlobalState)
 *           "append"    (non-integer read-modify-write through setGlobalState)
 *           "firstTouch" (mutateGlobalState once per fresh key, argv[2] keys, all workers in step)
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\State\SharedTable;
use Mbolli\PhpVia\Via;

$workers = (int) ($argv[1] ?? 4);
$each = (int) ($argv[2] ?? 500);
$mode = (string) ($argv[3] ?? 'increment');

const KEY = 'counter';
const START = 'barrier_start';
const READY = 'barrier_ready';

// Master process: allocated before any fork, exactly as Via::start() does it.
// The value cap is raised well above the default only so the list modes below can build a
// 2000-entry array without tripping the size guard; it has no bearing on what is measured.
$table = new SharedTable(maxRows: $mode === 'firstTouch' ? 2048 : 64, maxValueBytes: 1_048_576);

function mountWorker(SharedTable $table): Via {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->getApp()->setSharedTable($table);

    return $app;
}

$listMode = $mode === 'append' || $mode === 'mutate';

$pids = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $app = mountWorker($table);

        // Start barrier. Without it the first worker can finish its whole loop before the last
        // one is even forked, and the run measures nothing: the integer read-modify-write mode
        // came back 2000/2000 — looking race-free — purely because the workers never overlapped.
        $table->increment(READY, 1);
        while ((int) $table->get(START, 0) !== 1) {
            usleep(200);
        }

        for ($i = 0; $i < $each; ++$i) {
            if ($mode === 'increment') {
                $app->incrementGlobalState(KEY, 1);
            } elseif ($mode === 'mutate') {
                $app->mutateGlobalState(KEY, static function (mixed $list) use ($w, $i): array {
                    $list = is_array($list) ? $list : [];
                    $list[] = $w . ':' . $i;

                    return $list;
                });
            } elseif ($mode === 'firstTouch') {
                // Per-key barrier: the race is only in row creation, so every worker has to
                // reach each fresh key together or they drift apart after the first one.
                $table->increment('arrive_' . $i, 1);
                while ((int) $table->get('arrive_' . $i, 0) < $workers) {
                    // spin: a sleep here lets the workers drift apart again
                }
                $app->mutateGlobalState('fresh_' . $i, static fn (mixed $list): array => [...(is_array($list) ? $list : []), $w]);
            } elseif ($mode === 'append') {
                $list = $app->globalState(KEY, []);
                $list = is_array($list) ? $list : [];
                $list[] = $w . ':' . $i;
                $app->setGlobalState(KEY, $list);
            } else {
                $app->setGlobalState(KEY, ((int) $app->globalState(KEY, 0)) + 1);
            }
        }

        posix_kill(posix_getpid(), SIGKILL);
    }
    $pids[] = $pid;
}

// Release every worker at once, so they contend for the whole run rather than by accident.
// Bounded: a child that died before reaching the barrier must not hang the run forever.
$deadline = microtime(true) + 10;
while ((int) $table->get(READY, 0) < $workers) {
    if (microtime(true) > $deadline) {
        fwrite(STDERR, "barrier timed out: only {$table->get(READY, 0)} of {$workers} workers arrived\n");

        break;
    }
    usleep(200);
}
$table->set(START, 1);

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Read back from the master, which never wrote the key at all.
if ($mode === 'firstTouch') {
    $final = [];
    for ($i = 0; $i < $each; ++$i) {
        $final = [...$final, ...(array) $table->get('fresh_' . $i, [])];
    }
} else {
    $final = $table->get(KEY, $listMode ? [] : 0);
}
echo 'final=', is_array($final) ? count($final) : var_export($final, true), "\n";
echo 'expected=', $workers * $each, "\n";
