<?php

declare(strict_types=1);

/*
 * Runs a per-tab interval that throws inside a real OpenSwoole reactor, which a Pest process
 * cannot do in-process: an uncaught throw from a timer callback is fatal for the process.
 */

require __DIR__ . '/../../vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;

$via = new Via((new Config())->withLogLevel('error'));
$ctx = new Context('ctx-interval', '/interval', $via);

$ticks = 0;
Coroutine::run(static function () use ($ctx, &$ticks): void {
    $id = $ctx->setInterval(static function () use (&$ticks): void {
        ++$ticks;

        throw new RuntimeException('tab interval failed');
    }, 20);

    while ($ticks < 3) {
        Coroutine::usleep(10_000);
    }
    Timer::clear($id);
});

echo "ticks={$ticks}\n";
