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

        // Read signals from request
        $signals = Via::readSignals($request);

        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId || !isset($this->via->contexts[$contextId])) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $context = $this->via->contexts[$contextId];

        try {
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
