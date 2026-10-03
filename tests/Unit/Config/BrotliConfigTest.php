<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

describe('Config Brotli', function (): void {
    test('static files get level 11 without a call to withBrotli(), pages and SSE get none', function (): void {
        $config = new Config();

        expect($config->freeze()->brotli)->toBeFalse()
            ->and($config->freeze()->brotliStaticLevel)->toBe(11)
        ;
    });

    test('withBrotli() turns on pages and SSE and keeps level 11 for static files', function (): void {
        $config = (new Config())->withBrotli();

        expect($config->freeze()->brotli)->toBeTrue()
            ->and($config->freeze()->brotliDynamicLevel)->toBe(4)
            ->and($config->freeze()->brotliStaticLevel)->toBe(11)
        ;
    });

    test('withBrotli(false) turns Brotli off for static files too', function (): void {
        $config = (new Config())->withBrotli()->withBrotli(false);

        expect($config->freeze()->brotli)->toBeFalse()
            ->and($config->freeze()->brotliStaticLevel)->toBe(0)
        ;
    });

    test('an explicit static level wins over $enabled, clamped to 0 to 11', function (): void {
        expect((new Config())->withBrotli(false, staticLevel: 11)->freeze()->brotliStaticLevel)->toBe(11)
            ->and((new Config())->withBrotli(true, staticLevel: 0)->freeze()->brotliStaticLevel)->toBe(0)
            ->and((new Config())->withBrotli(staticLevel: 14)->freeze()->brotliStaticLevel)->toBe(11)
            ->and((new Config())->withBrotli(staticLevel: -1)->freeze()->brotliStaticLevel)->toBe(0)
        ;
    });
});
