<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\OriginPolicy;

/*
 * CSRF Protection Tests
 *
 * Tests the two CSRF mitigations:
 *   1. Config API for secureCookie and trustedOrigins settings.
 *   2. OriginPolicy::allows(), the Origin check for actions, the Dev Bar and session close.
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

    test('trustedOrigins defaults to null (same-host check)', function (): void {
        $config = new Config();

        expect($config->getTrustedOrigins())->toBeNull();
    });

    test('withTrustedOrigins sets the allowlist', function (): void {
        $config = (new Config())->withTrustedOrigins(['https://example.com', 'https://app.example.com']);

        expect($config->getTrustedOrigins())->toBe(['https://example.com', 'https://app.example.com']);
    });

    test('withTrustedOrigins(null) falls back to the same-host check', function (): void {
        $config = (new Config())
            ->withTrustedOrigins(['https://example.com'])
            ->withTrustedOrigins(null)
        ;

        expect($config->getTrustedOrigins())->toBeNull();
    });
});

describe('OriginPolicy: Origin validation', function (): void {
    /**
     * @param null|string       $originHeader       Value of the HTTP Origin header, or null if absent
     * @param null|string       $hostHeader         Value of the HTTP Host header, or null if absent
     * @param null|list<string> $trustedOrigins     Configured allowlist
     * @param bool              $devMode            Whether dev mode is enabled
     * @param bool              $allowMissingOrigin Whether withAllowMissingOrigin() is on
     */
    function originPolicyAllows(?string $originHeader, ?array $trustedOrigins, ?string $hostHeader = null, bool $devMode = false, bool $allowMissingOrigin = false): bool {
        $config = (new Config())
            ->withTrustedOrigins($trustedOrigins)
            ->withDevMode($devMode)
            ->withAllowMissingOrigin($allowMissingOrigin)
        ;

        return OriginPolicy::allows($config, $originHeader, $hostHeader);
    }

    test('no trustedOrigins + no devMode + present cross-origin → blocked (same-host fallback)', function (): void {
        // Without an explicit allowlist, production mode falls back to same-host.
        expect(originPolicyAllows('https://evil.example.com', null, 'example.com'))->toBeFalse();
    });

    test('no trustedOrigins + no devMode + absent Origin → denied (require explicit list for prod)', function (): void {
        expect(originPolicyAllows(null, null, 'example.com', devMode: false))->toBeFalse();
    });

    test('no trustedOrigins + devMode + absent Origin → allowed (curl / local tools)', function (): void {
        expect(originPolicyAllows(null, null, 'localhost:3000', devMode: true))->toBeTrue();
    });

    test('absent Origin header with explicit list → denied in production', function (): void {
        expect(originPolicyAllows(null, ['https://example.com']))->toBeFalse();
    });

    test('absent Origin header with explicit list + withAllowMissingOrigin → allowed (non-browser clients)', function (): void {
        expect(originPolicyAllows(null, ['https://example.com'], allowMissingOrigin: true))->toBeTrue();
    });

    test('no trustedOrigins + withAllowMissingOrigin + absent Origin → allowed', function (): void {
        expect(originPolicyAllows(null, null, 'example.com', allowMissingOrigin: true))->toBeTrue();
    });

    test('matching origin → allowed', function (): void {
        expect(originPolicyAllows('https://example.com', ['https://example.com']))->toBeTrue();
    });

    test('matching one of multiple trusted origins → allowed', function (): void {
        $origins = ['https://example.com', 'https://app.example.com'];

        expect(originPolicyAllows('https://app.example.com', $origins))->toBeTrue();
    });

    test('untrusted origin → blocked', function (): void {
        expect(originPolicyAllows('https://evil.example.com', ['https://example.com']))->toBeFalse();
    });

    test('origin matching is exact, not prefix-based', function (): void {
        // 'https://example.com.evil.com' must NOT match 'https://example.com'
        expect(originPolicyAllows('https://example.com.evil.com', ['https://example.com']))->toBeFalse();
    });

    test('origin matching is case-sensitive', function (): void {
        expect(originPolicyAllows('https://EXAMPLE.COM', ['https://example.com']))->toBeFalse();
    });

    test('empty trusted origins list blocks all browser requests', function (): void {
        // trustedOrigins=[] means no origin is whitelisted
        expect(originPolicyAllows('https://example.com', []))->toBeFalse();
        // Absent Origin (non-browser) is denied too, unless opted in
        expect(originPolicyAllows(null, []))->toBeFalse();
        expect(originPolicyAllows(null, [], allowMissingOrigin: true))->toBeTrue();
        expect(originPolicyAllows('https://example.com', [], allowMissingOrigin: true))->toBeFalse();
    });
});

describe('OriginPolicy: same-host fallback (no explicit list)', function (): void {
    test('same-host origin is allowed in prod without explicit list', function (): void {
        expect(originPolicyAllows('https://example.com', null, 'example.com'))->toBeTrue();
    });

    test('same-host with port is allowed', function (): void {
        expect(originPolicyAllows('https://localhost:3000', null, 'localhost:3000'))->toBeTrue();
    });

    test('http origin also matches same host (proxy strips TLS)', function (): void {
        expect(originPolicyAllows('http://example.com', null, 'example.com'))->toBeTrue();
    });

    test('cross-origin is blocked in prod without explicit list', function (): void {
        expect(originPolicyAllows('https://attacker.com', null, 'example.com'))->toBeFalse();
    });

    test('dev mode: same-host is allowed', function (): void {
        expect(originPolicyAllows('https://localhost:3000', null, 'localhost:3000', devMode: true))->toBeTrue();
    });

    test('dev mode: cross-origin is still blocked', function (): void {
        expect(originPolicyAllows('https://attacker.com', null, 'localhost:3000', devMode: true))->toBeFalse();
    });

    test('no Host header in prod → denied', function (): void {
        expect(originPolicyAllows('https://example.com', null, null, devMode: false))->toBeFalse();
    });

    test('no Host header in dev → allowed (unusual local setup)', function (): void {
        expect(originPolicyAllows('https://example.com', null, null, devMode: true))->toBeTrue();
    });
});
