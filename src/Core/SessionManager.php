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
    private const string SESSION_COOKIE_NAME = 'via_session_id';
    private const string SESSION_COOKIE_NAME_SECURE = '__Host-via_session_id';

    public function __construct(
        private Logger $logger,
    ) {}

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
