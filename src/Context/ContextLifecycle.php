<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

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

    /**
     * @param \WeakReference<Context> $context weak, so a destroyed context leaves no cycle for PHP's collector
     */
    public function __construct(
        private \WeakReference $context,
        private Via $via,
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
     * A throw from the callback is logged and the timer keeps running, as with Via::setInterval().
     *
     * @param callable $callback The function to call on each tick
     * @param int      $ms       Interval in milliseconds
     *
     * @return int Timer ID
     */
    public function registerTimer(callable $callback, int $ms): int {
        $timerId = Timer::tick($ms, function (mixed ...$args) use ($callback): void {
            try {
                $callback(...$args);
            } catch (\Throwable $e) {
                $this->via->log('error', 'Interval callback failed: ' . Logger::describe($e), $this->context->get());
            }
        });
        $this->timerIds[] = $timerId;

        return $timerId;
    }

    /**
     * Execute cleanup callbacks and release resources.
     */
    public function cleanup(): void {
        $context = $this->context->get() ?? throw new \LogicException('Cleanup of a freed context');

        // Clear all timers first
        foreach ($this->timerIds as $timerId) {
            Timer::clear($timerId);
        }
        $this->timerIds = [];

        foreach ($this->cleanupCallbacks as $callback) {
            try {
                $callback($context);
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
