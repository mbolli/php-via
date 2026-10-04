<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * When a worker runs PHP's cycle collector, whose every run walks all live objects. With PHP's own runs off, a run is
 * due once the memory in use has grown by half since the last one (by 32 MiB at least, and by half the room left
 * below memory_limit at most), and at least every interval while possible roots wait.
 *
 * @internal
 */
final class CycleCollector {
    /** How often a worker asks isDue(). */
    public const int CHECK_MS = 100;

    private const int MIN_GROWTH_BYTES = 32 << 20;

    /** Near memory_limit, the least growth that makes a run due, so a full heap is not walked every check. */
    private const int MIN_HEADROOM_GROWTH_BYTES = 1 << 20;

    /** Memory in use after the last run. */
    private int $base;

    private int $lastRunMs;

    /** @var \Closure(): int */
    private \Closure $memory;

    /** @var \Closure(): int */
    private \Closure $clockMs;

    /** @var \Closure(): int */
    private \Closure $roots;

    /**
     * @param int                  $intervalMs  longest time between runs while possible roots wait
     * @param int                  $memoryLimit memory_limit in bytes, 0 for none
     * @param null|\Closure(): int $memory      memory in use, for tests
     * @param null|\Closure(): int $clockMs     a monotonic clock in milliseconds, for tests
     * @param null|\Closure(): int $roots       possible roots waiting for the collector, for tests
     */
    public function __construct(
        private int $intervalMs,
        private int $memoryLimit,
        ?\Closure $memory = null,
        ?\Closure $clockMs = null,
        ?\Closure $roots = null,
    ) {
        $this->memory = $memory ?? static fn (): int => memory_get_usage();
        $this->clockMs = $clockMs ?? static fn (): int => intdiv(hrtime(true), 1_000_000);
        $this->roots = $roots ?? static fn (): int => gc_status()['roots'];
        $this->base = ($this->memory)();
        $this->lastRunMs = ($this->clockMs)();
    }

    /**
     * memory_limit in bytes, 0 when there is none.
     */
    public static function memoryLimit(string $ini): int {
        $ini = trim($ini);
        if ($ini === '' || $ini === '-1') {
            return 0;
        }

        $bytes = (int) $ini;
        $shift = match (strtolower($ini[-1])) {
            'g' => 30,
            'm' => 20,
            'k' => 10,
            default => 0,
        };

        return max(0, $bytes << $shift);
    }

    public function isDue(): bool {
        $growth = max(self::MIN_GROWTH_BYTES, intdiv($this->base, 2));
        if ($this->memoryLimit > 0) {
            $growth = min($growth, max(self::MIN_HEADROOM_GROWTH_BYTES, intdiv($this->memoryLimit - $this->base, 2)));
        }
        if (($this->memory)() - $this->base >= $growth) {
            return true;
        }

        return ($this->clockMs)() - $this->lastRunMs >= $this->intervalMs && ($this->roots)() > 0;
    }

    /**
     * Note a run that just ended.
     */
    public function ran(): void {
        $this->base = ($this->memory)();
        $this->lastRunMs = ($this->clockMs)();
    }
}
