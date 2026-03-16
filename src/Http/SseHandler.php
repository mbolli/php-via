<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use starfederation\datastar\enums\ElementPatchMode;

/**
 * Handles Server-Sent Events (SSE) connections for real-time updates.
 */
class SseHandler {
    private Via $via;
    private ?RequestLogger $requestLogger = null;

    public function __construct(Via $via) {
        $this->via = $via;
    }

    public function setRequestLogger(RequestLogger $logger): void {
        $this->requestLogger = $logger;
    }

    /**
     * Handle SSE connection for real-time updates.
     */
    public function handleSSE(Request $request, Response $response): void {
        // Get context ID from signals
        $signals = Via::readSignals($request);
        $contextId = $signals['via_ctx'] ?? null;

        if (!$contextId) {
            $response->status(400);
            $response->end('Invalid context');

            return;
        }

        $sse = new SwooleSSEGenerator();
        // Set SSE headers using Datastar SDK
        foreach (SwooleSSEGenerator::headers() as $name => $value) {
            $response->header($name, $value);
        }

        // If context doesn't exist, it was cleaned up
        if (!isset($this->via->contexts[$contextId])) {
            $this->via->log('info', "Context expired, sending reload: {$contextId}");
            // Use Datastar's executeScript to reload
            $output = $sse->executeScript('window.location.reload()');
            $response->write($output);
            $response->end();

            return;
        }

        $context = $this->via->contexts[$contextId];

        // If the context exists but its view was cleared (cleanup ran and removed it from Via::$contexts
        // but the callback hadn't fired yet), force a reload so the page re-initialises cleanly.
        if (!$context->hasView()) {
            $this->via->log('info', "Context has no view (post-cleanup race), sending reload: {$contextId}");
            unset($this->via->contexts[$contextId]);
            $output = $sse->executeScript('window.location.reload()');
            $response->write($output);
            $response->end();

            return;
        }

        // Track client info when SSE connects (not at page load)
        if (!isset($this->via->clients[$contextId])) {
            $clientId = $this->via->generateClientId();
            $clientInfo = [
                'id' => $clientId,
                'identicon' => $this->via->generateIdenticon($clientId),
                'connected_at' => time(),
                'ip' => $request->server['remote_addr'] ?? 'unknown',
            ];
            $this->via->clients[$contextId] = $clientInfo;
            $this->via->getApp()->registerClient($contextId, $clientInfo);
        }

        $this->requestLogger?->logSseConnect($contextId);

        // Cancel any pending cleanup timer for this context (reconnection)
        $this->via->getApp()->cancelContextCleanup($contextId);
        if (isset($this->via->cleanupTimers[$contextId])) {
            unset($this->via->cleanupTimers[$contextId]);
            $this->via->log('debug', "Cancelled cleanup timer for reconnected context: {$contextId}");
        }

        // Re-register context in all its scopes (in case cleanup partially ran)
        // This ensures the context receives broadcasts after reconnection
        foreach ($context->getScopes() as $scope) {
            $this->via->registerContextInScope($context, $scope);
        }

        // Recreate the patch channel for the new coroutine (SSE reconnection)
        // OpenSwoole Channels are coroutine-specific and can't be shared across request coroutines
        $context->getPatchManager()->recreatePatchChannel();

        // Send initial sync (view + signals) on connection/reconnection
        // Do this AFTER starting the loop to ensure patches are consumed
        $context->sync();

        // Notify app-level onClientConnect callbacks
        $this->via->triggerClientConnect($context);

        // Keep connection alive and listen for patches
        $lastKeepalive = time();

        while (true) {
            // Exit immediately if server is shutting down
            if ($this->via->isShuttingDown()) {
                $this->via->log('debug', 'Server shutting down, closing SSE connection', $context);

                break;
            }

            if (!$response->isWritable()) {
                break;
            }

            // Check for patches from the context
            $patch = $context->getPatch();
            if ($patch) {
                try {
                    $output = $this->sendSSEPatch($sse, $patch);

                    if (!$response->write($output)) {
                        break;
                    }
                } catch (\Throwable $e) {
                    $this->via->log('debug', 'Patch write exception, client disconnected: ' . $e->getMessage(), $context);

                    break;
                }
            } else {
                // No patch available — yield the coroutine for the poll interval.
                // usleep() is coroutine-safe because SWOOLE_HOOK_ALL is set on the server;
                // the hook intercepts it and yields the current coroutine non-blocking.
                usleep($this->via->getConfig()->getSsePollIntervalMs() * 1000);
            }

            // Send keepalive comment every 30 seconds to prevent timeout
            if (time() - $lastKeepalive >= 30) {
                try {
                    if (!$response->write(": keepalive\n\n")) {
                        break;
                    }
                    $lastKeepalive = time();
                } catch (\Throwable $e) {
                    break;
                }
            }
        }

        $this->requestLogger?->logSseDisconnect($contextId);

        // Unregister from all scopes immediately to stop receiving broadcasts
        // This prevents wasting resources syncing a context with no active SSE connection
        foreach ($context->getScopes() as $scope) {
            $this->via->getScopeRegistry()->unregisterContext($context, $scope);
        }

        // Unregister client immediately so getClients() reflects the departure
        // when onClientDisconnect callbacks fire (before the delayed context cleanup)
        unset($this->via->clients[$contextId]);
        $this->via->getApp()->unregisterClient($contextId);

        // Notify app-level onClientDisconnect callbacks
        $this->via->triggerClientDisconnect($context);

        // Schedule delayed cleanup
        $this->via->scheduleContextCleanup($contextId);
    }

    /**
     * Send SSE patch to client using Datastar SDK.
     *
     * @param array{type: string, content: mixed, selector?: string, mode?: ElementPatchMode} $patch
     */
    private function sendSSEPatch(SwooleSSEGenerator $sse, array $patch): string {
        $type = $patch['type'];
        $content = $patch['content'];
        $selector = $patch['selector'] ?? null;
        $mode = $patch['mode'] ?? null;

        return match ($type) {
            'elements' => $sse->patchElements($content, array_filter([
                'selector' => $selector,
                'mode' => $mode,
            ])),
            'signals' => $sse->patchSignals($content),
            'script' => $sse->executeScript($content),
            default => ''
        };
    }
}
