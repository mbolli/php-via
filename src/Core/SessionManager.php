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

    /** @var \WeakMap<Request, string> The id issued to each request without a valid cookie */
    private \WeakMap $issued;

    public function __construct(
        private Logger $logger,
    ) {
        $this->issued = new \WeakMap();
    }

    /**
     * Determine which worker should handle a request based on session cookie.
     *
     * NOT WIRED BY DEFAULT. This was installed as OpenSwoole's `dispatch_func` when
     * `worker_num > 1`, alongside `dispatch_mode = 7`, but `SW_DISPATCH_USERFUNC` is
     * 6, and 7 is stream mode, which ignores `dispatch_func` entirely. The affinity
     * therefore never ran, and mode 7 scatters per request where OpenSwoole's default
     * is sticky per connection, so it was worse than its own absence.
     *
     * Setting mode 6 is not shippable either: on PHP 8.3+ a `dispatch_func` runs on
     * the master reactor thread, where the stack-limit check mis-detects the stack
     * base and fatals on every dispatch. Only `zend.max_allowed_stack_size=-1` clears
     * that, and the ini is not settable at runtime.
     *
     * Prefer sticky routing at the L7 proxy (Caddy `lb_policy cookie`, nginx
     * `ip_hash`), which the deployment docs already require. An operator who has set
     * the ini can wire this manually via `Config::withSwooleSettings()` with
     * `dispatch_mode => 6`.
     *
     * Runs in the master reactor process (not a worker coroutine), so it must be
     * allocation-free and never use coroutine APIs. The raw HTTP header bytes for the
     * first packet of each new connection are passed in `$data`.
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

        // No session cookie yet (first request): fall back to fd hash.
        return (int) ($fd % $workerNum);
    }

    /**
     * Get or create session ID from request cookies.
     *
     * Only an id in the form this class issues is taken; anything else starts a new session. With secure
     * cookies only the __Host- cookie counts: a sibling subdomain or a plain-HTTP response can set the plain one.
     * A request without a valid cookie gets the same new id on every call, so the 'via.session' attribute
     * middleware reads is the id the page then sets.
     */
    public function getOrCreateSessionId(Request $request, bool $secure = false): string {
        $cookies = $request->cookie ?? [];
        $sessionId = $cookies[$secure ? self::SESSION_COOKIE_NAME_SECURE : self::SESSION_COOKIE_NAME] ?? null;

        if (\is_string($sessionId) && self::isValidSessionId($sessionId)) {
            return $sessionId;
        }

        return $this->issued[$request] ??= bin2hex(random_bytes(16));
    }

    /**
     * Whether $sessionId has the form of an id this class issues: 32 lowercase hex characters.
     */
    public static function isValidSessionId(string $sessionId): bool {
        return \strlen($sessionId) === 32 && strspn($sessionId, '0123456789abcdef') === 32;
    }

    /**
     * Set session cookie in response.
     *
     * @param string $sameSite    SameSite attribute ('Lax' default; 'None' for cross-origin embedding)
     * @param bool   $partitioned partition per top-level site (CHIPS). OpenSwoole's cookie() cannot
     *                            emit Partitioned, so this path writes a raw Set-Cookie header.
     */
    public function setSessionCookie(
        Response $response,
        string $sessionId,
        bool $secure = false,
        string $sameSite = 'Lax',
        bool $partitioned = false,
    ): void {
        // __Host- prefix: browsers enforce Secure + Path=/ + no Domain, preventing
        // cookie injection from subdomains. Only used when secureCookie is enabled.
        $cookieName = $secure ? self::SESSION_COOKIE_NAME_SECURE : self::SESSION_COOKIE_NAME;
        $maxAge = 30 * 24 * 60 * 60;

        if ($partitioned) {
            // OpenSwoole's cookie() can't emit Partitioned (CHIPS); write the header by hand.
            $response->header('Set-Cookie', self::buildCookieHeader($cookieName, $sessionId, $maxAge, $secure, $sameSite, true));

            return;
        }

        // Set cookie with 30 day expiration
        $result = $response->cookie(
            $cookieName,
            $sessionId,
            time() + $maxAge,
            '/',
            '',
            $secure,    // Secure: set via Config::withSecureCookie(true) for HTTPS deployments
            true,       // HttpOnly
            $sameSite,  // SameSite: 'Lax' blocks cross-site POSTs carrying the session cookie
        );
        $this->logger->log('debug', 'Set session cookie: ' . ($result ? 'success' : 'failed'));
    }

    /**
     * Build a Set-Cookie header value. Pure (no Response) so it is directly unit-testable.
     * __Host- names require Path=/, Secure, and no Domain: all satisfied here.
     */
    public static function buildCookieHeader(
        string $name,
        string $value,
        int $maxAge,
        bool $secure,
        string $sameSite,
        bool $partitioned,
    ): string {
        $parts = [$name . '=' . $value, 'Path=/', 'Max-Age=' . $maxAge, 'HttpOnly', 'SameSite=' . $sameSite];
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($partitioned) {
            $parts[] = 'Partitioned';
        }

        return implode('; ', $parts);
    }
}
