<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Core\SessionManager;

/*
 * SessionManager::buildCookieHeader() — pure Set-Cookie builder used for the
 * Partitioned (CHIPS) path that OpenSwoole's cookie() cannot emit.
 *
 * Plus the start() guards added by withEmbeddable(): SameSite=None requires both
 * a Secure cookie and an HTTPS-fronted deployment.
 */

describe('SessionManager::buildCookieHeader()', function (): void {
    test('emits a full partitioned __Host- cookie', function (): void {
        expect(SessionManager::buildCookieHeader('__Host-via_session_id', 'abc', 2592000, true, 'None', true))
            ->toBe('__Host-via_session_id=abc; Path=/; Max-Age=2592000; HttpOnly; SameSite=None; Secure; Partitioned')
        ;
    });

    test('omits Partitioned and Secure for the Lax/insecure default', function (): void {
        expect(SessionManager::buildCookieHeader('via_session_id', 'abc', 2592000, false, 'Lax', false))
            ->toBe('via_session_id=abc; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax')
        ;
    });

    test('includes Secure but not Partitioned when secure-only', function (): void {
        expect(SessionManager::buildCookieHeader('via_session_id', 'abc', 2592000, true, 'Lax', false))
            ->toBe('via_session_id=abc; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax; Secure')
        ;
    });
});

describe('start() embeddable guard', function (): void {
    test('throws when SameSite=None lacks HTTPS or h2c', function (): void {
        $via = createVia((new Config())->withEmbeddable());
        expect(fn () => $via->start())->toThrow(
            RuntimeException::class,
            'withEmbeddable() sets SameSite=None, which requires Secure cookies'
        );
    });

    test('throws when withSecureCookie(false) is called after withEmbeddable()', function (): void {
        $via = createVia((new Config())->withEmbeddable()->withSecureCookie(false));
        expect(fn () => $via->start())->toThrow(
            RuntimeException::class,
            'withEmbeddable() requires Secure cookies'
        );
    });
});
