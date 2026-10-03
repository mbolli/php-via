<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Context\PatchManager;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Rendering\Bootstrap;
use Mbolli\PhpVia\State\SessionTokens;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Support\RequestLogger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Timer;
use starfederation\datastar\enums\ElementPatchMode;

/**
 * Handles Server-Sent Events (SSE) connections for real-time updates.
 *
 * An idle stream does not poll: it wakes on a patch, a closed channel or connection, a reset
 * HTTP/2 stream or the keep-alive interval. Per-worker timers do the rest, see Via::start().
 */
class SseHandler {
    /** How often a server that speaks HTTP/2 looks for streams the client reset, in milliseconds. */
    public const int RESET_CHECK_MS = 250;

    /** Directory records a heartbeat refreshes before it yields to the event loop. */
    private const int HEARTBEAT_BATCH = 1024;

    private const string KEEP_ALIVE = ": keep-alive\n\n";

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

    /**
     * Context IDs whose onClientConnect callbacks have fired and still owe a disconnect.
     *
     * @var array<string, true>
     */
    private array $connectedContextIds = [];

    /** @var array<int, SseStream> Running page streams by registration number */
    private array $streams = [];

    /** @var array<int, array<int, true>> Registration numbers by connection; one HTTP/2 connection carries several streams */
    private array $streamsByFd = [];

    private int $nextStreamId = 0;

    private bool $heartbeating = false;

    public function __construct(Via $via) {
        $this->via = $via;
    }

    public function setRequestLogger(RequestLogger $logger): void {
        $this->requestLogger = $logger;
    }

