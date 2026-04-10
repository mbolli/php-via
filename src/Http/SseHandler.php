<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Timer;
use starfederation\datastar\enums\ElementPatchMode;

/**
 * Handles Server-Sent Events (SSE) connections for real-time updates.
 */
class SseHandler {
    private Via $via;
    private ?RequestLogger $requestLogger = null;

    /**
     * Context IDs that have already been sent a reload instruction.
     * Subsequent reconnects from the same dead context (e.g. backgrounded tab
     * that can't execute JS) are closed silently instead of spamming the log
     * and re-sending a reload that will never execute.
     * Entries are evicted after 5 minutes via a timer set on first insert.
     *
     * @var array<string, true>
     */
    private array $reloadedContextIds = [];

    public function __construct(Via $via) {
        $this->via = $via;
    }

    public function setRequestLogger(RequestLogger $logger): void {
        $this->requestLogger = $logger;
    }

    /**
     * Handle SSE connection for real-time updates.
     *
     * @param null|callable $brotliWrite  Brotli flush writer set by BrotliMiddleware (fn(string): string|false)
     * @param null|callable $brotliFinish Brotli finish finalizer set by BrotliMiddleware (fn(): string|false)
     */
    public function handleSSE(Request $request, Response $response, ?callable $brotliWrite = null, ?callable $brotliFinish = null): void {
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

        // If context doesn't exist, it was cleaned up.
        // Send a reload on the FIRST reconnect from this dead context so active tabs
        // recover. Subsequent reconnects (backgrounded/throttled tabs that can't execute
        // the JS) are closed silently — no log noise, no retransmitting a useless event.
        // NOTE: brotli headers are intentionally NOT set here — we write raw SSE and close
        // immediately, so compression is pointless and would corrupt the payload.
        if (!isset($this->via->contexts[$contextId])) {
            if (isset($this->reloadedContextIds[$contextId])) {
                // Already told this context to reload; just close cleanly.
                $response->end();

                return;
            }

            $this->reloadedContextIds[$contextId] = true;
            // Evict after 5 minutes so the set doesn't grow unbounded.
            Timer::after(300_000, function () use ($contextId): void {
                unset($this->reloadedContextIds[$contextId]);
            });

            $this->via->log('info', "Context expired, sending reload: {$contextId}");
            $response->write($sse->executeScript('window.location.reload()'));
            $response->end();

            return;
        }

        if ($brotliWrite !== null) {
            $response->header('Content-Encoding', 'br');
            $response->header('Vary', 'Accept-Encoding');
        }

        $context = $this->via->contexts[$contextId];

        // If the context exists but its view was cleared (cleanup ran and removed it from Via::$contexts
        // but the callback hadn't fired yet), force a reload so the page re-initialises cleanly.
        if (!$context->hasView()) {
            $this->via->log('info', "Context has no view (post-cleanup race), sending reload: {$contextId}");
            unset($this->via->contexts[$contextId]);
            // Brotli headers are already set above if brotli is active — use brotliWrite to send
            // the event through the proper encoder, otherwise write raw.
            $output = $sse->executeScript('window.location.reload()');
            if ($brotliWrite !== null) {
                $encoded = $brotliWrite($output);
                if ($encoded !== false && $encoded !== '') {
                    $response->write($encoded);
                }
            } else {
                $response->write($output);
            }
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

        // Track that this coroutine holds an active SSE connection for this context.
        // Guards against a race where an older SSE coroutine exits *after* this one starts,
        // scheduling a cleanup timer that would destroy the still-live context.
        $this->via->activeSseCount[$contextId] = ($this->via->activeSseCount[$contextId] ?? 0) + 1;

        // Send initial sync (view + signals) on connection/reconnection
        // Do this AFTER starting the loop to ensure patches are consumed
        $context->sync();

        // Notify app-level onClientConnect callbacks
        $this->via->triggerClientConnect($context);

        // Keep connection alive and listen for patches
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

                    if ($brotliWrite !== null) {
                        $compressed = $brotliWrite($output);
                        if ($compressed === false) {
                            // Compression failed — fall back to raw output for this chunk
                            $compressed = $output;
                        }
                        if (!$response->write($compressed)) {
                            break;
                        }
                    } elseif (!$response->write($output)) {
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

                // Safety valve: if context was destroyed externally (e.g. cleanup race),
                // send a reload so the client reinitialises instead of hanging silently.
                if (!isset($this->via->contexts[$contextId])) {
                    $this->via->log('info', "Context destroyed while SSE active, sending reload: {$contextId}");

                    if ($response->isWritable()) {
                        try {
                            $response->write($sse->executeScript('window.location.reload()'));
                        } catch (\Throwable) {
                        }
                    }

                    break;
                }
            }
        }

        // Flush the final brotli block so the decompressor sees a complete stream
        if ($brotliFinish !== null && $response->isWritable()) {
            $last = $brotliFinish();
            if ($last !== false && $last !== '') {
                try {
                    $response->write($last);
                } catch (\Throwable) {
                    // Client already gone — ignore
                }
            }
        }

        $this->requestLogger?->logSseDisconnect($contextId);

        // Decrement active SSE counter. Only perform cleanup when this is the last
        // active SSE coroutine for this context. If a newer SSE coroutine is already
        // running (reconnect race), skip cleanup — the new coroutine owns the context.
        $this->via->activeSseCount[$contextId] = ($this->via->activeSseCount[$contextId] ?? 1) - 1;
        $isLastSse = $this->via->activeSseCount[$contextId] <= 0;
        if ($isLastSse) {
            unset($this->via->activeSseCount[$contextId]);
        }

        if ($isLastSse) {
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
        } else {
            $this->via->log('debug', "Old SSE coroutine exited; {$this->via->activeSseCount[$contextId]} still active, skipping cleanup: {$contextId}", $context);
        }
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
