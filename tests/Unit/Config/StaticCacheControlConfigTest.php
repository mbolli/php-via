<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Config::getStaticCacheControl() governs Cache-Control for /datastar.js, /via.css,
 * and withStaticDir() files. Default follows devMode (mirrors withTracing()'s
 * null = follow devMode pattern) so withStaticDir() edits are visible immediately
 * in local dev without waiting out a cached max-age.
 */

describe('Config::getStaticCacheControl()', function (): void {
    test('defaults to a 1 hour revalidated cache outside devMode', function (): void {
        expect((new Config())->getStaticCacheControl())->toBe('public, max-age=3600, must-revalidate');
    });

    test('defaults to always-revalidate in devMode', function (): void {
        expect((new Config())->withDevMode()->getStaticCacheControl())->toBe('no-cache');
    });

    test('an explicit value overrides the devMode-based default', function (): void {
        $config = (new Config())->withDevMode()->withStaticCacheControl('public, max-age=31536000, immutable');
        expect($config->getStaticCacheControl())->toBe('public, max-age=31536000, immutable');
    });

    test('passing null restores the devMode-based default', function (): void {
        $config = (new Config())
            ->withStaticCacheControl('no-store')
            ->withStaticCacheControl(null)
        ;
        expect($config->getStaticCacheControl())->toBe('public, max-age=3600, must-revalidate');
    });
});
