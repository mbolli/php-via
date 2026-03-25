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
     * @param null|list<string> $trustedOrigins Configured allowlist
     */
    function callIsOriginAllowed(?string $originHeader, ?array $trustedOrigins): bool {
        $config = (new Config())->withTrustedOrigins($trustedOrigins);
        $via = new Via($config);

        $handler = new ActionHandler($via);

        $method = new ReflectionMethod(ActionHandler::class, 'isOriginAllowed');

        return $method->invoke($handler, $originHeader);
    }

    test('no trustedOrigins configured → all origins allowed', function (): void {
        expect(callIsOriginAllowed('https://evil.example.com', null))->toBeTrue();
        expect(callIsOriginAllowed(null, null))->toBeTrue();
    });

    test('absent Origin header → allowed regardless of allowlist', function (): void {
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
        // But absent Origin (non-browser) still allowed
        expect(callIsOriginAllowed(null, []))->toBeTrue();
    });
});