    /**
     * Handle SSE connection for real-time updates.
     *
     * @param null|callable        $brotliWrite  Brotli flush writer set by BrotliMiddleware (fn(string): string|false)
     * @param null|callable        $brotliFinish Brotli finish finalizer set by BrotliMiddleware (fn(): string|false)
     * @param array<string, mixed> $attributes   PSR-7 request attributes from the SSE-aware middleware, for a context this connect revives
     */
    public function handleSSE(Request $request, Response $response, ?callable $brotliWrite = null, ?callable $brotliFinish = null, array $attributes = []): void {
        // Get context ID from signals
        $signals = SignalParser::read($request);
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
        // For a rotation SSE-aware middleware asked for.
        $this->via->writeSessionCookie($request, $response);

        // If context doesn't exist, it was cleaned up. First try to rebuild it (same ID) so the
        // tab keeps its view without a reload; on success we fall through to normal SSE handling.
        // Otherwise send a reload on the FIRST reconnect from this dead context so active tabs
        // recover. Subsequent reconnects (backgrounded/throttled tabs that can't execute
        // the JS) are closed silently: no log noise, no retransmitting a useless event.
        // NOTE: brotli headers are intentionally NOT set on the reload path: we write raw SSE and
        // close immediately, so compression is pointless and would corrupt the payload.
        if (!isset($this->via->contexts[$contextId])) {
            if ($this->via->reviveContext($contextId, $request, byConnect: true, attributes: $attributes) !== null) {
                // Rebuilt: clear any stale reload marker and continue with the revived context.
                unset($this->reloadedContextIds[$contextId]);
            } else {
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
        }

        if ($brotliWrite !== null) {
            $response->header('Content-Encoding', 'br');
            $response->header('Vary', 'Accept-Encoding');
        }

        $context = $this->via->contexts[$contextId];

        // Verify the caller's session owns this context to prevent unauthorized SSE attachment.
        $session = $this->via->getRequestSession($request);
        if (!$this->isSessionAuthorized($contextId, $session->key)) {
            $response->status(403);
            $response->end('Forbidden');

            return;
        }
        $owner = $this->via->getContextSessionId($contextId);
        $cookie = $owner !== null ? SessionTokens::key($session->token) : null;

        // If the context exists but its view was cleared (cleanup ran and removed it from Via::$contexts
        // but the callback hadn't fired yet), force a reload so the page re-initialises cleanly.
        if (!$context->hasView()) {
            $this->via->log('info', "Context has no view (post-cleanup race), sending reload: {$contextId}");
            unset($this->via->contexts[$contextId]);
            // Brotli headers are already set above if brotli is active. Use brotliWrite to send
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

        // Counted before the registrations below: recreatePatchChannel() ends a stream of this
        // context that is still parked, and that stream must not undo them as the last one.
        $this->via->activeSseCount[$contextId] = ($this->via->activeSseCount[$contextId] ?? 0) + 1;
        ++$this->via->runningSseStreams;

        try {
            // A context an action revived without signals, and a clientSeeded signal, take the tab's values from this connect.
            $this->via->seedFromConnect($context, $signals);

            // The stream's worker holds the tab: actions that reach another worker are passed here.
            $this->via->claimStream($contextId);

            // Track client info when SSE connects (not at page load)
            if (!isset($this->via->clients[$contextId])) {
                $clientId = $this->via->generateClientId();
                $xff = $request->header['x-forwarded-for'] ?? null;
                $ip = $xff !== null
                    ? trim(explode(',', $xff)[0])
                    : ($request->header['x-real-ip'] ?? $request->server['remote_addr'] ?? 'unknown');
                $clientInfo = [
                    'id' => $clientId,
                    'identicon' => $this->via->generateIdenticon($clientId),
                    'connected_at' => time(),
                    'ip' => $ip,
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

            $this->stream(new SseStream($context, $contextId, $response, $cookie, $owner), $sse, $brotliWrite, $brotliFinish);
        } finally {
            // Runs even if the loop throws, or the count never drops to zero.
            $this->releaseStream($context, $contextId);
            --$this->via->runningSseStreams;
        }
    }

    /**
     * Whether a patch should be dropped because the connection cannot keep up.
     *
     * `send_yield` already applies backpressure, but as an unbounded park: measured
     * against a client that never reads, writes 0-100 returned in ~0.05ms each
     * (~6.4MB buffered) and write 101 then parked for 20s, returning false only when
     * the client disconnected. `isWritable()` stayed true the whole time, so it is no
     * use as a backpressure signal. A coroutine parked in write() also stops observing
     * shutdown and disconnect, which undoes the loop's liveness guarantees.
     *
     * Only `elements` patches may be dropped. They are idempotent full-fragment morphs
     * where the latest supersedes the rest, so a backed-up client simply catches up on
     * the next broadcast. `signals` are deltas (self-healing only because delivery is
     * acknowledged), and `script` patches are one-shot side effects with no resend
     * path, so neither is ever sacrificed here. Nor are the element patches of
     * patchElements() (PatchManager::isOneShot()), which the stream loop leaves out before asking.
     *
     * @param string $type           patch type
     * @param int    $queuedBytes    `send_queued_bytes` for the connection
     * @param int    $maxQueuedBytes threshold; 0 or less disables dropping
     */
    public static function shouldDropFrame(string $type, int $queuedBytes, int $maxQueuedBytes): bool {
        if ($maxQueuedBytes <= 0 || $type !== 'elements') {
            return false;
        }

        return $queuedBytes > $maxQueuedBytes;
    }

    /**
     * End the streams of a closed connection now: isWritable() stays true after the peer closes.
     *
     * @internal called from the server's close event
     */
    public function onConnectionClose(int $fd): void {
        $contexts = [];
        foreach ($this->streamsByFd[$fd] ?? [] as $key => $_) {
            $stream = $this->streams[$key];
            $stream->clientGone = true;
            $contexts[spl_object_id($stream->context)] = $stream->context;
        }

        foreach ($contexts as $context) {
            $context->getPatchManager()->wakeConsumers();
        }
    }

    /**
     * Close the patch channel of every running stream, including those whose context left Via::$contexts.
     *
     * @internal called by the worker shutdown
     */
    public function closeStreams(): void {
        foreach ($this->streams as $stream) {
            $stream->context->getPatchManager()->closePatchChannel();
        }
    }

    /**
     * Rewrite the cross-worker directory record of every context this worker streams to, busy or idle.
     *
     * @internal run by a per-worker timer, see Via::sseHeartbeatIntervalMs()
     */
    public function heartbeatStreams(): void {
        if ($this->heartbeating) {
            return;
        }
        $this->heartbeating = true;

        try {
            $touched = [];
            foreach ($this->streams as $key => $stream) {
                // Re-checked because the sweep yields: an ended stream or a destroyed context is left to expire.
                if (!$this->isRunning($key) || isset($touched[$stream->contextId])
                    || ($this->via->contexts[$stream->contextId] ?? null) !== $stream->context) {
                    continue;
                }

                $touched[$stream->contextId] = true;
                $this->via->getApp()->refreshContextRecord($stream->context);

                if (\count($touched) % self::HEARTBEAT_BATCH === 0 && Coroutine::getCid() > 0) {
                    Coroutine::usleep(1000);
                }
            }
        } finally {
            $this->heartbeating = false;
        }
    }

    /**
     * End the streams whose client reset them, which closes no connection and so fires no close event.
     *
     * @internal run every RESET_CHECK_MS by a per-worker timer when the server speaks HTTP/2
     */
    public function endResetStreams(): void {
        foreach ($this->streams as $key => $stream) {
            if ($stream->clientGone || !$this->isRunning($key) || $stream->response->isWritable()) {
                continue;
            }

            $stream->clientGone = true;
            $stream->context->getPatchManager()->wakeConsumers();
        }
    }

    /**
     * Run the SSE loop for an authorised context until the client, the context or the server goes away.
     */
    private function stream(SseStream $stream, SwooleSSEGenerator $sse, ?callable $brotliWrite, ?callable $brotliFinish): void {
        $key = $this->openStream($stream);

        try {
            $this->runStream($stream, $stream->response, $sse, $brotliWrite, $brotliFinish);
        } finally {
            $this->closeStream($key);
        }
    }

    private function openStream(SseStream $stream): int {
        $key = ++$this->nextStreamId;
        // A connection that closed before this point had no stream to tell.
        $stream->clientGone = $this->via->getServer()?->exists($stream->fd) === false;

        $this->streams[$key] = $stream;
        $this->streamsByFd[$stream->fd][$key] = true;

        return $key;
    }

    private function isRunning(int $key): bool {
        return isset($this->streams[$key]);
    }

    private function closeStream(int $key): void {
        $fd = $this->streams[$key]->fd;
        unset($this->streams[$key], $this->streamsByFd[$fd][$key]);

        if ($this->streamsByFd[$fd] === []) {
            unset($this->streamsByFd[$fd]);
        }
    }

    /**
     * Initial sync, then the patch loop until the client, the context or the server goes away.
     */
    private function runStream(SseStream $stream, Response $response, SwooleSSEGenerator $sse, ?callable $brotliWrite, ?callable $brotliFinish): void {
        $context = $stream->context;
        $contextId = $stream->contextId;

        // Send initial sync (view + signals) on connection/reconnection
        // Do this AFTER starting the loop to ensure patches are consumed
        $synced = true;

        try {
            $context->sync();
            // Confirm the connection even when the sync had nothing to send: it clears the
            // shell's reconnect banner ($_disconnected) and flushes the headers now, not at
            // the first keep-alive.
            $context->getPatchManager()->queuePatch(['type' => 'signals', 'content' => ['_disconnected' => false]]);
        } catch (\Throwable $e) {
            // Skip the loop; releaseStream() still owns the counters and cleanup.
            $synced = false;
            $this->via->log('error', 'Initial SSE sync failed: ' . Logger::describe($e), $context);

            // Nothing has been written yet, so the status still takes effect.
            try {
                $response->status(500);
                $response->end();
            } catch (\Throwable) {
                // Client already gone.
            }
            $this->via->reportError($e, $context, ErrorPhase::Render);
        }

        // A tab whose sync fails never counts as connected, so a view that always throws
        // does not fire a connect/disconnect pair on every retry. A stream that replaces one
        // still running keeps the tab connected, and the last one to end disconnects it.
        if ($synced && !isset($this->connectedContextIds[$contextId])) {
            $this->connectedContextIds[$contextId] = true;
            $this->via->triggerClientConnect($context);
        }

        // Slow-consumer bookkeeping: $backedUp tracks the stall episode so the log
        // records transitions rather than every dropped frame.
        $backedUp = false;
        $droppedFrames = 0;

        // A tenth of slack: the park's millisecond timer can end just short of the full interval.
        $keepAliveNs = $this->via->getSettings()->sseKeepAliveMs * 900_000;
        $lastWriteNs = hrtime(true);

        // Keep connection alive and listen for patches
        while ($synced) {
            // Exit immediately if server is shutting down
            if ($this->via->isShuttingDown()) {
                $this->via->log('debug', 'Server shutting down, closing SSE connection', $context);

                break;
            }

            // isWritable() stays true after the peer closes; clientGone is what reports that.
            if ($stream->clientGone || !$response->isWritable()) {
                break;
            }

            // Check for patches from the context
            $patch = $context->getPatch();
            if ($patch) {
                // The client can leave while the loop is parked; the patch then waits for its next stream.
                if ($this->connectionGone($stream, $response)) {
                    $context->getPatchManager()->returnPatch($patch);

                    break;
                }

                if ($this->cookieRetired($stream)) {
                    $context->getPatchManager()->returnPatch($patch);
                    $this->askToReconnect($stream, $sse, $brotliWrite, 'The session cookie of this stream was retired');

                    break;
                }

                // Drop this frame rather than parking in write() behind a client that
                // is not draining its socket. See shouldDropFrame().
                if (!PatchManager::isOneShot($patch) && $this->isBackedUp($response, $patch['type'])) {
                    ++$droppedFrames;

                    // Log the transition only. A stalled client can drop thousands of
                    // frames, and one line per frame would bury everything else.
                    if (!$backedUp) {
                        $backedUp = true;
                        $this->via->log('debug', "Client backlog exceeded, dropping element frames: {$contextId}", $context);
                    }

                    continue;
                }

                $backedUp = false;

                try {
                    if (!$this->writeOutput($response, $this->sendSSEPatch($sse, $patch), $brotliWrite)) {
                        break;
                    }
                    $lastWriteNs = hrtime(true);

                    // Delivery acknowledgement. Signal patches carry deltas and are only
                    // marked synced here, once the bytes are actually on the wire: a patch
                    // that dies in the queue therefore leaves its signals dirty and is
                    // resent by the next sync instead of silently stranding the client.
                    // Note write() returning true means "buffered", not "received".
                    if (isset($patch['confirm'])) {
                        ($patch['confirm'])();
                    }
                } catch (\Throwable $e) {
                    $this->via->log('debug', 'Patch write exception, client disconnected: ' . $e->getMessage(), $context);

                    break;
                }
            } elseif ($context->getPatchManager()->wasChannelClosed()) {
                // The channel was closed by cleanup, or replaced by recreatePatchChannel()
                // on a reconnect. Stop consuming: a closed channel returns immediately
                // regardless of timeout, so continuing would spin at 100% CPU (the
                // regression fcab883 was written to fix), and re-reading the channel
                // property would let this superseded coroutine steal patches from the
                // live one. The replacement coroutine already owns the new channel, and
                // recreatePatchChannel() carried any pending patches across to it.
                $this->via->log('debug', 'Patch channel closed, ending SSE loop', $context);

                break;
            } else {
                // No patch: the keep-alive interval passed, or a closed connection or reset stream woke
                // the park. Nothing else ends it, so an idle stream costs one wake per interval.

                if ($this->connectionGone($stream, $response)) {
                    break;
                }

                // Safety valve: if context was destroyed externally (e.g. cleanup race),
                // send a reload so the client reinitialises instead of hanging silently.
                if (!isset($this->via->contexts[$contextId])) {
                    $this->via->log('info', "Context destroyed while SSE active, sending reload: {$contextId}");

                    // isWritable() guard: defensive check. Context destruction and
                    // response close can race in separate coroutines on reconnect.
                    // @phpstan-ignore if.alwaysTrue
                    if ($response->isWritable()) {
                        try {
                            $this->writeOutput($response, $sse->executeScript('window.location.reload()'), $brotliWrite);
                        } catch (\Throwable) {
                        }
                    }

                    break;
                }

                if ($this->cookieRetired($stream)) {
                    $this->askToReconnect($stream, $sse, $brotliWrite, 'The session cookie of this stream was retired');

                    break;
                }

                // Skipped behind a backlog, so the comment never parks the loop in write().
                if ($keepAliveNs > 0 && hrtime(true) - $lastWriteNs >= $keepAliveNs && !$this->isBackedUp($response, 'elements')) {
                    try {
                        if (!$this->writeOutput($response, self::KEEP_ALIVE, $brotliWrite)) {
                            break;
                        }
                    } catch (\Throwable) {
                        break;
                    }
                    $lastWriteNs = hrtime(true);
                }
            }
        }

        // A stopping worker sends its tabs to another one at once, not after the client's reconnect interval.
        if ($synced && $this->via->isShuttingDown() && !$stream->clientGone && $response->isWritable()) {
            $this->askToReconnect($stream, $sse, $brotliWrite, 'This worker stops');
        }

        // Flush the final brotli block so the decompressor sees a complete stream
        if ($brotliFinish !== null && $response->isWritable()) {
            $last = $brotliFinish();
            if ($last !== false && $last !== '') {
                try {
                    $response->write($last);
                } catch (\Throwable) {
                    // Client already gone, ignore
                }
            }
        }

        if ($droppedFrames > 0) {
            $this->via->log('debug', "Dropped {$droppedFrames} element frames for slow client: {$contextId}", $context);
        }
    }

    /**
     * Drop this stream from the active count and, when it was the last one, release the context.
     */
    private function releaseStream(Context $context, string $contextId): void {
        $this->requestLogger?->logSseDisconnect($contextId);

        // Decrement active SSE counter. Only perform cleanup when this is the last
        // active SSE coroutine for this context. If a newer SSE coroutine is already
        // running (reconnect race), skip cleanup: the new coroutine owns the context.
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

            if (isset($this->connectedContextIds[$contextId])) {
                unset($this->connectedContextIds[$contextId]);
                $this->via->triggerClientDisconnect($context);
            }

            // Schedule delayed cleanup
            $this->via->scheduleContextCleanup($contextId);
        } else {
            $this->via->log('debug', "Old SSE coroutine exited; {$this->via->activeSseCount[$contextId]} still active, skipping cleanup: {$contextId}", $context);
        }
    }

    /**
     * Whether the session cookie the stream connected with no longer names its session: a rotation retired it.
     * Whoever connected with a cookie planted or read before a login then gets nothing more of the session.
     */
    private function cookieRetired(SseStream $stream): bool {
        if ($stream->cookie === null || $stream->session === null) {
            return false;
        }

        return !$this->via->getSessionManager()->tokens()->stillNames($stream->cookie, $stream->session, $stream->cookieCheck);
    }

    /**
     * Ask the tab of a stream that ends to reconnect at once: after a rotation retired its cookie, a browser that
     * took the new cookie keeps its context and one that holds only the old cookie is refused; after this worker
     * stopped, another one takes the tab.
     *
     * @param null|callable(string): (false|string) $brotliWrite
     */
    private function askToReconnect(SseStream $stream, SwooleSSEGenerator $sse, ?callable $brotliWrite, string $why): void {
        $this->via->log('debug', $why . ', asking the tab to reconnect', $stream->context);

        try {
            $this->writeOutput($stream->response, $sse->patchSignals([Bootstrap::RECONNECT_SIGNAL => bin2hex(random_bytes(6))]), $brotliWrite);
        } catch (\Throwable) {
            // Client already gone.
        }
    }

    /**
     * Whether the client has left: its connection closed, or it reset this HTTP/2 stream.
     * exists() is the backstop for a close this worker is not told about (dispatch_mode 1, 3 or 7).
     */
    private function connectionGone(SseStream $stream, Response $response): bool {
        return $stream->clientGone || !$response->isWritable() || $this->via->getServer()?->exists($response->fd) === false;
    }

    /**
     * Write SSE output through the stream's Brotli encoder when it has one, so every frame of a
     * compressed stream, keep-alive comments included, goes through the same encoder.
     *
     * @param null|callable(string): (false|string) $brotliWrite
     */
    private function writeOutput(Response $response, string $output, ?callable $brotliWrite): bool {
        if ($brotliWrite !== null) {
            $compressed = $brotliWrite($output);
            // On a compression failure the chunk goes out raw.
            if ($compressed !== false) {
                $output = $compressed;
            }
        }

        return $response->write($output);
    }

    /**
     * Check the connection's unsent backlog before writing.
     *
     * getClientInfo() costs ~0.32us, negligible against a patch write.
     */
    private function isBackedUp(Response $response, string $patchType): bool {
        $maxQueued = $this->via->getSettings()->sseMaxQueuedBytes;

        if ($maxQueued <= 0 || $patchType !== 'elements') {
            return false;
        }

        $server = $this->via->getServer();
        if ($server === null) {
            return false;
        }

        $info = $server->getClientInfo($response->fd);
        if (!\is_array($info)) {
            return false;
        }

        return self::shouldDropFrame($patchType, (int) ($info['send_queued_bytes'] ?? 0), $maxQueued);
    }

    /**
     * Send SSE patch to client using Datastar SDK.
     *
     * @param array{type: string, content: mixed, selector?: string, mode?: ElementPatchMode|PatchMode, confirm?: callable(): void} $patch
     */
    private function sendSSEPatch(SwooleSSEGenerator $sse, array $patch): string {
        $type = $patch['type'];
        $content = $patch['content'];
        $selector = $patch['selector'] ?? null;
        $mode = $patch['mode'] ?? null;
        if ($mode instanceof PatchMode) {
            $mode = ElementPatchMode::from($mode->value);
        }

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

    /**
     * Check whether the caller's session is authorised to open an SSE stream for the context.
     *
     * @see ActionHandler::isSessionAuthorized() (identical contract)
     */
    private function isSessionAuthorized(string $contextId, ?string $callerSessionId): bool {
        $storedSessionId = $this->via->getContextSessionId($contextId);
        if ($storedSessionId === null) {
            return true;
        }

        return $callerSessionId !== null && $callerSessionId === $storedSessionId;
    }
}
