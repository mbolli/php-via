<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

use OpenSwoole\Atomic\Long;

/**
 * Performance statistics tracker.
 *
 * requests, avg_request_time, actions and sse_connections count for the whole server once share() ran, which a
 * started server does before its workers fork. Every other figure describes the worker that reads it.
 */
class Stats {
    // Render stats
    private int $renderCount = 0;
    private float $totalRenderTime = 0.0;
    private float $minRenderTime = PHP_FLOAT_MAX;
    private float $maxRenderTime = 0.0;

    // Request stats, used until share()
    private int $requests = 0;
    private int $requestTimeUs = 0;
    private int $sseConnections = 0;
    private int $actions = 0;

    /** @var null|array{requests: Long, requestTimeUs: Long, sseConnections: Long, actions: Long} */
    private ?array $shared = null;

    // GC stats
    private int $gcRuns = 0;
    private int $gcCyclesFreed = 0;

    // Broadcast flush stats (this worker)
    private int $broadcastsScheduled = 0;
    private int $broadcastsCoalesced = 0;
    private int $broadcastFlushes = 0;
    private int $broadcastFlushOverruns = 0;
    private float $broadcastFlushLastMs = 0.0;
    private float $broadcastFlushMaxMs = 0.0;
    private float $broadcastFlushTotalMs = 0.0;

    /**
     * @param null|\Closure(): array{active_sse: int, active_contexts: int} $live this worker's open SSE streams and contexts, read on every getAll()
     */
    public function __construct(private ?\Closure $live = null) {}

    /**
     * Keep the request, action and SSE connection counters in shared memory, so every worker forked afterwards adds
     * to the same ones and they survive a worker restart.
     *
     * @internal called by Via::start() before the server forks its workers
     */
    public function share(): void {
        $this->shared ??= [
            'requests' => new Long($this->requests),
            'requestTimeUs' => new Long($this->requestTimeUs),
            'sseConnections' => new Long($this->sseConnections),
            'actions' => new Long($this->actions),
        ];
    }

    /**
     * Track a render operation.
     *
     * @internal
     *
     * @param float $duration Render duration in seconds
     */
    public function trackRender(float $duration): void {
        ++$this->renderCount;
        $this->totalRenderTime += $duration;
        $this->minRenderTime = min($this->minRenderTime, $duration);
        $this->maxRenderTime = max($this->maxRenderTime, $duration);
    }

    /**
     * Track a page, static file or route() request.
     *
     * @internal
     *
     * @param float $duration Request duration in milliseconds
     */
    public function trackRequest(float $duration = 0.0): void {
        $us = max(0, (int) round($duration * 1000));
        if ($this->shared !== null) {
            $this->shared['requests']->add();
            $this->shared['requestTimeUs']->add($us);

            return;
        }

        ++$this->requests;
        $this->requestTimeUs += $us;
    }

    /**
     * Track an SSE stream that opened.
     *
     * @internal
     */
    public function trackSseConnection(): void {
        if ($this->shared !== null) {
            $this->shared['sseConnections']->add();

            return;
        }

        ++$this->sseConnections;
    }

    /**
     * Track an action that runs, on the worker that runs it.
     *
     * @internal
     */
    public function trackAction(): void {
        if ($this->shared !== null) {
            $this->shared['actions']->add();

            return;
        }

        ++$this->actions;
    }

    /**
     * Track a GC run.
     *
     * @internal
     *
     * @param int $cyclesFreed Number of cycles freed, as returned by gc_collect_cycles()
     */
    public function trackGc(int $cyclesFreed): void {
        ++$this->gcRuns;
        $this->gcCyclesFreed += $cyclesFreed;
    }

    /**
     * Track a broadcast marked for the next flush.
     *
     * @internal
     *
     * @param bool $coalesced true when the scope was already waiting for that flush
     */
    public function trackBroadcastScheduled(bool $coalesced): void {
        ++$this->broadcastsScheduled;
        if ($coalesced) {
            ++$this->broadcastsCoalesced;
        }
    }

    /**
     * Track one broadcast flush.
     *
     * @internal
     *
     * @param float $durationMs wall time of the flush, yields included
     * @param int   $tickMs     the configured broadcast tick; a longer flush counts as an overrun
     */
    public function trackBroadcastFlush(float $durationMs, int $tickMs): void {
        ++$this->broadcastFlushes;
        $this->broadcastFlushLastMs = $durationMs;
        $this->broadcastFlushMaxMs = max($this->broadcastFlushMaxMs, $durationMs);
        $this->broadcastFlushTotalMs += $durationMs;
        if ($tickMs > 0 && $durationMs > $tickMs) {
            ++$this->broadcastFlushOverruns;
        }
    }

