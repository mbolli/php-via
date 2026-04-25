<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Tests for Config::detectBasePathFromRequest().
 *
 * Covers:
 *   1. Valid relative paths are accepted and normalised.
 *   2. Protocol-relative, absolute-URL, and malformed inputs are rejected.
 *   3. A rejected value does NOT lock detection — a subsequent valid value still wins.
 *   4. Once a valid value is locked, further calls are no-ops.
 */

describe('Config::detectBasePathFromRequest()', function (): void {
    // ── Helper ───────────────────────────────────────────────────────────────

    function makeConfig(): Config {
        return new Config();
    }

    // ── 1. Valid inputs ───────────────────────────────────────────────────────

    test('root path "/" is accepted', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/');

        expect($config->getBasePath())->toBe('/');
    });

    test('simple sub-path is accepted and gains trailing slash', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/myapp');

        expect($config->getBasePath())->toBe('/myapp/');
    });

    test('path that already has trailing slash is normalised correctly', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/myapp/');

        expect($config->getBasePath())->toBe('/myapp/');
    });

    test('nested path is accepted', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/sub/path');

        expect($config->getBasePath())->toBe('/sub/path/');
    });

    test('path with hyphens and digits is accepted', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/my-app2/v1');

        expect($config->getBasePath())->toBe('/my-app2/v1/');
    });

    // ── 2. Rejected inputs — no path change, no lock ──────────────────────────

    test('null header is ignored (no change, no lock)', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest(null);

        expect($config->getBasePath())->toBe('/');

        // A subsequent valid call still works (not locked by null)
        $config->detectBasePathFromRequest('/valid');
        expect($config->getBasePath())->toBe('/valid/');
    });

    test('empty string is ignored', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('');

        expect($config->getBasePath())->toBe('/');

        $config->detectBasePathFromRequest('/valid');
        expect($config->getBasePath())->toBe('/valid/');
    });

    test('protocol-relative path is rejected', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('//attacker.example.com/pwn');

        // Default basePath unchanged
        expect($config->getBasePath())->toBe('/');

        // Not locked — a subsequent valid value is accepted
        $config->detectBasePathFromRequest('/safe');
        expect($config->getBasePath())->toBe('/safe/');
    });

    test('absolute HTTPS URL is rejected', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('https://attacker.example.com/steal');

        expect($config->getBasePath())->toBe('/');

        $config->detectBasePathFromRequest('/safe');
        expect($config->getBasePath())->toBe('/safe/');
    });

    test('absolute HTTP URL is rejected', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('http://evil.example.com/');

        expect($config->getBasePath())->toBe('/');
    });

    test('path with backslash is rejected', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/path\\sub');

        expect($config->getBasePath())->toBe('/');

        $config->detectBasePathFromRequest('/safe');
        expect($config->getBasePath())->toBe('/safe/');
    });

    test('path with space is rejected', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/path with spaces');

        expect($config->getBasePath())->toBe('/');
    });

    test('path with control character is rejected', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest("/path\x00evil");

        expect($config->getBasePath())->toBe('/');
    });

    test('javascript: URI is rejected', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('javascript:alert(1)');

        expect($config->getBasePath())->toBe('/');
    });

    // ── 3. Locking semantics ──────────────────────────────────────────────────

    test('first valid value locks detection; later calls are no-ops', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('/app');
        $config->detectBasePathFromRequest('/other');

        expect($config->getBasePath())->toBe('/app/');
    });

    test('rejected value does not lock; subsequent valid value is accepted', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('//evil.com/path');
        $config->detectBasePathFromRequest('/legitimate');

        expect($config->getBasePath())->toBe('/legitimate/');
    });

    test('multiple rejected values before a valid value still accepts the valid one', function (): void {
        $config = makeConfig();
        $config->detectBasePathFromRequest('https://evil.com');
        $config->detectBasePathFromRequest('//evil.com');
        $config->detectBasePathFromRequest('/good-path');

        expect($config->getBasePath())->toBe('/good-path/');
    });
});
