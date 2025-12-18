<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\Support\Logger;
use Swoole\Http\Request;
use Swoole\Http\Response;

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

    public function __construct(
        private Logger $logger,
    ) {}

    /**
     * Get or create session ID from request cookies.
     */
    public function getOrCreateSessionId(Request $request): string {
        $cookies = $request->cookie ?? [];
        $sessionId = $cookies[self::SESSION_COOKIE_NAME] ?? null;

        if (!$sessionId) {
            // Generate new session ID
            $sessionId = bin2hex(random_bytes(16));
        }

        return $sessionId;
    }

    /**
     * Set session cookie in response.
     */
    public function setSessionCookie(Response $response, string $sessionId): void {
        // Set cookie with 30 day expiration
        $expires = time() + (30 * 24 * 60 * 60);
        $result = $response->cookie(
            self::SESSION_COOKIE_NAME,
            $sessionId,
            $expires,
            '/',
            '',
            false, // secure (set to true for HTTPS)
            true   // httponly
        );
        $this->logger->log('debug', "Set session cookie: {$sessionId}, result: " . ($result ? 'success' : 'failed'));
    }
}
