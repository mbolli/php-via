<?php

declare(strict_types=1);

/*
 * Fixture for PersistentGlobalStateTest: a real server that writes GlobalState, then stops.
 *
 * Run twice against the same database path: the first run writes, the second reads back what
 * survived the restart. Prints value=<what globalState() returned at start-up>.
 *
 * argv[1] = db path, argv[2] = value to write ("-" to only read)
 * argv[3] = flush interval ms (default 100), argv[4] = worker count (default 1)
 * argv[5] = "shutdown" to write from the last worker's onShutdown, after the leader has stopped,
 *           or "doubleterm" to stop with two SIGTERMs to the master instead of shutdown()
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\InMemoryBroker;
use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$path = (string) ($argv[1] ?? '');
$write = (string) ($argv[2] ?? '-');
$flushMs = (int) ($argv[3] ?? 100);
$workers = (int) ($argv[4] ?? 1);
$writeOnShutdown = ($argv[5] ?? '') === 'shutdown';
$external = ($argv[5] ?? '') === 'external';
$doubleTerm = ($argv[5] ?? '') === 'doubleterm';

$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')
        ->withPort(FixturePort::pick(3900, 90))
        ->withLogLevel('error')
        ->withWorkerNum($workers)
        ->withBroker($workers > 1 ? new SwooleBroker() : new InMemoryBroker())
        ->withPersistentGlobalState($path, flushMs: $flushMs)
);

$app->page('/', fn () => null);

$app->onStart(static function () use ($app, $write, $writeOnShutdown, $doubleTerm): void {
    if ($app->getServer()?->getWorkerId() !== 0) {
        return;
    }

    // Read what the snapshot restored before touching anything.
    echo 'value=', var_export($app->globalState('counter'), true), "\n";

    if ($write !== '-' && !$writeOnShutdown) {
        $app->setGlobalState('counter', $write);
    }

    if ($external) {
        return;
    }

    // Long enough for at least one flush tick to land. One caller only: a second SIGTERM to
    // the master can end it before its shutdown event.
    Timer::after(400, static function () use ($app, $doubleTerm): void {
        Timer::clearAll();
        if ($doubleTerm) {
            $master = (int) $app->getServer()?->master_pid;
            posix_kill($master, SIGTERM);
            posix_kill($master, SIGTERM);

            return;
        }
        $app->getServer()?->shutdown();
    });
});

$app->onShutdown(static function () use ($app, $write, $writeOnShutdown, $workers): void {
    if ($writeOnShutdown && $write !== '-' && $app->getServer()?->getWorkerId() === $workers - 1) {
        Coroutine::usleep(200_000);
        $app->setGlobalState('counter', $write);
    }
});

$app->start();
