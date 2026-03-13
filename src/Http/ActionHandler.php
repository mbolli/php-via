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
}
