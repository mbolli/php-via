<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Support\Logger;
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

    private RateLimiter $rateLimiter;

    /** True once the rate-limit store overflow has been logged */
    private bool $overflowReported = false;

    /** True once a denied action POST without Origin has been logged */
    private bool $missingOriginReported = false;

    public function __construct(Via $via) {
        $this->via = $via;

        // Allocated here (Via's constructor, so the master process, before $server->start()
        // forks the workers) because an OpenSwoole\Table is only shared with processes that
        // inherit it. Single-worker deployments keep plain per-process counters.
        $this->rateLimiter = new RateLimiter(shared: $via->getSettings()->workerNum > 1);
    }

    public function setRequestLogger(RequestLogger $logger): void {
        $this->requestLogger = $logger;
    }

    /**
     * Handle action triggers from the client.
     */
    public function handleAction(Request $request, Response $response, string $actionId): void {
        $actionStart = hrtime(true);

        // CSRF: Datastar posts with fetch(), so browsers always send Origin (see OriginPolicy).
        $origin = $request->header['origin'] ?? null;
        if (!OriginPolicy::allows($this->via->getSettings(), $origin, $request->header['host'] ?? null)) {
            if ($origin === null) {
                $this->reportMissingOrigin($actionId);
            }
            $response->status(403);
            $response->end($origin === null ? 'Forbidden: missing Origin' : 'Forbidden: untrusted origin');

            return;
        }

        // Rate limiting: reject if IP exceeds configured action rate limit.
        $ip = $request->server['remote_addr'] ?? 'unknown';
        if (!$this->checkRateLimit($ip)) {
            $response->status(429);
            $response->header('Retry-After', (string) $this->via->getSettings()->actionRateWindow);
            $response->end('Too Many Requests');

            return;
        }

        // Read signals from request
        $signals = SignalParser::read($request);

        // For multipart/form-data (Datastar contentType:'form'), signals are not included in the
        // request: only raw FormData fields are sent. Fall back to $request->post for via_ctx.
        $contextId = $signals['via_ctx'] ?? $request->post['via_ctx'] ?? null;

        if (!$contextId) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        // Rebuild the context if this worker has never seen it. SseHandler has always done
        // this; without it here, a context lived only on the worker that served its page and
        // action success tracked 1/worker_num: every other worker answered 400. Also covers
        // the single-worker case SseHandler already handled: a backgrounded tab whose context
        // was cleaned up, then fires an action before its SSE stream reconnects.
        if (!isset($this->via->contexts[$contextId]) && $this->via->reviveContext($contextId, $request) === null) {
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

        // A context this worker holds without a stream (a copy rebuilt for an action, or a tab whose
        // stream is down) is freed unless a stream attaches or another action arrives in time.
        $this->via->armActionDeadline($contextId);

        // Open a Dev Bar trace for this action. render.regions spans from any
        // $c->sync()/broadcast() and user $c->span() calls nest under it.
        $tracer = $this->via->getTracer();
        $traceStarted = $tracer !== null && $tracer->startTrace('POST ' . $actionId, 'request');
        if ($traceStarted) {
            $tracer->setAttribute('action.id', $actionId);
            $tracer->setAttribute('context.id', $contextId);
            $tracer->setAttribute('context.route', $context->getRoute());
        }

        try {
            // Inject HTTP request params so action callbacks can use $c->input() / $c->file() / $c->cookie()
            $context->setRequestInput($request->get ?? [], $request->post ?? [], $request->files ?? []);
            $context->setRequestCookies($request->cookie ?? []);

            // Inject signals into context
            $context->injectSignals($signals);

            // Execute the context-level action
            $context->getPatchManager()->beginAction();
            $context->executeAction($actionId);
            $this->syncSignalsAfterAction($context, $actionId);

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
            $this->via->writeSessionCookie($request, $response, rotate: $context->takeSessionRotation());

            $response->status(200);
            $response->end();
        } catch (\Throwable $e) {
            $this->via->log('error', "Action {$actionId} failed: " . Logger::describe($e));
            $tracer?->markError(\get_class($e) . ': ' . $e->getMessage());
            // Before the send below, so that what the onError callbacks write reaches the tab with it.
            $this->via->reportError($e, $context, ErrorPhase::Action, $actionId);
            // The values the action wrote before it threw are already the server's.
            $this->syncSignalsAfterAction($context, $actionId);

            $durationUs = (hrtime(true) - $actionStart) / 1000;
            $this->requestLogger?->logAction($actionId, $contextId, $durationUs, false);
            $this->via->writeSessionCookie($request, $response, rotate: $context->takeSessionRotation());

            $response->status(500);
            $response->end('Action failed');
        } finally {
            if ($traceStarted) {
                $tracer->endTrace();
            }
        }
    }

    private function syncSignalsAfterAction(Context $context, string $actionId): void {
        try {
            $context->getPatchManager()->syncSignalsAfterAction();
        } catch (\Throwable $e) {
            $this->via->log('error', "Sending the signals changed by action {$actionId} failed: " . Logger::describe($e));
            $this->via->reportError($e, $context, ErrorPhase::Action, $actionId);
        }
    }

    /**
     * Check if the IP is within the configured rate limit.
     *
     * Delegates to RateLimiter, whose counters are shared across workers. They used to be a
     * plain property on this class, which, since the handler is built before the workers are
     * forked, gave each worker its own budget and made the effective limit `limit * worker_num`.
     * See tests/Feature/ActionRateLimitTest.php.
     */
    private function checkRateLimit(string $ip): bool {
        $settings = $this->via->getSettings();

        $allowed = $this->rateLimiter->allow($ip, $settings->actionRateLimit, $settings->actionRateWindow);

        if (!$allowed || !$this->rateLimiter->hasOverflowed() || $this->overflowReported) {
            return $allowed;
        }

        // Fail-open is deliberate (an exhausted table means an unusual number of distinct
        // client IPs, and denying everyone would be the bigger outage) but must not be silent.
        $this->overflowReported = true;
        $this->via->log(
            'warn',
            'Rate-limit store is full: limits are no longer enforced for new client IPs. '
            . 'This means an unusually large number of distinct IPs are sending actions.'
        );

        return $allowed;
    }

    private function reportMissingOrigin(string $actionId): void {
        if ($this->missingOriginReported) {
            return;
        }

        $this->missingOriginReported = true;
        $this->via->log(
            'warn',
            "Action {$actionId} denied with 403: the request has no Origin header. Browsers always send one; "
            . 'to accept non-browser clients (curl, uptime checks), enable Config::withAllowMissingOrigin().'
        );
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
}
