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
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;

$via = new Via((new Config())->withLogLevel('error'));
$ctx = new Context('ctx-interval', '/interval', $via);

$reports = [];
$via->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $action) use (&$reports): void {
    $reports[] = $phase->value . ' ' . ($c?->getId() ?? 'null') . ' ' . ($action ?? 'null') . ' ' . $e->getMessage();
});

$ticks = 0;
Coroutine::run(static function () use ($ctx, &$ticks): void {
    $id = $ctx->setInterval(static function () use (&$ticks): void {
        ++$ticks;

        throw new RuntimeException('tab interval failed');
    }, 20);

    $deadline = microtime(true) + 2.0;
    while ($ticks < 3 && microtime(true) < $deadline) {
        Coroutine::usleep(10_000);
    }
    Timer::clear($id);
});

echo "ticks={$ticks}\n";
echo 'reports=' . count($reports) . ' ' . implode(' | ', array_unique($reports)) . "\n";