    /**
     * Broadcast flush statistics of this worker.
     *
     * Two readings of flush_total_ms taken a second apart give the share of that second the
     * worker spent flushing.
     *
     * @return array{scheduled: int, coalesced: int, flushes: int, flush_overruns: int, flush_last_ms: float, flush_max_ms: float, flush_total_ms: float}
     */
    public function getBroadcastStats(): array {
        return [
            'scheduled' => $this->broadcastsScheduled,
            'coalesced' => $this->broadcastsCoalesced,
            'flushes' => $this->broadcastFlushes,
            'flush_overruns' => $this->broadcastFlushOverruns,
            'flush_last_ms' => $this->broadcastFlushLastMs,
            'flush_max_ms' => $this->broadcastFlushMaxMs,
            'flush_total_ms' => $this->broadcastFlushTotalMs,
        ];
    }

    /**
     * Get render statistics summary.
     *
     * @return array{render_count: int, total_time: float, min_time: float, max_time: float, avg_time: float}
     */
    public function getStats(): array {
        $avgTime = $this->renderCount > 0
            ? $this->totalRenderTime / $this->renderCount
            : 0.0;

        return [
            'render_count' => $this->renderCount,
            'total_time' => $this->totalRenderTime,
            'min_time' => $this->minRenderTime === PHP_FLOAT_MAX ? 0.0 : $this->minRenderTime,
            'max_time' => $this->maxRenderTime,
            'avg_time' => $avgTime,
        ];
    }

    /**
     * Get all statistics. requests, avg_request_time (ms), actions and sse_connections count for the whole server;
     * active_sse and active_contexts are the open streams and contexts of this worker, and the render (seconds), GC
     * and broadcast (ms) figures count for this worker.
     *
     * @return array{requests: int, sse_connections: int, actions: int, active_sse: int, active_contexts: int, render_count: int, avg_render_time: float, avg_request_time: float, gc_runs: int, gc_cycles_freed: int, broadcasts_scheduled: int, broadcasts_coalesced: int, broadcast_flushes: int, broadcast_flush_overruns: int, broadcast_flush_last_ms: float, broadcast_flush_max_ms: float, broadcast_flush_total_ms: float}
     */
    public function getAll(): array {
        $shared = $this->shared;
        $requests = $shared === null ? $this->requests : $shared['requests']->get();
        $requestTimeUs = $shared === null ? $this->requestTimeUs : $shared['requestTimeUs']->get();
        $live = $this->live !== null ? ($this->live)() : ['active_sse' => 0, 'active_contexts' => 0];

        return [
            'requests' => $requests,
            'sse_connections' => $shared === null ? $this->sseConnections : $shared['sseConnections']->get(),
            'actions' => $shared === null ? $this->actions : $shared['actions']->get(),
            'active_sse' => $live['active_sse'],
            'active_contexts' => $live['active_contexts'],
            'render_count' => $this->renderCount,
            'avg_render_time' => $this->renderCount > 0 ? $this->totalRenderTime / $this->renderCount : 0.0,
            'avg_request_time' => $requests > 0 ? $requestTimeUs / $requests / 1000 : 0.0,
            'gc_runs' => $this->gcRuns,
            'gc_cycles_freed' => $this->gcCyclesFreed,
            'broadcasts_scheduled' => $this->broadcastsScheduled,
            'broadcasts_coalesced' => $this->broadcastsCoalesced,
            'broadcast_flushes' => $this->broadcastFlushes,
            'broadcast_flush_overruns' => $this->broadcastFlushOverruns,
            'broadcast_flush_last_ms' => $this->broadcastFlushLastMs,
            'broadcast_flush_max_ms' => $this->broadcastFlushMaxMs,
            'broadcast_flush_total_ms' => $this->broadcastFlushTotalMs,
        ];
    }

    /**
     * Reset all statistics but the live ones. Shared counters reset for every worker.
     *
     * @internal
     */
    public function reset(): void {
        $this->renderCount = 0;
        $this->totalRenderTime = 0.0;
        $this->minRenderTime = PHP_FLOAT_MAX;
        $this->maxRenderTime = 0.0;
        $this->requests = 0;
        $this->requestTimeUs = 0;
        $this->sseConnections = 0;
        $this->actions = 0;
        foreach ($this->shared ?? [] as $counter) {
            $counter->set(0);
        }
        $this->gcRuns = 0;
        $this->gcCyclesFreed = 0;
        $this->broadcastsScheduled = 0;
        $this->broadcastsCoalesced = 0;
        $this->broadcastFlushes = 0;
        $this->broadcastFlushOverruns = 0;
        $this->broadcastFlushLastMs = 0.0;
        $this->broadcastFlushMaxMs = 0.0;
        $this->broadcastFlushTotalMs = 0.0;
    }
}
