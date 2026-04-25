<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/**
 * Handles action triggers from the client.
 */
class ActionHandler {
    private Via $via;
    private ?RequestLogger $requestLogger = null;

    /** @var array<string, list<float>> Per-IP request timestamps for rate limiting */
    private array $rateLimitBuckets = [];

    /** Unix timestamp of last rate-limit bucket cleanup */
    private int $lastRateLimitCleanup = 0;

    public function __construct(Via $via) {
        $this->via = $via;
    }

    public function setRequestLogger(RequestLogger $logger): void {
        $this->requestLogger = $logger;
    }

    /**
     * Handle action triggers from the client.
     */
    public function handleAction(Request $request, Response $response, string $actionId): void {
        $actionStart = hrtime(true);

        // CSRF: validate the Origin header.
        // Datastar fires actions via fetch(), which always sends Origin on cross-origin
        // requests. When no explicit allowlist is configured, the framework falls back to
        // a same-host check derived from the Host header.  In dev mode, absent Origin is
        // also accepted (curl, Postman, local tooling).
        if (!$this->isOriginAllowed($request->header['origin'] ?? null, $request->header['host'] ?? null)) {
            $response->status(403);
            $response->end('Forbidden: untrusted origin');

            return;
        }

        // Rate limiting: reject if IP exceeds configured action rate limit.
        $ip = $request->server['remote_addr'] ?? 'unknown';
        if (!$this->checkRateLimit($ip)) {
            $response->status(429);
            $response->header('Retry-After', (string) $this->via->getConfig()->getActionRateWindow());
            $response->end('Too Many Requests');

            return;
        }

        // Read signals from request
        $signals = Via::readSignals($request);

        // For multipart/form-data (Datastar contentType:'form'), signals are not included in the
        // request — only raw FormData fields are sent. Fall back to $request->post for via_ctx.
        $contextId = $signals['via_ctx'] ?? $request->post['via_ctx'] ?? null;

        if (!$contextId || !isset($this->via->contexts[$contextId])) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $context = $this->via->contexts[$contextId];

        // Verify the caller's session owns this context to prevent cross-session IDOR.
        if (!$this->isSessionAuthorized($contextId, $this->via->getSessionId($request))) {
            $response->status(403);
            $response->end('Forbidden');

            return;
        }

        try {
            // Inject HTTP request params so action callbacks can use $c->input() / $c->file() / $c->cookie()
            $context->setRequestInput($request->get ?? [], $request->post ?? [], $request->files ?? []);
            $context->setRequestCookies($request->cookie ?? []);

            // Inject signals into context
            $context->injectSignals($signals);

            // Execute the context-level action
            $context->executeAction($actionId);

            $durationUs = (hrtime(true) - $actionStart) / 1000;
            $this->requestLogger?->logAction($actionId, $contextId, $durationUs, true);

            // Apply any cookies queued by the action callback
            foreach ($context->flushPendingCookies() as $cookie) {
                $response->cookie(
                    $cookie['name'],
                    $cookie['value'],
                    $cookie['expires'],
                    $cookie['path'],
                    $cookie['domain'],
                    $cookie['secure'],
                    $cookie['httpOnly'],
                    $cookie['sameSite'],
                );
            }

            $response->status(200);
            $response->end();
        } catch (\Exception $e) {
            $this->via->log('error', "Action {$actionId} failed: " . $e->getMessage());

            $durationUs = (hrtime(true) - $actionStart) / 1000;
            $this->requestLogger?->logAction($actionId, $contextId, $durationUs, false);

            $response->status(500);
            $response->end('Action failed');
        }
    }

