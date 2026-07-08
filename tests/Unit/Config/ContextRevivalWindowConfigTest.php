<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Config::getContextRevivalWindowMs() governs how long after a context is destroyed a returning
 * tab may rebuild an equivalent one instead of hard-reloading. Mirrors the withContextCleanupDelay()
 * / withGcInterval() pattern; 0 disables revival (reconnect falls back to a reload).
 */

describe('Config context revival window', function (): void {
    test('default window is 10 minutes', function (): void {
        expect((new Config())->getContextRevivalWindowMs())->toBe(600_000);
    });

    test('withContextRevivalWindow() sets a custom window', function (): void {
        expect((new Config())->withContextRevivalWindow(120_000)->getContextRevivalWindowMs())->toBe(120_000);
    });

    test('withContextRevivalWindow(0) disables revival', function (): void {
        expect((new Config())->withContextRevivalWindow(0)->getContextRevivalWindowMs())->toBe(0);
    });

    test('withContextRevivalWindow() clamps negative values to 0', function (): void {
        expect((new Config())->withContextRevivalWindow(-1)->getContextRevivalWindowMs())->toBe(0);
    });

    test('withContextRevivalWindow() is fluent', function (): void {
        expect((new Config())->withContextRevivalWindow(30_000))->toBeInstanceOf(Config::class);
    });
});
