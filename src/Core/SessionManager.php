<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\State\SessionTokens;
use Mbolli\PhpVia\Support\Logger;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/**
 * SessionManager - Session and cookie handling.
 *
 * The session cookie is a secret token; the session id php-via hands to apps, scopes and ownership
 * checks is a key derived from it, which regenerateSession() keeps while it replaces the cookie.
 */
class SessionManager {
    public const string SESSION_COOKIE_NAME = 'via_session_id';
    public const string SESSION_COOKIE_NAME_SECURE = '__Host-via_session_id';

    /** @var \WeakMap<Request, RequestSession> */
    private \WeakMap $sessions;

    public function __construct(
        private Logger $logger,
        private SessionTokens $tokens,
    ) {
        $this->sessions = new \WeakMap();
    }

    public function tokens(): SessionTokens {
        return $this->tokens;
    }

    /**
     * @internal for tests that need a clock or a grace period of their own
     */
    public function useTokens(SessionTokens $tokens): void {
        $this->tokens = $tokens;
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
     * The session id of a request: the key of the session its cookie belongs to, or of a new session.
     */
    public function getOrCreateSessionId(Request $request, bool $secure = false): string {
        return $this->resolve($request, $secure)->key;
    }

    /**
     * The session of a request, the same object on every call.
     *
     * Only a cookie in the form this class issues is taken, and not one a rotation retired past its grace
     * period; anything else starts a new session. With secure cookies only the __Host- cookie counts: a
     * sibling subdomain or a plain-HTTP response can set the plain one. A request without a valid cookie
     * gets the same new session on every call, so the 'via.session' attribute middleware reads is the
     * session the page then sets.
     */
    public function resolve(Request $request, bool $secure = false): RequestSession {
        if (isset($this->sessions[$request])) {
            return $this->sessions[$request];
        }

        $cookies = $request->cookie ?? [];
        $token = $cookies[$secure ? self::SESSION_COOKIE_NAME_SECURE : self::SESSION_COOKIE_NAME] ?? null;
        if (\is_string($token) && self::isValidSessionId($token)) {
            [$key, $state] = $this->tokens->lookup($token);
            if ($state !== SessionTokens::RETIRED) {
                return $this->sessions[$request] = new RequestSession($key, $token, $state);
            }
        }

        $token = bin2hex(random_bytes(16));

        return $this->sessions[$request] = new RequestSession(SessionTokens::key($token), $token, RequestSession::NEW);
    }

    /**
     * The cookie value the response to $request sets, decided once per request: a new cookie when the
     * session rotates, the cookie of a new session, and on page loads ($refresh) the request's cookie
     * again for a fresh Max-Age. A cookie a rotation retired is never set again, so a page that loads
     * with it during the grace period leaves the browser the new one.
     *
     * @param bool $rotate a new cookie for the session, as Context::regenerateSession() asks
     *
     * @throws \OverflowException when the session should rotate and the rotation table is full
     */
    public function cookieFor(Request $request, bool $secure, bool $rotate, bool $refresh): ?string {
        $session = $this->resolve($request, $secure);
        if ($session->written) {
            return null;
        }
        $rotate = $rotate || $session->rotate;
        if (!$rotate && !$refresh) {
            return null;
        }
        $session->written = true;

        if ($session->state === RequestSession::NEW) {
            return $session->token;
        }
        if ($rotate) {
            return $this->tokens->rotate($session->token);
        }

        // Looked up again: another tab's request may have rotated the cookie while this one ran.
        $state = $this->tokens->lookup($session->token)[1];

        return $state === SessionTokens::FRESH || $state === SessionTokens::CURRENT ? $session->token : null;
    }

    /**
     * Whether a cookie value has the form this class issues: 32 lowercase hex characters.
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
