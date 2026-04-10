<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * Performance statistics tracker.
 *
 * Tracks rendering performance metrics and request statistics.
 */
class Stats {
    // Render stats
    private int $renderCount = 0;
    private float $totalRenderTime = 0.0;
    private float $minRenderTime = PHP_FLOAT_MAX;
    private float $maxRenderTime = 0.0;

    // Request stats
    private int $requests = 0;
    private int $sseConnections = 0;
    private int $actions = 0;
    private int $activeSse = 0;
    private int $activeContexts = 0;
    private float $totalRequestTime = 0.0;

    // GC stats
    private int $gcRuns = 0;
    private int $gcCyclesFreed = 0;

    /**
     * Track a render operation.
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
     * Track an HTTP request.
     *
     * @param float $duration Request duration in milliseconds
     */
    public function trackRequest(float $duration = 0.0): void {
        ++$this->requests;
        $this->totalRequestTime += $duration;
    }

    /**
     * Track an SSE connection.
     */
    public function trackSseConnection(): void {
        ++$this->sseConnections;
    }

    /**
     * Track an action execution.
     */
    public function trackAction(): void {
        ++$this->actions;
    }

    /**
     * Set the number of active SSE connections.
     */
    public function setActiveSse(int $count): void {
        $this->activeSse = $count;
    }

    /**
     * Set the number of active contexts.
     */
    public function setActiveContexts(int $count): void {
        $this->activeContexts = $count;
    }

    /**
     * Track a GC run.
     *
     * @param int $cyclesFreed Number of cycles freed, as returned by gc_collect_cycles()
     */
    public function trackGc(int $cyclesFreed): void {
        ++$this->gcRuns;
        $this->gcCyclesFreed += $cyclesFreed;
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
     * Get all statistics.
     *
     * @return array<string, float|int>
     */
    public function getAll(): array {
        return [
            'requests' => $this->requests,
            'sse_connections' => $this->sseConnections,
            'actions' => $this->actions,
            'active_sse' => $this->activeSse,
            'active_contexts' => $this->activeContexts,
            'render_count' => $this->renderCount,
            'avg_render_time' => $this->renderCount > 0 ? $this->totalRenderTime / $this->renderCount : 0.0,
            'avg_request_time' => $this->requests > 0 ? $this->totalRequestTime / $this->requests : 0.0,
            'gc_runs' => $this->gcRuns,
            'gc_cycles_freed' => $this->gcCyclesFreed,
        ];
    }

    /**
     * Reset all statistics.
     */
    public function reset(): void {
        $this->renderCount = 0;
        $this->totalRenderTime = 0.0;
        $this->minRenderTime = PHP_FLOAT_MAX;
        $this->maxRenderTime = 0.0;
        $this->requests = 0;
        $this->sseConnections = 0;
        $this->actions = 0;
        $this->totalRequestTime = 0.0;
        $this->gcRuns = 0;
        $this->gcCyclesFreed = 0;
        // Note: active_sse and active_contexts are not reset
    }
}
