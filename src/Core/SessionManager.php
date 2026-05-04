<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\Support\Logger;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/**
 * SessionManager - Session and cookie handling.
 *
 * Manages:
 * - Session ID generation
 * - Cookie handling
 * - Session-to-context mapping
 */
class SessionManager {
    public const string SESSION_COOKIE_NAME = 'via_session_id';
    public const string SESSION_COOKIE_NAME_SECURE = '__Host-via_session_id';

    public function __construct(
        private Logger $logger,
    ) {}

    /**
     * Determine which worker should handle a request based on session cookie.
     *
     * Used as OpenSwoole's `dispatch_func` when `worker_num > 1`. Runs in the
     * master reactor process (not a worker coroutine), so it must be allocation-free
     * and never use coroutine APIs. The raw HTTP header bytes for the first packet of
     * each new connection are passed in `$data`.
     *
     * Both cookie names are checked so that requests survive a HTTP→HTTPS migration.
     *
     * @param object $server    OpenSwoole\Http\Server (typed as object for testability)
     * @param int    $fd        Connection file descriptor
     * @param int    $type      Dispatch type (1 = data, 2 = close, 3 = connect)
     * @param string $data      Raw HTTP bytes (first packet of the connection)
     * @param int    $workerNum Total number of workers
     */
    public static function workerForRequest(object $server, int $fd, int $type, string $data, int $workerNum): int {
        if ($workerNum <= 1) {
            return 0;
        }

        // Try secure cookie first (__Host-via_session_id), then plain via_session_id.
        // Cookie values are hex strings (32 chars), so [a-f0-9]+ is a safe pattern.
        foreach ([self::SESSION_COOKIE_NAME_SECURE, self::SESSION_COOKIE_NAME] as $name) {
            $pattern = '/' . preg_quote($name, '/') . '=([a-f0-9]+)/i';
            if (preg_match($pattern, $data, $m) === 1) {
                return (int) (abs(crc32($m[1])) % $workerNum);
            }
        }

        // No session cookie yet (first request) — fall back to fd hash.
        return (int) ($fd % $workerNum);
    }

    /**
     * Get or create session ID from request cookies.
     */
    public function getOrCreateSessionId(Request $request, bool $secure = false): string {
        $cookies = $request->cookie ?? [];
        $cookieName = $secure ? self::SESSION_COOKIE_NAME_SECURE : self::SESSION_COOKIE_NAME;
        $sessionId = $cookies[$cookieName] ?? null;

        // Fall back to non-prefixed name for migration from HTTP to HTTPS
        if (!$sessionId && $secure) {
            $sessionId = $cookies[self::SESSION_COOKIE_NAME] ?? null;
        }

        if (!$sessionId) {
            // Generate new session ID
            $sessionId = bin2hex(random_bytes(16));
        }

        return $sessionId;
    }

    /**
     * Set session cookie in response.
     */
    public function setSessionCookie(Response $response, string $sessionId, bool $secure = false): void {
        // __Host- prefix: browsers enforce Secure + Path=/ + no Domain, preventing
        // cookie injection from subdomains. Only used when secureCookie is enabled.
        $cookieName = $secure ? self::SESSION_COOKIE_NAME_SECURE : self::SESSION_COOKIE_NAME;

        // Set cookie with 30 day expiration
        $expires = time() + (30 * 24 * 60 * 60);
        $result = $response->cookie(
            $cookieName,
            $sessionId,
            $expires,
            '/',
            '',
            $secure,  // Secure — set via Config::withSecureCookie(true) for HTTPS deployments
            true,     // HttpOnly
            'Lax',    // SameSite — blocks cross-site POST requests carrying the session cookie
        );
        $this->logger->log('debug', "Set session cookie: {$sessionId}, result: " . ($result ? 'success' : 'failed'));
    }
}
