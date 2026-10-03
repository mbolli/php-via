<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\Stats;
use Mbolli\PhpVia\Via;

describe('Config broadcast coalescing', function (): void {
    test('coalescing is on by default with a 25 ms tick', function (): void {
        $config = new Config();

        expect($config->freeze()->broadcastCoalescingEnabled)->toBeTrue();
        expect($config->freeze()->broadcastTickMs)->toBe(25);
    });

    test('withBroadcastCoalescing(false) turns it off', function (): void {
        expect((new Config())->withBroadcastCoalescing(false)->freeze()->broadcastCoalescingEnabled)->toBeFalse();
        expect((new Config())->withBroadcastCoalescing()->freeze()->broadcastCoalescingEnabled)->toBeTrue();
    });

    test('new Via() warns that turning coalescing off is deprecated, and stays quiet with it on', function (): void {
        $boot = static function (Config $config): string {
            ob_start();

            try {
                new Via($config->withLogLevel('warn'));

                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        };

        expect($boot((new Config())->withBroadcastCoalescing(false)))
            ->toContain('Config::withBroadcastCoalescing(false) is deprecated and goes in php-via 0.15')
            ->toContain('$app->flushBroadcasts()')
            ->and($boot(new Config()))->not->toContain('withBroadcastCoalescing')
        ;
    });

    test('withBroadcastTickMs() sets the tick, 0 keeps no gap and negatives clamp to 0', function (): void {
        expect((new Config())->withBroadcastTickMs(50)->freeze()->broadcastTickMs)->toBe(50);
        expect((new Config())->withBroadcastTickMs(0)->freeze()->broadcastTickMs)->toBe(0);
        expect((new Config())->withBroadcastTickMs(-10)->freeze()->broadcastTickMs)->toBe(0);
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

describe('Config::withBroadcastThrottle()', function (): void {
    test('sets an interval per scope or pattern, the longest matching one applies, and 0 removes it', function (): void {
        $settings = (new Config())
            ->withBroadcastThrottle('import:*', 250)
            ->withBroadcastThrottle('import:big', 1000)
            ->withBroadcastThrottle('route:/live', 50)
            ->withBroadcastThrottle('room:1', 10)
            ->withBroadcastThrottle('room:1', 0)
            ->freeze()
        ;

        expect($settings->broadcastThrottles)->toBe(['import:*' => 250, 'import:big' => 1000, 'route:/live' => 50])
            ->and($settings->broadcastThrottleMs('import:7'))->toBe(250)
            ->and($settings->broadcastThrottleMs('import:big'))->toBe(1000)
            ->and($settings->broadcastThrottleMs('route:/live'))->toBe(50)
            ->and($settings->broadcastThrottleMs('room:1'))->toBe(0)
            ->and((new Config())->freeze()->broadcastThrottleMs('import:7'))->toBe(0)
        ;
    });

    test('throws for a scope that needs a context to resolve, or a negative interval', function (string $scope, int $ms): void {
        (new Config())->withBroadcastThrottle($scope, $ms);
    })->throws(InvalidArgumentException::class)->with([
        'tab' => [Scope::TAB, 100],
        'route' => [Scope::ROUTE, 100],
        'session' => [Scope::SESSION, 100],
        'negative' => ['import:*', -1],
    ]);
});
