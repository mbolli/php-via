<?php

declare(strict_types=1);

/*
 * Fixture for BroadcastCoalescingTest: a real 2-worker server with SwooleBroker, so broadcasts
 * run through Event::defer, the tick timer and the pipeMessage receive path as in production.
 *
 * Worker 1 holds one context in "room:x" and counts its renders. Worker 0 broadcasts the scope
 * 10 times in one turn (send side), then sends 10 raw pipe messages for it in one turn (receive
 * side). Worker 1 writes "phase1=<renders>" and "phase2=<renders>" to argv[1] after each phase.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Channel-backed PatchManager, as in production.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$out = (string) ($argv[1] ?? sys_get_temp_dir() . '/via_coalescing_out');

$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort(FixturePort::pick(4200, 150))->withLogLevel('error')
        ->withWorkerNum(2)->withBroker(new SwooleBroker())->withGcInterval(0)
);

$app->page('/', fn () => null);

$app->onStart(static function () use ($app, $out): void {
    $server = $app->getServer();
    if ($server === null) {
        return;
    }

    if ($server->worker_id === 1) {
        $renders = 0;
        $ctx = new Context('observer', '/observer', $app);
        $ctx->scope('room:x');
        $ctx->view(static function () use (&$renders): string {
            ++$renders;

            return '<div id="observer">' . $renders . '</div>';
        }, cacheUpdates: false);

        Timer::after(500, static function () use (&$renders, $out): void {
            file_put_contents($out, "phase1={$renders}\n", FILE_APPEND | LOCK_EX);
        });
        Timer::after(1000, static function () use (&$renders, $out): void {
            file_put_contents($out, "phase2={$renders}\n", FILE_APPEND | LOCK_EX);
        });

        return;
    }

    Timer::after(250, static function () use ($app): void {
        for ($i = 0; $i < 10; ++$i) {
            $app->broadcast('room:x');
        }
    });

    Timer::after(700, static function () use ($server): void {
        for ($i = 0; $i < 10; ++$i) {
            $server->sendMessage((string) json_encode(['scope' => 'room:x', 'nodeId' => 'fixture-' . $i]), 1);
        }
    });

    Timer::after(1300, static function () use ($server): void {
        Timer::clearAll();
        $server->shutdown();
    });
});

$app->start();
