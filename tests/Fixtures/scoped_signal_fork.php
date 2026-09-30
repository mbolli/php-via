<?php

declare(strict_types=1);

/*
 * Fixture for ScopedSignalSharingTest.
 *
 * Reproduces the real lifecycle: the shared store is allocated in the master process, before
 * the workers are forked, and each forked worker mounts the same route and mutates the same
 * scoped signal.
 *
 * Prints "final=<value> reads=<distinct values seen by the children>".
 *
 * argv[1] = worker count
 * argv[2] = mutations per worker
 * argv[3] = "increment" (atomic), "setValue" (integer read-modify-write),
 *            "append" (non-integer read-modify-write: push onto an array)
 *            "mutate" (the same append through Signal::mutate())
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\State\SharedSignalStore;
use Mbolli\PhpVia\Via;

$workers = (int) ($argv[1] ?? 4);
$each = (int) ($argv[2] ?? 500);
$mode = (string) ($argv[3] ?? 'increment');

// Master process: allocated before any fork, exactly as Via::start() does it.
$store = new SharedSignalStore(maxRows: 64);

function mountWorker(SharedSignalStore $store, string $contextId, string $mode): Context {
    $app = new Via((new Config())->withLogLevel('error'));
    $app->setSharedSignalStore($store);
    $app->page('/probe', function (Context $c) use ($mode): void {
        $c->scope(Scope::ROUTE);
        if ($mode === 'append' || $mode === 'mutate') {
            $items = $c->signal([], 'items');
            $c->view(fn (): string => 'count=' . count($items->array()));
        } else {
            $count = $c->signal(0, 'count');
            $c->view(fn (): string => 'count=' . $count->int());
        }
    });

    $ctx = new Context($contextId, '/probe', $app, null, 'sess');
    $app->contexts[$contextId] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()['/probe'], $ctx, []);

    return $ctx;
}

// Mount once in the master purely to learn the signal's shared-store key, so the read-back
// below does not have to guess it.
$listMode = $mode === 'append' || $mode === 'mutate';
$signalKey = mountWorker($store, '/probe_/master', $mode)
    ->getSignal($listMode ? 'items' : 'count')->sharedKey()
;

$pids = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $ctx = mountWorker($store, '/probe_/w' . $w, $mode);
        $signal = $ctx->getSignal($mode === 'append' || $mode === 'mutate' ? 'items' : 'count');

        for ($i = 0; $i < $each; ++$i) {
            if ($mode === 'increment') {
                $signal->increment(1, broadcast: false);
            } elseif ($mode === 'mutate') {
                $signal->mutate(static function (mixed $list) use ($w, $i): array {
                    $list = is_array($list) ? $list : [];
                    $list[] = $w . ':' . $i;

                    return $list;
                }, broadcast: false);
            } elseif ($mode === 'append') {
                $list = $signal->array();
                $list[] = $w . ':' . $i;
                $signal->setValue($list, broadcast: false);
            } else {
                $signal->setValue($signal->int() + 1, broadcast: false);
            }
        }

        posix_kill(posix_getpid(), SIGKILL);
    }
    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Read back from the master, which never mounted the route at all.
$final = $store->get($signalKey, $listMode ? [] : 0);
echo 'final=', is_array($final) ? count($final) : var_export($final, true), "\n";
echo 'expected=', $workers * $each, "\n";
