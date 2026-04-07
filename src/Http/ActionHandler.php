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

        // CSRF: validate the Origin header when trustedOrigins is configured.
        // Datastar fires actions via fetch(), which always sends Origin on cross-origin
        // requests. We reject any request whose Origin is not in the allowlist.
        if (!$this->isOriginAllowed($request->header['origin'] ?? null)) {
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

        try {
            // Inject HTTP request params so action callbacks can use $c->input() / $c->file()
            $context->setRequestInput($request->get ?? [], $request->post ?? [], $request->files ?? []);

            // Inject signals into context
            $context->injectSignals($signals);

            // Execute the context-level action
            $context->executeAction($actionId);

            $durationUs = (hrtime(true) - $actionStart) / 1000;
            $this->requestLogger?->logAction($actionId, $contextId, $durationUs, true);

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

    private function isOriginAllowed(?string $origin): bool {
        $trustedOrigins = $this->via->getConfig()->getTrustedOrigins();

        // No allowlist configured — allow all origins (explicit opt-in required for restriction).
        if ($trustedOrigins === null) {
            return true;
        }

        // Absent Origin header: allow (non-browser clients, same-origin curl, etc.).
        if ($origin === null) {
            return true;
        }

        return \in_array($origin, $trustedOrigins, strict: true);
    }
}
