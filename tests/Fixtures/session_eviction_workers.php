<?php

declare(strict_types=1);

/*
 * Fixture for SharedSessionDataTest: the leader's eviction timer on a real multi-worker server.
 *
 * Stores data in more sessions than Config::withSessionTableSize() keeps, but fewer than fill the
 * table, so only the leader's timer can bring the row count back under the cap.
 *
 * Prints "peak=<rows after writing> rows=<rows after waiting> cap=<n>".
 *
 * argv[1] = session count
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use Tests\Support\FixturePort;

$sessions = (int) ($argv[1] ?? 90);

$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort(FixturePort::pick(4950, 150))->withLogLevel('error')
        ->withWorkerNum(2)->withBroker(new SwooleBroker())
        ->withSessionTableSize(64)
);

// Not Timer::clearAll() to run once, as other fixtures do: that would stop the eviction timer too.
$started = false;
$app->setInterval(static function () use ($app, $sessions, &$started): void {
    if ($started) {
        return;
    }
    $started = true;

    Coroutine::create(static function () use ($app, $sessions): void {
        $store = $app->getApp()->getSessionStore();
        if ($store === null) {
            echo "peak=-1 rows=-1 cap=-1\n";
            $app->getServer()?->shutdown();

            return;
        }

        for ($i = 0; $i < $sessions; ++$i) {
            $app->setSessionData("ses_{$i}", 'n', $i);
        }
        $peak = $store->count();

        $deadline = microtime(true) + 3;
        while ($store->count() > $store->capacity() && microtime(true) < $deadline) {
            Coroutine::usleep(50_000);
        }

        echo 'peak=', $peak, ' rows=', $store->count(), ' cap=', $store->capacity(), "\n";
        $app->getServer()?->shutdown();
    });
}, 100);

$app->start();
