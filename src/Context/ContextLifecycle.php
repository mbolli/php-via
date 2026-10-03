<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Context;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;

/**
 * ContextLifecycle - Manages context cleanup, timers, and callbacks.
 *
 * Handles:
 * - Cleanup callbacks
 * - Timer management
 * - Background tasks
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
                $context = $this->context->get();
                $this->via->log('error', 'Interval callback failed: ' . Logger::describe($e), $context);
                $this->via->reportError($e, $context, ErrorPhase::Timer);
            }
        });
        $this->timerIds[] = $timerId;

        return $timerId;
    }

    /**
     * Run $task in a coroutine of its own and count it in Via::$runningTasks while it runs.
     * A throw is logged and reported with ErrorPhase::Task, unless an onError() callback started the task.
     *
     * @param callable(Context): void $task
     *
     * @throws \RuntimeException when OpenSwoole creates no coroutine, at max_coroutine
     */
    public function spawn(callable $task): void {
        $context = $this->context->get() ?? throw new \LogicException('spawn() on a freed context');
        // Reported, the throw of a task that reports an error would start the callbacks, and the task, again.
        $fromErrorCallback = $this->via->inErrorCallbacks();

        $cid = Coroutine::create(function () use ($task, $context, $fromErrorCallback): void {
            ++$this->via->runningTasks;

            try {
                $task($context);
            } catch (\Throwable $e) {
                $this->via->log('error', 'Task failed: ' . Logger::describe($e), $context);
                if (!$fromErrorCallback) {
                    $this->via->reportError($e, $context, ErrorPhase::Task);
                }
            } finally {
                --$this->via->runningTasks;
            }
        });

        if ($cid === false) {
            throw new \RuntimeException('Context::spawn() could not start a coroutine: the worker is at max_coroutine.');
        }
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