    /**
     * Validate the request Origin header against the configured trustedOrigins list.
     *
     * Returns true when:
     * - No trustedOrigins list is configured (dev mode / opt-in).
     * - The Origin header is absent (non-browser clients, curl, internal calls).
     * - The Origin header value exactly matches one of the trusted origins.
     *
     * Returns false (block the request) when an Origin header is present but
     * does not match any trusted origin.
     */
    /**
     * Check if the IP is within the configured rate limit.
     *
     * Uses a sliding-window counter: timestamps older than the window are pruned,
     * then the current count is compared against the limit. Returns true if allowed.
     */
    private function checkRateLimit(string $ip): bool {
        $limit = $this->via->getConfig()->getActionRateLimit();
        if ($limit <= 0) {
            return true; // Rate limiting disabled
        }

        $window = $this->via->getConfig()->getActionRateWindow();
        $now = microtime(true);
        $cutoff = $now - $window;

        // Prune expired entries for this IP
        if (isset($this->rateLimitBuckets[$ip])) {
            $this->rateLimitBuckets[$ip] = array_values(
                array_filter($this->rateLimitBuckets[$ip], fn (float $ts) => $ts > $cutoff)
            );
        } else {
            $this->rateLimitBuckets[$ip] = [];
        }

        // Check limit
        if (\count($this->rateLimitBuckets[$ip]) >= $limit) {
            return false;
        }

        // Record this request
        $this->rateLimitBuckets[$ip][] = $now;

        // Periodic cleanup: every 60 seconds, remove IPs with no recent requests
        // to prevent unbounded memory growth from many distinct IPs.
        if ((int) $now - $this->lastRateLimitCleanup > 60) {
            $this->lastRateLimitCleanup = (int) $now;
            foreach ($this->rateLimitBuckets as $bucketIp => $timestamps) {
                $this->rateLimitBuckets[$bucketIp] = array_values(
                    array_filter($timestamps, fn (float $ts) => $ts > $cutoff)
                );
                if ($this->rateLimitBuckets[$bucketIp] === []) {
                    unset($this->rateLimitBuckets[$bucketIp]);
                }
            }
        }

        return true;
    }

    /**
     * Check whether the caller's session is authorised to interact with the given context.
     *
     * Returns true when:
     *   - The context has no stored session binding (accessible to any caller).
     *   - The caller's session matches the session that originally created the context.
     *
     * Returns false (block the request) when there is a stored session and the
     * caller's session does not match it.
     */
    private function isSessionAuthorized(string $contextId, ?string $callerSessionId): bool {
        $storedSessionId = $this->via->getContextSessionId($contextId);
        if ($storedSessionId === null) {
            return true;
        }

        return $callerSessionId !== null && $callerSessionId === $storedSessionId;
    }

    /**
     * Validate the Origin header against the configured allowlist or a derived same-host check.
     *
     * Explicit allowlist (withTrustedOrigins):
     *   – Absent Origin is always allowed (non-browser clients, curl, server-to-server).
     *   – Present Origin must be in the list.
     *
     * No explicit allowlist:
     *   – Falls back to a same-host check: the Origin's host must equal the Host header.
     *     Accepts both http:// and https:// prefixes so it works behind a TLS-terminating proxy.
     *   – Absent Origin: allowed in dev mode (curl/tools), denied in production.
     *   – No Host header: allowed in dev mode, denied in production.
     */
    private function isOriginAllowed(?string $origin, ?string $host): bool {
        $config = $this->via->getConfig();
        $trustedOrigins = $config->getTrustedOrigins();
        $devMode = $config->getDevMode();

        if ($trustedOrigins !== null) {
            // Explicit allowlist configured — check strictly.
            // Absent Origin is always allowed (non-browser clients, curl, server-to-server).
            if ($origin === null) {
                return true;
            }

            return \in_array($origin, $trustedOrigins, strict: true);
        }

        // No explicit allowlist: same-host fallback.
        if ($origin === null) {
            // Dev: allow absent Origin (curl, local tooling).  Prod: deny.
            return $devMode;
        }

        if ($host === null) {
            // No Host header: allow in dev (unusual setup), deny in prod.
            return $devMode;
        }

        // Strip the scheme from the Origin header and compare to the Host header.
        // This handles both http:// and https:// transparently.
        $originHost = (string) preg_replace('#^https?://#', '', $origin);

        return $originHost === $host;
    }
}
