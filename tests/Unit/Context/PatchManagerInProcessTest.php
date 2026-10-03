<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Context\PatchManager;

/*
 * After Via::serveInProcess(), as Testing\TestApp runs an app, a context's patch queue is an array that
 * behaves as a Channel: the SSE loop, running in a Fiber, parks on it and a push, a wake or a close
 * resumes it.
 */

/**
 * A consumer as SseHandler's loop reads: it records each patch, each wake and the close, and parks in between.
 *
 * @param list<string> $log
 *
 * @return Fiber<mixed, mixed, mixed, mixed>
 */
function inProcessConsumer(PatchManager $patches, array &$log): Fiber {
    return new Fiber(static function () use ($patches, &$log): void {
        while (true) {
            $patch = $patches->getPatch();
            if ($patch !== null) {
                $log[] = (string) $patch['content'];
            } elseif ($patches->wasChannelClosed()) {
                $log[] = 'closed';

                return;
            } else {
                $log[] = 'woken';
            }
        }
    });
}

function inProcessPatches(): PatchManager {
    $via = createVia();
    $via->serveInProcess();

    return (new Context('/p_/in-process', '/p', $via))->getPatchManager();
}

test('the consumer parks on an empty queue, and a push runs it until it parks again', function (): void {
    $patches = inProcessPatches();
    $log = [];
    $consumer = inProcessConsumer($patches, $log);
    $consumer->start();

    expect($consumer->isSuspended())->toBeTrue()
        ->and($log)->toBe([])
    ;

    $patches->queuePatch(['type' => 'elements', 'content' => 'a']);
    $patches->queuePatch(['type' => 'elements', 'content' => 'b']);

    expect($log)->toBe(['a', 'b'])
        ->and($consumer->isSuspended())->toBeTrue()
    ;
});

test('a wake returns null without a close, and a close ends the consumer after what is queued', function (): void {
    $patches = inProcessPatches();
    $log = [];
    $consumer = inProcessConsumer($patches, $log);
    $consumer->start();

    $patches->wakeConsumers();
    $patches->closePatchChannel();

    expect($log)->toBe(['woken', 'closed'])
        ->and($consumer->isTerminated())->toBeTrue()
    ;

    $patches->queuePatch(['type' => 'elements', 'content' => 'late']);

    expect($patches->getPatch())->toBeNull('a closed queue takes no patch, as a closed Channel');

    $patches->recreatePatchChannel();
    $patches->queuePatch(['type' => 'elements', 'content' => 'x']);
    $patches->closePatchChannel();
    $log = [];
    inProcessConsumer($patches, $log)->start();

    expect($log)->toBe(['x', 'closed']);
});

test('outside a fiber, and without serveInProcess(), getPatch() returns at once', function (): void {
    $patches = inProcessPatches();

    expect($patches->getPatch())->toBeNull()
        ->and($patches->wasChannelClosed())->toBeFalse()
    ;

    $testMode = (new Context('/p_/test-mode', '/p', createVia()))->getPatchManager();
    $log = [];
    $consumer = new Fiber(static function () use ($testMode, &$log): void {
        $log[] = $testMode->getPatch();
    });
    $consumer->start();

    expect($consumer->isTerminated())->toBeTrue()
        ->and($log)->toBe([null])
    ;
});

test('serveInProcess() runs the onWorkerStart callbacks as worker 0, once', function (): void {
    $via = createVia();
    $workers = [];
    $via->onWorkerStart(static function (int $workerId) use (&$workers): void {
        $workers[] = $workerId;
    });

    expect($via->isInProcess())->toBeFalse();

    $via->serveInProcess();

    expect($workers)->toBe([0])
        ->and($via->isInProcess())->toBeTrue()
        ->and(fn () => $via->serveInProcess())->toThrow(LogicException::class, 'serveInProcess() runs once')
    ;
});
