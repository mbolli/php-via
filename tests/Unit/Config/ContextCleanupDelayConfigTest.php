<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Config::getContextCleanupDelayMs() governs how long php-via waits after an SSE
 * disconnect before destroying the context, allowing time for page navigation or a
 * brief reconnect. Mirrors the Config::withGcInterval() pattern.
 */

describe('Config context cleanup delay', function (): void {
    test('default delay is 5 seconds', function (): void {
        $config = new Config();
        expect($config->getContextCleanupDelayMs())->toBe(5000);
    });

    test('withContextCleanupDelay() sets a custom delay', function (): void {
        $config = (new Config())->withContextCleanupDelay(60_000);
        expect($config->getContextCleanupDelayMs())->toBe(60_000);
    });

    test('withContextCleanupDelay(0) disables the grace period', function (): void {
        $config = (new Config())->withContextCleanupDelay(0);
        expect($config->getContextCleanupDelayMs())->toBe(0);
    });

    test('withContextCleanupDelay() clamps negative values to 0', function (): void {
        $config = (new Config())->withContextCleanupDelay(-500);
        expect($config->getContextCleanupDelayMs())->toBe(0);
    });

    test('withContextCleanupDelay() is fluent', function (): void {
        $config = new Config();
        expect($config->withContextCleanupDelay(10_000))->toBeInstanceOf(Config::class);
    });
});
