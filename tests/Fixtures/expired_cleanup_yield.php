<?php

declare(strict_types=1);

/*
 * Fixture for ExpiredContextSliceTest: three contexts expire in one pass, and the cleanup of the first waits on I/O
 * for 300 ms. Prints which contexts are left after 50 ms and after 450 ms.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

Coroutine::run(static function (): void {
    $via = new Via((new Config())->withLogLevel('error'));
    $app = $via->getApp();
    for ($i = 0; $i < 3; ++$i) {
        $context = new Context("ctx{$i}", '/r', $via);
        if ($i === 0) {
            $context->onCleanup(static function (): void {
                Coroutine::usleep(300_000);
            });
        }
        $app->registerContext($context);
        $app->cleanupTimerFired("ctx{$i}", 30_000, null);
    }

    Coroutine::usleep(50_000);
    echo 'alive_50ms=' . implode(',', array_keys($app->getAllContexts())) . "\n";
    Coroutine::usleep(400_000);
    echo 'alive_450ms=' . implode(',', array_keys($app->getAllContexts())) . "\n";
});
