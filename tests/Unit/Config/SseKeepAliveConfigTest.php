<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

describe('Config::withSseKeepAliveMs()', function (): void {
    test('defaults to 15 seconds', function (): void {
        expect((new Config())->getSseKeepAliveMs())->toBe(15_000);
    });

    test('sets a custom interval', function (): void {
        expect((new Config())->withSseKeepAliveMs(30_000)->getSseKeepAliveMs())->toBe(30_000);
    });

    test('0 turns the comment off and negative values clamp to 0', function (): void {
        expect((new Config())->withSseKeepAliveMs(0)->getSseKeepAliveMs())->toBe(0);
        expect((new Config())->withSseKeepAliveMs(-5)->getSseKeepAliveMs())->toBe(0);
    });

    test('leaves the Dev Bar poll interval alone', function (): void {
        $config = (new Config())->withSseKeepAliveMs(1000);

        expect($config->getSsePollIntervalMs())->toBe(100);
        expect($config)->toBeInstanceOf(Config::class);
    });
});
