<?php

declare(strict_types=1);

/*
 * Fixture for ActionRateLimitTest.
 *
 * Reproduces the real lifecycle: the ActionHandler (and its rate-limit state) is
 * built in Via's constructor, in the master process, and the workers are forked
 * afterwards by $server->start(). Every child then hammers the SAME client IP.
 *
 * Prints "allowed=<total across all children>".
 *
 * argv[1] = worker count, argv[2] = limit, argv[3] = attempts per worker
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Via;

$workers = (int) ($argv[1] ?? 4);
$limit = (int) ($argv[2] ?? 5);
$attempts = (int) ($argv[3] ?? 20);

$via = new Via(
    (new Config())
        ->withLogLevel('error')
        ->withWorkerNum($workers)
        ->withActionRateLimit($limit, 60)
);

// Master process: exactly where Via builds it, before any fork.
$handler = new ActionHandler($via);
$check = new ReflectionMethod(ActionHandler::class, 'checkRateLimit');

$dir = sys_get_temp_dir() . '/via_rl_' . getmypid();
mkdir($dir);

$pids = [];
for ($w = 0; $w < $workers; ++$w) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $allowed = 0;
        for ($i = 0; $i < $attempts; ++$i) {
            if ($check->invoke($handler, '203.0.113.7') === true) {
                ++$allowed;
            }
        }
        file_put_contents($dir . '/' . $w, (string) $allowed);
        posix_kill(posix_getpid(), SIGKILL);
    }
    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$total = 0;
foreach (glob($dir . '/*') ?: [] as $f) {
    $total += (int) file_get_contents($f);
    @unlink($f);
}
@rmdir($dir);

echo 'allowed=', $total, "\n";
