<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Tracing;

/**
 * In-process ring buffer of the most recent traces.
 *
 * Each pushed trace gets a monotonically increasing sequence number; the dev
 * bar's SSE stream fetches "everything since cursor X" to deliver new traces
 * live, and the console renders the most recent N for instant paint.
 *
 * Scope: this buffer lives in the worker process, so in single-worker mode
 * (the default and the common dev case) it sees every request. Under
 * worker_num > 1, each worker keeps its own buffer; a dev-console stream is
 * pinned to one worker and therefore shows that worker's traces only. That is
 * an accepted limitation for a development tool — production runs with tracing
 * off.
 */
final class TraceStore {
    /** @var array<int, array<string, mixed>> seq => trace array, oldest first */
    private array $buffer = [];

    private int $cursor = 0;

    public function __construct(private int $capacity = 100) {
        $this->capacity = max(1, $capacity);
    }

    /**
     * Append a trace, evicting the oldest when at capacity.
     */
    public function push(Trace $trace): void {
        $seq = ++$this->cursor;
        $this->buffer[$seq] = ['seq' => $seq] + $trace->toArray();

        if (\count($this->buffer) > $this->capacity) {
            array_shift($this->buffer);
        }
    }

    /**
     * Traces with a sequence number greater than $cursor, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function since(int $cursor): array {
        $out = [];
        foreach ($this->buffer as $seq => $trace) {
            if ($seq > $cursor) {
                $out[] = $trace;
            }
        }

        return $out;
    }

    /**
     * The most recent traces, newest first, capped at $limit.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array {
        $all = array_reverse(array_values($this->buffer));

        return \array_slice($all, 0, max(0, $limit));
    }

    /**
     * The highest sequence number issued so far (0 when empty).
     */
    public function cursor(): int {
        return $this->cursor;
    }

    public function clear(): void {
        $this->buffer = [];
    }
}
