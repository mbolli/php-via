<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Support\Stats;

describe('Config broadcast coalescing', function (): void {
    test('coalescing is on by default with a 25 ms tick', function (): void {
        $config = new Config();

        expect($config->isBroadcastCoalescingEnabled())->toBeTrue();
        expect($config->getBroadcastTickMs())->toBe(25);
    });

    test('withBroadcastCoalescing(false) turns it off', function (): void {
        expect((new Config())->withBroadcastCoalescing(false)->isBroadcastCoalescingEnabled())->toBeFalse();
        expect((new Config())->withBroadcastCoalescing()->isBroadcastCoalescingEnabled())->toBeTrue();
    });

    test('withBroadcastTickMs() sets the tick, 0 keeps no gap and negatives clamp to 0', function (): void {
        expect((new Config())->withBroadcastTickMs(50)->getBroadcastTickMs())->toBe(50);
        expect((new Config())->withBroadcastTickMs(0)->getBroadcastTickMs())->toBe(0);
        expect((new Config())->withBroadcastTickMs(-10)->getBroadcastTickMs())->toBe(0);
    });
});

describe('broadcast flush stats', function (): void {
    test('flushes, overruns and durations are tracked per tick', function (): void {
        $stats = new Stats();
        $stats->trackBroadcastScheduled(false);
        $stats->trackBroadcastScheduled(true);
        $stats->trackBroadcastScheduled(true);
        $stats->trackBroadcastFlush(4.0, 25);
        $stats->trackBroadcastFlush(30.0, 25);
        $stats->trackBroadcastFlush(2.5, 25);
        // Without a tick nothing can overrun it.
        $stats->trackBroadcastFlush(100.0, 0);

        expect($stats->getBroadcastStats())->toBe([
            'scheduled' => 3,
            'coalesced' => 2,
            'flushes' => 4,
            'flush_overruns' => 1,
            'flush_last_ms' => 100.0,
            'flush_max_ms' => 100.0,
            'flush_total_ms' => 136.5,
        ]);
        expect($stats->getAll())->toHaveKey('broadcast_flushes', 4);

        $stats->reset();
        expect($stats->getBroadcastStats()['flushes'])->toBe(0);
    });
});
