<?php

declare(strict_types=1);

/*
 * Fixture for SseLoopLivenessTest.
 *
 * Exercises the REAL PatchManager against a REAL OpenSwoole Channel. The Pest
 * suite runs under VIA_TEST_MODE=1, where PatchManager swaps the Channel for a
 * plain array — so the branch that has now failed twice in production is the one
 * branch the suite cannot reach. This fixture runs with that flag cleared.
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE');
unset($_ENV['VIA_TEST_MODE'], $_SERVER['VIA_TEST_MODE']);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

Coroutine::run(static function (): void {
    $app = new Via((new Config())->withLogLevel('error')->withSsePollIntervalMs(20));
    $context = new Context('fixture', '/test', $app);
    $pm = $context->getPatchManager();

    // A queued patch comes straight back.
    $pm->queuePatch(['type' => 'elements', 'content' => '<p>hi</p>']);
    $got = $pm->getPatch();
    echo 'delivered=', ($got['content'] ?? '') === '<p>hi</p>' ? '1' : '0', "\n";
    echo 'delivered_closed=', $pm->wasChannelClosed() ? '1' : '0', "\n";

    // Idle: must return within roughly the poll timeout, not park forever.
    $t = microtime(true);
    $idle = $pm->getPatch();
    $elapsedMs = (microtime(true) - $t) * 1000;
    echo 'idle_null=', $idle === null ? '1' : '0', "\n";
    echo 'idle_ms=', (int) round($elapsedMs), "\n";
    echo 'idle_closed=', $pm->wasChannelClosed() ? '1' : '0', "\n";

    // Closed: must be reported as closed, and must not take the timeout to notice.
    $pm->closePatchChannel();
    $t = microtime(true);
    $closed = $pm->getPatch();
    $closedMs = (microtime(true) - $t) * 1000;
    echo 'closed_null=', $closed === null ? '1' : '0', "\n";
    echo 'closed_flag=', $pm->wasChannelClosed() ? '1' : '0', "\n";
    echo 'closed_ms=', (int) round($closedMs), "\n";
});
