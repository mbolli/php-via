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
use Mbolli\PhpVia\Context\PatchManager;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;

Coroutine::run(static function (): void {
    // The poll interval paces only the Dev Bar stream now; the keep-alive bounds the park.
    $app = new Via((new Config())->withLogLevel('error')->withDevBarOptions(pollMs: 1)->withSseKeepAliveMs(20));
    $context = new Context('fixture', '/test', $app);
    $pm = $context->getPatchManager();

    // A queued patch comes straight back.
    $pm->queuePatch(['type' => 'elements', 'content' => '<p>hi</p>']);
    $got = $pm->getPatch();
    echo 'delivered=', ($got['content'] ?? '') === '<p>hi</p>' ? '1' : '0', "\n";
    echo 'delivered_closed=', $pm->wasChannelClosed() ? '1' : '0', "\n";

    // Idle: must return within roughly the keep-alive interval, not park forever.
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

    // Woken: a parked getPatch() returns null at once without reporting a close, and the
    // channel keeps working.
    $slow = new Via((new Config())->withLogLevel('error')->withSseKeepAliveMs(5000));
    $woken = new Context('woken', '/test', $slow);
    $wpm = $woken->getPatchManager();

    $wpm->wakeConsumers();
    $wpm->queuePatch(['type' => 'script', 'content' => 'first']);
    echo 'wake_unparked_left_nothing=', ($wpm->getPatch()['content'] ?? '') === 'first' ? '1' : '0', "\n";

    $done = new Coroutine\Channel(1);
    Coroutine::create(static function () use ($wpm, $done): void {
        $t = microtime(true);
        $patch = $wpm->getPatch();
        $done->push([$patch, $wpm->wasChannelClosed(), (microtime(true) - $t) * 1000]);
    });
    Coroutine::usleep(50_000);
    $wpm->wakeConsumers();
    [$patch, $closedFlag, $wokenMs] = $done->pop(2);
    echo 'woken_null=', $patch === null ? '1' : '0', "\n";
    echo 'woken_closed=', $closedFlag ? '1' : '0', "\n";
    echo 'woken_ms=', (int) round($wokenMs), "\n";

    $wpm->queuePatch(['type' => 'script', 'content' => 'after']);
    echo 'woken_then_delivered=', ($wpm->getPatch()['content'] ?? '') === 'after' ? '1' : '0', "\n";

    // With the keep-alive off an idle park is still bounded.
    $off = new Context('off', '/test', new Via((new Config())->withLogLevel('error')->withSseKeepAliveMs(0)));
    echo 'backstop_s=', (new ReflectionProperty(PatchManager::class, 'pollTimeout'))->getValue($off->getPatchManager()), "\n";
});
