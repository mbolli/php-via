<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\Application;
use Mbolli\PhpVia\Via;

/*
 * After a worker was busy, the cleanup timers of thousands of contexts fire in one pass of the event loop, and
 * destroying each in its own callback kept every request waiting until all were done (about 100 ms for 13,000
 * contexts after a GC pause in a 250,000-view burst). A timer now only queues its context, and later passes destroy
 * the queue in slices of about 10 ms with the loop in between.
 */

/**
 * An app whose deferred slices are collected instead of handed to a timer, with $count contexts whose cleanup takes
 * $cleanupUs each.
 *
 * @return array{0: Via, 1: ArrayObject<int, Closure(): void>}
 */
function appWithSlowCleanups(int $count, int $cleanupUs): array {
    $app = createVia();
    $deferred = new ArrayObject();
    (new ReflectionProperty(Application::class, 'defer'))->setValue($app->getApp(), static function (Closure $next) use ($deferred): void {
        $deferred[] = $next;
    });
    for ($i = 0; $i < $count; ++$i) {
        $context = new Context("ctx{$i}", '/r', $app);
        $context->onCleanup(static function () use ($cleanupUs): void {
            $until = hrtime(true) + $cleanupUs * 1000;
            while (hrtime(true) < $until) {
                // stands in for cleanup work
            }
        });
        $app->getApp()->registerContext($context);
    }

    return [$app, $deferred];
}

/** @param ArrayObject<int, Closure(): void> $deferred */
function runNextSlice(ArrayObject $deferred): void {
    $next = $deferred[0];
    $deferred->exchangeArray(array_slice($deferred->getArrayCopy(), 1));
    $next();
}

test('contexts whose timers fire in one pass are destroyed in later passes, about 10 ms at a time', function (): void {
    [$app, $deferred] = appWithSlowCleanups(400, 100);

    // One pass of the event loop: every timer fires, 40 ms of cleanup work in all.
    for ($i = 0; $i < 400; ++$i) {
        $app->getApp()->cleanupTimerFired("ctx{$i}", 30_000, null);
    }
    $left = [count($app->getApp()->getAllContexts())];
    while (count($deferred) > 0) {
        runNextSlice($deferred);
        $left[] = count($app->getApp()->getAllContexts());
    }

    $slices = array_map(static fn (int $before, int $after): int => $before - $after, array_slice($left, 0, -1), array_slice($left, 1));
    expect($left[0])->toBe(400)
        ->and(end($left))->toBe(0)
        ->and(count($slices))->toBeGreaterThanOrEqual(3)
        ->and(max($slices))->toBeLessThan(200)
    ;
});

test('a context whose stream came back while it waited for its slice is kept', function (): void {
    [$app, $deferred] = appWithSlowCleanups(3, 15_000);

    foreach (['ctx0', 'ctx1', 'ctx2'] as $id) {
        $app->getApp()->cleanupTimerFired($id, 30_000, null);
    }
    runNextSlice($deferred);
    // ctx0 took the whole slice; the SSE connect of ctx1 cancels its cleanup.
    $app->getApp()->cancelContextCleanup('ctx1');
    while (count($deferred) > 0) {
        runNextSlice($deferred);
    }

    expect(array_keys($app->getApp()->getAllContexts()))->toBe(['ctx1']);
});

test('the active check runs when the context gets its slice, not when its timer fired', function (): void {
    [$app, $deferred] = appWithSlowCleanups(2, 15_000);
    $checks = 0;
    $check = static function () use (&$checks): bool {
        ++$checks;

        return false;
    };

    $app->getApp()->cleanupTimerFired('ctx0', 30_000, $check);
    $app->getApp()->cleanupTimerFired('ctx1', 30_000, $check);
    $atFire = $checks;
    runNextSlice($deferred);
    $afterFirstSlice = $checks;
    runNextSlice($deferred);

    expect([$atFire, $afterFirstSlice, $checks])->toBe([0, 1, 2])
        ->and($app->getApp()->getAllContexts())->toBe([])
    ;
});

test('a cleanup that waits on I/O holds up only its own context', function (): void {
    // Coroutines need a scheduler of their own, so this runs in a subprocess.
    $out = (string) shell_exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/expired_cleanup_yield.php') . ' 2>&1');

    expect($out)->toContain("alive_50ms=ctx0\n")
        ->and($out)->toContain("alive_450ms=\n")
    ;
});
