<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

describe('Config::withSseKeepAliveMs()', function (): void {
    test('defaults to 15 seconds', function (): void {
        expect((new Config())->freeze()->sseKeepAliveMs)->toBe(15_000);
    });

    test('sets a custom interval', function (): void {
        expect((new Config())->withSseKeepAliveMs(30_000)->freeze()->sseKeepAliveMs)->toBe(30_000);
    });

    test('0 turns the comment off and negative values clamp to 0', function (): void {
        expect((new Config())->withSseKeepAliveMs(0)->freeze()->sseKeepAliveMs)->toBe(0);
        expect((new Config())->withSseKeepAliveMs(-5)->freeze()->sseKeepAliveMs)->toBe(0);
    });

    test('leaves the Dev Bar poll interval alone', function (): void {
        $config = (new Config())->withSseKeepAliveMs(1000);

        expect($config->freeze()->ssePollIntervalMs)->toBe(100);
        expect($config)->toBeInstanceOf(Config::class);
    });
});
