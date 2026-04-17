<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Support\Stats;

describe('Config GC interval', function (): void {
    test('default interval is 30 seconds', function (): void {
        $config = new Config();
        expect($config->getGcIntervalMs())->toBe(30_000);
    });

    test('withGcInterval() sets a custom interval', function (): void {
        $config = (new Config())->withGcInterval(60_000);
        expect($config->getGcIntervalMs())->toBe(60_000);
    });

    test('withGcInterval(0) disables the timer', function (): void {
        $config = (new Config())->withGcInterval(0);
        expect($config->getGcIntervalMs())->toBe(0);
    });

    test('withGcInterval() clamps negative values to 0', function (): void {
        $config = (new Config())->withGcInterval(-500);
        expect($config->getGcIntervalMs())->toBe(0);
    });

    test('withGcInterval() is fluent', function (): void {
        $config = new Config();
        expect($config->withGcInterval(5_000))->toBeInstanceOf(Config::class);
    });
});

describe('Stats GC tracking', function (): void {
    test('gc_runs starts at zero', function (): void {
        $stats = new Stats();
        expect($stats->getAll()['gc_runs'])->toBe(0);
    });

    test('gc_cycles_freed starts at zero', function (): void {
        $stats = new Stats();
        expect($stats->getAll()['gc_cycles_freed'])->toBe(0);
    });

    test('trackGc() increments run count', function (): void {
        $stats = new Stats();
        $stats->trackGc(0);
        $stats->trackGc(5);
        expect($stats->getAll()['gc_runs'])->toBe(2);
    });

    test('trackGc() accumulates cycles freed', function (): void {
        $stats = new Stats();
        $stats->trackGc(10);
        $stats->trackGc(25);
        expect($stats->getAll()['gc_cycles_freed'])->toBe(35);
    });

    test('trackGc() with zero cycles still increments run count', function (): void {
        $stats = new Stats();
        $stats->trackGc(0);
        expect($stats->getAll()['gc_runs'])->toBe(1);
        expect($stats->getAll()['gc_cycles_freed'])->toBe(0);
    });

    test('reset() clears gc stats', function (): void {
        $stats = new Stats();
        $stats->trackGc(42);
        $stats->reset();
        expect($stats->getAll()['gc_runs'])->toBe(0);
        expect($stats->getAll()['gc_cycles_freed'])->toBe(0);
    });
});

describe('Via::runGcCycle()', function (): void {
    test('increments gc_runs in stats', function (): void {
        $via = createVia();
        $via->runGcCycle();

        expect($via->getStats()->getAll()['gc_runs'])->toBe(1);
    });

    test('collects circular references', function (): void {
        $via = createVia();

        // Create objects with circular references that the refcount GC cannot free.
        // PHP's cycle collector is the only thing that can reclaim these.
        $count = 50;
        for ($i = 0; $i < $count; ++$i) {
            $a = new stdClass();
            $b = new stdClass();
            $a->ref = $b;
            $b->ref = $a;
            // $a and $b go out of scope here but the cycle keeps them alive
        }

        $via->runGcCycle();

        expect($via->getStats()->getAll()['gc_cycles_freed'])->toBeGreaterThan(0);
    });

    test('accumulates across multiple calls', function (): void {
        $via = createVia();
        $via->runGcCycle();
        $via->runGcCycle();
        $via->runGcCycle();

        expect($via->getStats()->getAll()['gc_runs'])->toBe(3);
    });
});
