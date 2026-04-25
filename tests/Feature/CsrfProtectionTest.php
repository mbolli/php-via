<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Via;

/*
 * CSRF Protection Tests
 *
 * Tests the two CSRF mitigations:
 *   1. Config API for secureCookie and trustedOrigins settings.
 *   2. ActionHandler::isOriginAllowed() logic (via reflection).
 */

describe('Config: CSRF options', function (): void {
    test('secureCookie defaults to false', function (): void {
        $config = new Config();

        expect($config->getSecureCookie())->toBeFalse();
    });

    test('withSecureCookie(true) enables secure flag', function (): void {
        $config = (new Config())->withSecureCookie(true);

        expect($config->getSecureCookie())->toBeTrue();
    });

    test('withSecureCookie(false) explicitly disables secure flag', function (): void {
        $config = (new Config())->withSecureCookie(false);

        expect($config->getSecureCookie())->toBeFalse();
    });

    test('trustedOrigins defaults to null (no restriction)', function (): void {
        $config = new Config();

        expect($config->getTrustedOrigins())->toBeNull();
    });

    test('withTrustedOrigins sets the allowlist', function (): void {
        $config = (new Config())->withTrustedOrigins(['https://example.com', 'https://app.example.com']);

        expect($config->getTrustedOrigins())->toBe(['https://example.com', 'https://app.example.com']);
    });

    test('withTrustedOrigins(null) disables restriction', function (): void {
        $config = (new Config())
            ->withTrustedOrigins(['https://example.com'])
            ->withTrustedOrigins(null)
        ;

        expect($config->getTrustedOrigins())->toBeNull();
    });
});

describe('ActionHandler: Origin validation', function (): void {
    /**
     * Helper: call the private isOriginAllowed() method via reflection.
     *
     * @param null|string       $originHeader   Value of the HTTP Origin header, or null if absent
     * @param null|string       $hostHeader     Value of the HTTP Host header, or null if absent
     * @param null|list<string> $trustedOrigins Configured allowlist
     * @param bool              $devMode        Whether dev mode is enabled
     */
    function callIsOriginAllowed(?string $originHeader, ?array $trustedOrigins, ?string $hostHeader = null, bool $devMode = false): bool {
        $config = (new Config())
            ->withTrustedOrigins($trustedOrigins)
            ->withDevMode($devMode)
        ;
        $via = new Via($config);

        $handler = new ActionHandler($via);

        $method = new ReflectionMethod(ActionHandler::class, 'isOriginAllowed');

        return $method->invoke($handler, $originHeader, $hostHeader);
    }

    test('no trustedOrigins + no devMode + present cross-origin → blocked (same-host fallback)', function (): void {
        // Without an explicit allowlist, production mode falls back to same-host.
        expect(callIsOriginAllowed('https://evil.example.com', null, 'example.com'))->toBeFalse();
    });

    test('no trustedOrigins + no devMode + absent Origin → denied (require explicit list for prod)', function (): void {
        expect(callIsOriginAllowed(null, null, 'example.com', devMode: false))->toBeFalse();
    });

    test('no trustedOrigins + devMode + absent Origin → allowed (curl / local tools)', function (): void {
        expect(callIsOriginAllowed(null, null, 'localhost:3000', devMode: true))->toBeTrue();
    });

    test('absent Origin header with explicit list → allowed (non-browser clients)', function (): void {
        expect(callIsOriginAllowed(null, ['https://example.com']))->toBeTrue();
    });

    test('matching origin → allowed', function (): void {
        expect(callIsOriginAllowed('https://example.com', ['https://example.com']))->toBeTrue();
    });

    test('matching one of multiple trusted origins → allowed', function (): void {
        $origins = ['https://example.com', 'https://app.example.com'];

        expect(callIsOriginAllowed('https://app.example.com', $origins))->toBeTrue();
    });

    test('untrusted origin → blocked', function (): void {
        expect(callIsOriginAllowed('https://evil.example.com', ['https://example.com']))->toBeFalse();
    });

    test('origin matching is exact, not prefix-based', function (): void {
        // 'https://example.com.evil.com' must NOT match 'https://example.com'
        expect(callIsOriginAllowed('https://example.com.evil.com', ['https://example.com']))->toBeFalse();
    });

    test('origin matching is case-sensitive', function (): void {
        expect(callIsOriginAllowed('https://EXAMPLE.COM', ['https://example.com']))->toBeFalse();
    });

    test('empty trusted origins list blocks all browser requests', function (): void {
        // trustedOrigins=[] means no origin is whitelisted
        expect(callIsOriginAllowed('https://example.com', []))->toBeFalse();
        // But absent Origin (non-browser) still allowed when allowlist is configured
        expect(callIsOriginAllowed(null, []))->toBeTrue();
    });
});

describe('ActionHandler: same-host fallback (no explicit list)', function (): void {
    test('same-host origin is allowed in prod without explicit list', function (): void {
        expect(callIsOriginAllowed('https://example.com', null, 'example.com'))->toBeTrue();
    });

    test('same-host with port is allowed', function (): void {
        expect(callIsOriginAllowed('https://localhost:3000', null, 'localhost:3000'))->toBeTrue();
    });

    test('http origin also matches same host (proxy strips TLS)', function (): void {
        expect(callIsOriginAllowed('http://example.com', null, 'example.com'))->toBeTrue();
    });

    test('cross-origin is blocked in prod without explicit list', function (): void {
        expect(callIsOriginAllowed('https://attacker.com', null, 'example.com'))->toBeFalse();
    });

    test('dev mode: same-host is allowed', function (): void {
        expect(callIsOriginAllowed('https://localhost:3000', null, 'localhost:3000', devMode: true))->toBeTrue();
    });

    test('dev mode: cross-origin is still blocked', function (): void {
        expect(callIsOriginAllowed('https://attacker.com', null, 'localhost:3000', devMode: true))->toBeFalse();
    });

    test('no Host header in prod → denied', function (): void {
        expect(callIsOriginAllowed('https://example.com', null, null, devMode: false))->toBeFalse();
    });

    test('no Host header in dev → allowed (unusual local setup)', function (): void {
        expect(callIsOriginAllowed('https://example.com', null, null, devMode: true))->toBeTrue();
    });
});
