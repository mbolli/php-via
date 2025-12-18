<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Swoole\Timer;

/**
 * ContextLifecycle - Manages context cleanup, timers, and callbacks.
 *
 * Handles:
 * - Cleanup callbacks
 * - Timer management
 * - Disconnect handling
 * - Resource cleanup
 */
class ContextLifecycle {
    /** @var array<callable> */
    private array $cleanupCallbacks = [];

    /** @var array<int> Timer IDs created by this context */
    private array $timerIds = [];

    public function __construct(
        private Context $context,
    ) {}

    /**
     * Register a callback to be executed when the context is cleaned up.
     */
    public function addCleanupCallback(callable $callback): void {
        $this->cleanupCallbacks[] = $callback;
    }

    /**
     * Create a timer that will be automatically cleaned up with the context.
     *
     * @param callable $callback The function to call on each tick
     * @param int      $ms       Interval in milliseconds
     *
     * @return int Timer ID
     */
    public function registerTimer(callable $callback, int $ms): int {
        $timerId = Timer::tick($ms, $callback);
        $this->timerIds[] = $timerId;

        return $timerId;
    }

    /**
     * Execute cleanup callbacks and release resources.
     */
    public function cleanup(): void {
        error_log("ContextLifecycle::cleanup() called for context: {$this->context->getId()}");

        // Clear all timers first
        foreach ($this->timerIds as $timerId) {
            Timer::clear($timerId);
        }
        $this->timerIds = [];

        error_log('Executing ' . \count($this->cleanupCallbacks) . ' cleanup callbacks');
        foreach ($this->cleanupCallbacks as $callback) {
            try {
                $callback($this->context);
            } catch (\Throwable $e) {
                error_log('Cleanup callback error: ' . $e->getMessage());
            }
        }

        // Clear callbacks
        $this->cleanupCallbacks = [];
    }

    /**
     * Clear all timers without executing callbacks.
     */
    public function clearTimers(): void {
        foreach ($this->timerIds as $timerId) {
            Timer::clear($timerId);
        }
        $this->timerIds = [];
    }
}
