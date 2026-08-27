<?php

declare(strict_types=1);

/*
 * Fixture for SseLoopLivenessTest.
 *
 * Runs the real SSE consume loop shape against a real OpenSwoole Channel and
 * reports iteration counts, so the two failure modes in this loop's history stay
 * pinned:
 *
 *   fcab883 — pop(timeout) on a CLOSED channel returns instantly, spinning at
 *             100% CPU after context cleanup.
 *   A1      — pop(0) on an OPEN channel parks forever, so the loop's liveness
 *             checks (shutdown, client disconnect) never run.
 *
 * Prints "<closedIterations> <livenessChecksWhileIdle>".
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;

$timeout = 0.02; // stands in for ssePollIntervalMs

Coroutine::run(static function () use ($timeout): void {
    // --- closed channel: must not spin ---
    $closed = new Channel(50);
    $closed->close();

    $iterations = 0;
    $deadline = microtime(true) + 0.15;

    while (microtime(true) < $deadline) {
        ++$iterations;
        $patch = $closed->pop($timeout);

        if ($patch === false && $closed->errCode === Channel::CHANNEL_CLOSED) {
            break; // the fix: leave rather than spin
        }
    }

    // --- open, idle channel: liveness checks must still run ---
    $open = new Channel(50);
    $checks = 0;
    $deadline = microtime(true) + 0.15;

    while (microtime(true) < $deadline) {
        $patch = $open->pop($timeout);

        if ($patch === false && $open->errCode === Channel::CHANNEL_TIMEOUT) {
            ++$checks; // where isShuttingDown()/isWritable() are evaluated
        }
    }

    echo $iterations . ' ' . $checks . "\n";
});
