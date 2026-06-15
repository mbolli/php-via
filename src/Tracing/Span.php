<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Tracing;

/**
 * A single timed operation within a {@see Trace}.
 *
 * Spans form a tree via {@see $parentId}. Timing uses hrtime(true) (monotonic
 * nanoseconds) so durations are immune to wall-clock adjustments. The wall-clock
 * anchor for the human-readable timestamp lives on the Trace, not here.
 */
final class Span {
    private ?int $endNs = null;
    private string $status = 'ok';

    /**
     * @param string               $id         Unique span id
     * @param string               $traceId    Owning trace id
     * @param null|string          $parentId   Parent span id (null for the root span)
     * @param string               $name       Dotted name, e.g. "db.list_issues" or "GET /"
     * @param int                  $startNs    hrtime(true) at span start
     * @param string               $category   Colour/grouping bucket (e.g. request, render, db, sse)
     * @param array<string, mixed> $attributes Arbitrary key/value annotations
     */
    public function __construct(
        public readonly string $id,
        public readonly string $traceId,
        public readonly ?string $parentId,
        public readonly string $name,
        public readonly int $startNs,
        public readonly string $category,
        private array $attributes = [],
    ) {}

    /**
     * Close the span. Idempotent — only the first call records the end time.
     *
     * @param null|int $endNs hrtime(true) value; defaults to now
     */
    public function end(?int $endNs = null): void {
        if ($this->endNs === null) {
            $this->endNs = $endNs ?? hrtime(true);
        }
    }

    public function isEnded(): bool {
        return $this->endNs !== null;
    }

    public function setAttribute(string $key, mixed $value): void {
        $this->attributes[$key] = $value;
    }

    public function setStatus(string $status): void {
        $this->status = $status;
    }

    public function getStatus(): string {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array {
        return $this->attributes;
    }

    /**
     * Duration in milliseconds. Uses "now" if the span is still open.
     */
    public function durationMs(): float {
        return (($this->endNs ?? hrtime(true)) - $this->startNs) / 1_000_000;
    }

    /**
     * Offset of this span's start from the trace root start, in milliseconds.
     * Drives the left edge of the waterfall bar.
     */
    public function offsetMsFrom(int $traceStartNs): float {
        return ($this->startNs - $traceStartNs) / 1_000_000;
    }

    /**
     * UI-ready representation. Attributes are NOT sanitized here — {@see Trace::toArray()}
     * applies {@see Sanitizer} across the whole trace so redaction is centralised.
     *
     * @return array<string, mixed>
     */
    public function toArray(int $traceStartNs): array {
        return [
            'id' => $this->id,
            'parentId' => $this->parentId,
            'name' => $this->name,
            'category' => $this->category,
            'offsetMs' => round($this->offsetMsFrom($traceStartNs), 3),
            'durationMs' => round($this->durationMs(), 3),
            'attributes' => $this->attributes,
            'status' => $this->status,
        ];
    }
}
