<?php

declare(strict_types=1);

use Mbolli\PhpVia\Core\SessionManager;

/*
 * SessionManager::workerForRequest() unit tests.
 *
 * Tests the pure routing logic that maps an incoming HTTP request (identified by
 * its raw first-packet bytes) to a worker index. No server socket required — the
 * $server argument is unused by the method itself; an stdClass stub suffices.
 */

describe('SessionManager::workerForRequest()', function (): void {
    /** Minimal HTTP/1.1 request line with a Cookie header. */
    function makeRequest(string $cookieHeader = ''): string {
        $base = "GET / HTTP/1.1\r\nHost: localhost\r\n";
        if ($cookieHeader !== '') {
            $base .= "Cookie: {$cookieHeader}\r\n";
        }

        return $base . "\r\n";
    }

    $stub = new stdClass();

    test('returns 0 when workerNum is 1', function () use ($stub): void {
        $data = makeRequest('via_session_id=deadbeefdeadbeefdeadbeefdeadbeef');
        $worker = SessionManager::workerForRequest($stub, 1, 1, $data, 1);
        expect($worker)->toBe(0);
    });

    test('returns fd % workerNum when no session cookie present', function () use ($stub): void {
        $data = makeRequest(); // no Cookie header
        $worker = SessionManager::workerForRequest($stub, 7, 1, $data, 4);
        expect($worker)->toBe(7 % 4); // 3
    });

    test('routes same session ID to same worker consistently', function () use ($stub): void {
        $sessionId = 'aabbccddeeff00112233445566778899';
        $data = makeRequest("via_session_id={$sessionId}");
        $expected = (int) (abs(crc32($sessionId)) % 4);

        expect(SessionManager::workerForRequest($stub, 99, 1, $data, 4))->toBe($expected);
        // Second call must return identical result
        expect(SessionManager::workerForRequest($stub, 99, 1, $data, 4))->toBe($expected);
    });

    test('different session IDs may map to different workers', function () use ($stub): void {
        // Use two sessions that are known to hash differently mod 4
        $results = [];
        foreach (['aaaa0000aaaa0000aaaa0000aaaa0000', 'bbbb1111bbbb1111bbbb1111bbbb1111'] as $sid) {
            $results[] = SessionManager::workerForRequest($stub, 1, 1, makeRequest("via_session_id={$sid}"), 4);
        }
        // Values are between 0 and 3
        foreach ($results as $r) {
            expect($r)->toBeGreaterThanOrEqual(0)->toBeLessThan(4);
        }
    });

    test('prefers __Host-via_session_id over plain via_session_id', function () use ($stub): void {
        $secureId = 'aaaabbbbccccdddd0000111122223333';
        $plainId = 'ffff0000ffff0000ffff0000ffff0000';
        // Both cookies present; secure one should win
        $data = makeRequest("__Host-via_session_id={$secureId}; via_session_id={$plainId}");

        $expected = (int) (abs(crc32($secureId)) % 4);
        expect(SessionManager::workerForRequest($stub, 1, 1, $data, 4))->toBe($expected);
    });

    test('falls back to plain cookie when only plain cookie present', function () use ($stub): void {
        $sessionId = '12345678901234567890123456789012';
        $data = makeRequest("via_session_id={$sessionId}");
        $expected = (int) (abs(crc32($sessionId)) % 8);
        expect(SessionManager::workerForRequest($stub, 5, 1, $data, 8))->toBe($expected);
    });

    test('result is always a valid worker index (0 to workerNum-1)', function () use ($stub): void {
        foreach (range(1, 20) as $_) {
            $sid = bin2hex(random_bytes(16));
            $w = SessionManager::workerForRequest($stub, 1, 1, makeRequest("via_session_id={$sid}"), 4);
            expect($w)->toBeGreaterThanOrEqual(0)->toBeLessThan(4);
        }
    });

    test('ignores malformed / non-hex cookie value and falls back to fd', function () use ($stub): void {
        // Cookie value contains non-hex chars — pattern won't match
        $data = makeRequest('via_session_id=not-a-valid-hex-id!');
        $worker = SessionManager::workerForRequest($stub, 3, 1, $data, 4);
        expect($worker)->toBe(3 % 4); // fd fallback
    });
});
