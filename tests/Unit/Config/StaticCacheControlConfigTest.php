<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

function phpViaTestStaticCacheControlCallable(string $filePath, string $mimeType): string {
    return "public, max-age=1 ({$mimeType})";
}

/*
 * withStaticCacheControl() governs Cache-Control for /datastar.js, /via.css,
 * and withStaticDir() files. Default follows devMode (mirrors withDevBar()'s
 * null = follow devMode pattern) so withStaticDir() edits are visible immediately
 * in local dev without waiting out a cached max-age. A callable can also be passed
 * to fine-tune the value per file path / MIME type.
 */

describe('Settings::staticCacheControl(): string / default', function (): void {
    test('defaults to a 1 hour revalidated cache outside devMode', function (): void {
        expect((new Config())->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('public, max-age=3600, must-revalidate')
        ;
    });

    test('defaults to always-revalidate in devMode', function (): void {
        expect((new Config())->withDevMode()->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('no-cache')
        ;
    });

    test('an explicit value overrides the devMode-based default', function (): void {
        $config = (new Config())->withDevMode()->withStaticCacheControl('public, max-age=31536000, immutable');
        expect($config->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('public, max-age=31536000, immutable')
        ;
    });

    test('passing null restores the devMode-based default', function (): void {
        $config = (new Config())
            ->withStaticCacheControl('no-store')
            ->withStaticCacheControl(null)
        ;
        expect($config->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('public, max-age=3600, must-revalidate')
        ;
    });
});

describe('Settings::staticCacheControl(): callable', function (): void {
    test('invokes the callable with the file path and MIME type', function (): void {
        $seen = [];
        $config = (new Config())->withStaticCacheControl(function (string $filePath, string $mimeType) use (&$seen): string {
            $seen[] = [$filePath, $mimeType];

            return 'public, max-age=60';
        });

        $result = $config->freeze()->staticCacheControl('/app/public/app.css', 'text/css');

        expect($result)->toBe('public, max-age=60');
        expect($seen)->toBe([['/app/public/app.css', 'text/css']]);
    });

    test('can fine-tune per MIME type', function (): void {
        $config = (new Config())->withStaticCacheControl(
            fn (string $filePath, string $mimeType): string => str_starts_with($mimeType, 'font/')
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=3600, must-revalidate'
        );

        expect($config->freeze()->staticCacheControl('/app/public/icon.woff2', 'font/woff2'))
            ->toBe('public, max-age=31536000, immutable')
        ;
        expect($config->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('public, max-age=3600, must-revalidate')
        ;
    });

    test('keeps the default for a file the callable returns null for', function (bool $devMode): void {
        $config = (new Config())->withDevMode($devMode)->withStaticCacheControl(
            fn (string $filePath): ?string => str_contains($filePath, '/fonts/') ? 'public, max-age=31536000, immutable' : null
        );

        expect($config->freeze()->staticCacheControl('/app/public/fonts/a.woff2', 'font/woff2'))->toBe('public, max-age=31536000, immutable')
            ->and($config->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))->toBe($devMode ? 'no-cache' : 'public, max-age=3600, must-revalidate')
            ->and($config->freeze()->staticCacheControl('/vendor/php-via/public/datastar.js', 'application/javascript', versioned: true))
            ->toBe($devMode ? 'no-cache' : 'public, max-age=31536000, immutable')
        ;
    })->with(['production' => false, 'devMode' => true]);

    test('accepts a first-class callable reference to a named function', function (): void {
        $config = (new Config())->withStaticCacheControl(phpViaTestStaticCacheControlCallable(...));
        expect($config->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('public, max-age=1 (text/css)')
        ;
    });

    test('a plain string is always taken literally, never invoked as a function name', function (): void {
        $config = (new Config())->withStaticCacheControl('phpViaTestStaticCacheControlCallable');
        expect($config->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('phpViaTestStaticCacheControlCallable')
        ;
    });

    test('a subsequent withStaticCacheControl(null) clears a previously set callable', function (): void {
        $config = (new Config())
            ->withStaticCacheControl(fn (): string => 'public, max-age=1')
            ->withStaticCacheControl(null)
        ;
        expect($config->freeze()->staticCacheControl('/app/public/app.css', 'text/css'))
            ->toBe('public, max-age=3600, must-revalidate')
        ;
    });
});
