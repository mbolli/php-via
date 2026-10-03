<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\ErrorPhase;
use OpenSwoole\Coroutine;

/**
 * The callbacks registered with Via::onError(), and the guard that keeps them from running inside themselves.
 *
 * @internal
 */
final class ErrorHooks {
    /** @var list<callable(\Throwable, ?Context, ErrorPhase, ?string): void> */
    private array $hooks = [];

    /** @var array<int, true> coroutines (-1 outside one) running the callbacks right now */
    private array $running = [];

    /**
     * @param \Closure(string, string, ?Context): void $log
     */
    public function __construct(private \Closure $log) {}

    /**
     * @param callable(\Throwable, ?Context, ErrorPhase, ?string): void $hook
     */
    public function add(callable $hook): void {
        $this->hooks[] = $hook;
    }

    /**
     * Pass a caught throw to every callback. A callback's own throw is logged, and a throw caught while the callbacks
     * run in this coroutine reaches none of them, since it would start them again.
     */
    public function report(\Throwable $e, ?Context $context, ErrorPhase $phase, ?string $action = null): void {
        $cid = Coroutine::getCid();
        if ($this->hooks === [] || isset($this->running[$cid])) {
            return;
        }

        $this->running[$cid] = true;

        try {
            foreach ($this->hooks as $hook) {
                try {
                    $hook($e, $context, $phase, $action);
                } catch (\Throwable $hookError) {
                    ($this->log)('error', "onError callback failed (phase {$phase->value}): " . Logger::describe($hookError), $context);
                }
            }
        } finally {
            unset($this->running[$cid]);
        }
    }
}
