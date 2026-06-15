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

        expect($config->getSessionCookieSameSite())->toBe('None');
        expect($config->getSecureCookie())->toBeTrue();
        expect($config->isSessionCookiePartitioned())->toBeTrue();
        expect($config->getFrameAncestors())->toBe(['https://x.example']);
    });

    test('normalizes a string frame-ancestor to a list', function (): void {
        expect((new Config())->withEmbeddable('https://a.example')->getFrameAncestors())
            ->toBe(['https://a.example'])
        ;
    });

    test('normalizes an array of frame-ancestors to a list', function (): void {
        expect((new Config())->withEmbeddable(['https://a.example', 'https://b.example'])->getFrameAncestors())
            ->toBe(['https://a.example', 'https://b.example'])
        ;
    });

    test('null frame-ancestors emits no restriction', function (): void {
        expect((new Config())->withEmbeddable()->getFrameAncestors())->toBeNull();
    });

    test('partitioned can be disabled', function (): void {
        expect((new Config())->withEmbeddable(null, partitioned: false)->isSessionCookiePartitioned())
            ->toBeFalse()
        ;
    });

    test('defaults leave a non-embeddable app unchanged', function (): void {
        $config = new Config();

        expect($config->getSessionCookieSameSite())->toBe('Lax');
        expect($config->isSessionCookiePartitioned())->toBeFalse();
        expect($config->getFrameAncestors())->toBeNull();
        expect($config->getSecureCookie())->toBeFalse();
    });
});
