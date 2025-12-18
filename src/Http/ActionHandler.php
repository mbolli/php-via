<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Via;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * Handles action triggers from the client.
 */
class ActionHandler {
    private Via $via;

    public function __construct(Via $via) {
        $this->via = $via;
    }

    /**
     * Handle action triggers from the client.
     */
    public function handleAction(Request $request, Response $response, string $actionId): void {
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

            $this->via->log('debug', "Executing action {$actionId} for context {$contextId}");

            // Execute the context-level action
            $context->executeAction($actionId);

            $this->via->log('debug', "Action {$actionId} completed successfully");

            $response->status(200);
            $response->end();
        } catch (\Exception $e) {
            $this->via->log('error', "Action {$actionId} failed: " . $e->getMessage());
            $response->status(500);
            $response->end('Action failed');
        }
    }
}
