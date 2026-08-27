<?php

declare(strict_types=1);

/*
 * Fixture for PersistentGlobalStateTest: a real server that writes GlobalState, then stops.
 *
 * Run twice against the same database path: the first run writes, the second reads back what
 * survived the restart. Prints value=<what globalState() returned at start-up>.
 *
 * argv[1] = db path, argv[2] = value to write ("-" to only read)
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

$path = (string) ($argv[1] ?? '');
$write = (string) ($argv[2] ?? '-');

$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')
        ->withPort(3900 + (getmypid() % 90))
        ->withLogLevel('error')
        ->withPersistentGlobalState($path, flushMs: 100)
);

$app->page('/', fn () => null);

$app->onStart(static function () use ($app, $write): void {
    // Read what the snapshot restored before touching anything.
    echo 'value=', var_export($app->globalState('counter'), true), "\n";

    if ($write !== '-') {
        $app->setGlobalState('counter', $write);
    }

    // Long enough for at least one flush tick to land.
    Timer::after(400, static function () use ($app): void {
        Timer::clearAll();
        $app->getServer()?->shutdown();
    });
});

$app->start();
