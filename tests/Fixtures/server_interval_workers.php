<?php

declare(strict_types=1);

/*
 * Fixture for ServerIntervalTest: a real multi-worker Via server with one
 * Via::setInterval() registered. Every firing appends its pid to the tally file
 * given as argv[4]; the test reads and removes it.
 *
 * argv[1] = worker count
 * argv[2] = interval ms
 * argv[3] = run milliseconds
 * argv[4] = tally file path
 * argv[5] = "1" to register the interval on every worker instead of the leader
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$workers = (int) ($argv[1] ?? 4);
$everyMs = (int) ($argv[2] ?? 100);
$runMs = (int) ($argv[3] ?? 2000);
$tally = (string) ($argv[4] ?? sys_get_temp_dir() . '/via_interval_tally');
$everyWorker = ($argv[5] ?? '0') === '1';

$app = new Via(
    (new Config())
        ->withLogLevel('error')
        ->withHost('127.0.0.1')
        ->withPort(FixturePort::pick(3700, 150))
        ->withWorkerNum($workers)
        ->withBroker(new SwooleBroker())
);

$app->page('/', fn () => null);

$app->setInterval(static function () use ($tally): void {
    file_put_contents($tally, getmypid() . "\n", FILE_APPEND | LOCK_EX);
}, $everyMs, everyWorker: $everyWorker);

// onStart runs in every worker. All of them arm the stop; whichever fires first
// takes the whole server down, so no coordination between them is needed.
$app->onStart(static function () use ($app, $runMs): void {
    Timer::after($runMs, static function () use ($app): void {
        // shutdown() from a worker coroutine leaves that coroutine asleep, so OpenSwoole
        // prints a scheduler-deadlock notice on the way out. It is cosmetic — the process
        // does stop, and the test reads the tally file rather than this output. SIGTERM to
        // the master is NOT an alternative: OpenSwoole owns signal 15 and the master did
        // not stop when sent one.
        Timer::clearAll();
        $app->getServer()?->shutdown();
    });
});

$app->start();
