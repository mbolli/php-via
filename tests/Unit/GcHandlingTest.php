<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Support\CycleCollector;
use Mbolli\PhpVia\Support\Stats;

describe('Config GC interval', function (): void {
    test('default interval is 30 seconds, with PHP\'s own runs on', function (): void {
        $settings = (new Config())->freeze();
        expect($settings->gcIntervalMs)->toBe(30_000)
            ->and($settings->gcOnGrowth)->toBeFalse()
        ;
    });

    test('onGrowth turns on the growth-based runs and a second call without it turns them off', function (): void {
        expect((new Config())->withGcIntervalMs(10_000, onGrowth: true)->freeze()->gcOnGrowth)->toBeTrue()
            ->and((new Config())->withGcIntervalMs(10_000, onGrowth: true)->withGcIntervalMs(5_000)->freeze()->gcOnGrowth)->toBeFalse()
        ;
    });

    test('withGcIntervalMs() sets a custom interval', function (): void {
        $config = (new Config())->withGcIntervalMs(60_000);
        expect($config->freeze()->gcIntervalMs)->toBe(60_000);
    });

    test('withGcIntervalMs(0) runs no timed collection', function (): void {
        $config = (new Config())->withGcIntervalMs(0);
        expect($config->freeze()->gcIntervalMs)->toBe(0);
    });

    test('withGcIntervalMs() clamps negative values to 0', function (): void {
        $config = (new Config())->withGcIntervalMs(-500);
        expect($config->freeze()->gcIntervalMs)->toBe(0);
    });

    test('withGcIntervalMs() is fluent', function (): void {
        $config = new Config();
        expect($config->withGcIntervalMs(5_000))->toBeInstanceOf(Config::class);
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

/**
 * A collector over a fake heap: [collector, set memory in MiB, advance the clock in ms, set the waiting roots].
 *
 * @return array{CycleCollector, Closure(int): void, Closure(int): void, Closure(int): void}
 */
function fakeCycleCollector(int $baseMib, int $limitMib = 0, int $intervalMs = 30_000): array {
    $memory = $baseMib << 20;
    $now = 1_000;
    $roots = 0;
    $collector = new CycleCollector(
        $intervalMs,
        $limitMib << 20,
        static function () use (&$memory): int { return $memory; },
        static function () use (&$now): int { return $now; },
        static function () use (&$roots): int { return $roots; },
    );

    return [
        $collector,
        static function (int $mib) use (&$memory): void { $memory = $mib << 20; },
        static function (int $ms) use (&$now): void { $now += $ms; },
        static function (int $n) use (&$roots): void { $roots = $n; },
    ];
}

describe('CycleCollector', function (): void {
    test('a run is due once memory grew by 32 MiB on a small heap', function (): void {
        [$collector, $setMemory] = fakeCycleCollector(10);

        $setMemory(41);
        $before = $collector->isDue();
        $setMemory(42);

        expect($before)->toBeFalse()->and($collector->isDue())->toBeTrue();
    });

    test('a run is due once memory grew by half on a large heap', function (): void {
        [$collector, $setMemory] = fakeCycleCollector(200);

        $setMemory(299);
        $before = $collector->isDue();
        $setMemory(300);

        expect($before)->toBeFalse()->and($collector->isDue())->toBeTrue();
    });

    test('near memory_limit a run is due after half the room left', function (): void {
        [$collector, $setMemory] = fakeCycleCollector(100, limitMib: 128);

        $setMemory(113);
        $before = $collector->isDue();
        $setMemory(114);

        expect($before)->toBeFalse()->and($collector->isDue())->toBeTrue();
    });

    test('a run is due after the interval only while possible roots wait', function (): void {
        [$collector, , $advance, $setRoots] = fakeCycleCollector(10, intervalMs: 5_000);

        $advance(4_999);
        $setRoots(5);
        $early = $collector->isDue();
        $advance(1);
        $setRoots(0);
        $noRoots = $collector->isDue();
        $setRoots(5);

        expect($early)->toBeFalse()->and($noRoots)->toBeFalse()->and($collector->isDue())->toBeTrue();
    });

    test('a run measures growth and time from where it ended', function (): void {
        [$collector, $setMemory, $advance, $setRoots] = fakeCycleCollector(10, intervalMs: 5_000);
        $setRoots(5);
        $setMemory(60);
        $advance(5_000);
        $collector->ran();

        $setMemory(91);
        $advance(4_999);
        $before = $collector->isDue();
        $setMemory(92);

        expect($before)->toBeFalse()->and($collector->isDue())->toBeTrue();
    });

    test('reads memory_limit', function (string $ini, int $bytes): void {
        expect(CycleCollector::memoryLimit($ini))->toBe($bytes);
    })->with([
        'none' => ['-1', 0],
        'empty' => ['', 0],
        'megabytes' => ['128M', 128 << 20],
        'gigabytes' => ['2g', 2 << 30],
        'kilobytes' => ['512K', 512 << 10],
        'bytes' => ['1048576', 1 << 20],
    ]);
});
