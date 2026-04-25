<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Tests for Config::withBasePath().
 *
 * Covers:
 *   1. Valid relative paths are accepted and normalised (trailing slash).
 *   2. Protocol-relative, absolute-URL, and malformed inputs throw InvalidArgumentException.
 *   3. Default basePath is '/'.
 */

describe('Config::withBasePath()', function (): void {
    // ── 1. Valid inputs ───────────────────────────────────────────────────────

    test('default basePath is "/"', function (): void {
        $config = new Config();

        expect($config->getBasePath())->toBe('/');
    });

    test('root path "/" is accepted', function (): void {
        $config = (new Config())->withBasePath('/');

        expect($config->getBasePath())->toBe('/');
    });

    test('simple sub-path gains trailing slash', function (): void {
        $config = (new Config())->withBasePath('/myapp');

        expect($config->getBasePath())->toBe('/myapp/');
    });

    test('path with trailing slash is normalised correctly', function (): void {
        $config = (new Config())->withBasePath('/myapp/');

        expect($config->getBasePath())->toBe('/myapp/');
    });

    test('nested path is accepted', function (): void {
        $config = (new Config())->withBasePath('/sub/path');

        expect($config->getBasePath())->toBe('/sub/path/');
    });

    test('path with hyphens and digits is accepted', function (): void {
        $config = (new Config())->withBasePath('/my-app2/v1');

        expect($config->getBasePath())->toBe('/my-app2/v1/');
    });

    // ── 2. Invalid inputs throw InvalidArgumentException ─────────────────────

    test('protocol-relative path throws', function (): void {
        expect(fn () => (new Config())->withBasePath('//attacker.example.com/pwn'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('absolute HTTPS URL throws', function (): void {
        expect(fn () => (new Config())->withBasePath('https://attacker.example.com/steal'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('absolute HTTP URL throws', function (): void {
        expect(fn () => (new Config())->withBasePath('http://evil.example.com/'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('path with backslash throws', function (): void {
        expect(fn () => (new Config())->withBasePath('/path\\sub'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('path with space throws', function (): void {
        expect(fn () => (new Config())->withBasePath('/path with spaces'))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('path with control character throws', function (): void {
        expect(fn () => (new Config())->withBasePath("/path\x00evil"))
            ->toThrow(\InvalidArgumentException::class);
    });

    test('javascript: URI throws', function (): void {
        expect(fn () => (new Config())->withBasePath('javascript:alert(1)'))
            ->toThrow(\InvalidArgumentException::class);
    });
});
