<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * Performance statistics tracker.
 *
 * Tracks rendering performance metrics.
 */
class Stats {
    private int $renderCount = 0;
    private float $totalTime = 0.0;
    private float $minTime = PHP_FLOAT_MAX;
    private float $maxTime = 0.0;

    /**
     * Track a render operation.
     *
     * @param float $duration Render duration in seconds
     */
    public function trackRender(float $duration): void {
        ++$this->renderCount;
        $this->totalTime += $duration;
        $this->minTime = min($this->minTime, $duration);
        $this->maxTime = max($this->maxTime, $duration);
    }

    /**
     * Get statistics summary.
     *
     * @return array{render_count: int, total_time: float, min_time: float, max_time: float, avg_time: float}
     */
    public function getStats(): array {
        $avgTime = $this->renderCount > 0
            ? $this->totalTime / $this->renderCount
            : 0.0;

        return [
            'render_count' => $this->renderCount,
            'total_time' => $this->totalTime,
            'min_time' => $this->minTime === PHP_FLOAT_MAX ? 0.0 : $this->minTime,
            'max_time' => $this->maxTime,
            'avg_time' => $avgTime,
        ];
    }

    /**
     * Reset all statistics.
     */
    public function reset(): void {
        $this->renderCount = 0;
        $this->totalTime = 0.0;
        $this->minTime = PHP_FLOAT_MAX;
        $this->maxTime = 0.0;
    }
}
