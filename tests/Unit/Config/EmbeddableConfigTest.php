<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Config::withEmbeddable() — cross-origin iframe embedding.
 *
 * Verifies the opinionated bundle (SameSite=None + Secure + Partitioned) and
 * frame-ancestors normalization, plus that defaults stay byte-identical.
 */

describe('Config::withEmbeddable()', function (): void {
    test('sets SameSite=None, Secure, partitioned, and frame-ancestors', function (): void {
        $config = (new Config())->withEmbeddable('https://x.example');

        expect($config->freeze()->sessionCookieSameSite)->toBe('None');
        expect($config->freeze()->secureCookie)->toBeTrue();
        expect($config->freeze()->sessionCookiePartitioned)->toBeTrue();
        expect($config->freeze()->frameAncestors)->toBe(['https://x.example']);
    });

    test('normalizes a string frame-ancestor to a list', function (): void {
        expect((new Config())->withEmbeddable('https://a.example')->freeze()->frameAncestors)
            ->toBe(['https://a.example'])
        ;
    });

    test('normalizes an array of frame-ancestors to a list', function (): void {
        expect((new Config())->withEmbeddable(['https://a.example', 'https://b.example'])->freeze()->frameAncestors)
            ->toBe(['https://a.example', 'https://b.example'])
        ;
    });

    test('null frame-ancestors emits no restriction', function (): void {
        expect((new Config())->withEmbeddable()->freeze()->frameAncestors)->toBeNull();
    });

    test('partitioned can be disabled', function (): void {
        expect((new Config())->withEmbeddable(null, partitioned: false)->freeze()->sessionCookiePartitioned)
            ->toBeFalse()
        ;
    });

    test('defaults leave a non-embeddable app unchanged', function (): void {
        $config = new Config();

        expect($config->freeze()->sessionCookieSameSite)->toBe('Lax');
        expect($config->freeze()->sessionCookiePartitioned)->toBeFalse();
        expect($config->freeze()->frameAncestors)->toBeNull();
        expect($config->freeze()->secureCookie)->toBeFalse();
    });
});
