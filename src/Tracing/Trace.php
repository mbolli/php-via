<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Tracing;

/**
 * A completed (or in-flight) root operation and all spans collected under it.
 *
 * The first span added is the root; its duration is the trace's total duration.
 * {@see toArray()} emits the UI-ready JSON the dev bar consumes — each span is
 * pre-computed with offset+duration so the front-end only maps to bar geometry.
 */
final class Trace {
    /** @var list<Span> Root span first, then children in creation order. */
    private array $spans = [];

    /**
     * @param string $traceId          Unique trace id
     * @param string $label            Human label, e.g. "GET /" or "POST increment"
     * @param int    $startNs          hrtime(true) at trace start (offset anchor)
     * @param float  $wallClockStartMs microtime(true) * 1000 at start (for the clock timestamp)
     */
    public function __construct(
        public readonly string $traceId,
        public readonly string $label,
        public readonly int $startNs,
        public readonly float $wallClockStartMs,
    ) {}

    public function addSpan(Span $span): void {
        $this->spans[] = $span;
    }

    public function rootSpan(): ?Span {
        return $this->spans[0] ?? null;
    }

    public function spanCount(): int {
        return \count($this->spans);
    }

    public function hasError(): bool {
        foreach ($this->spans as $span) {
            if ($span->getStatus() === 'error') {
                return true;
            }
        }

        return false;
    }

    /**
     * UI-ready representation with sanitized attributes.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array {
        $root = $this->spans[0] ?? null;

        $spans = [];
        foreach ($this->spans as $span) {
            $entry = $span->toArray($this->startNs);

            /** @var array<string, mixed> $attrs */
            $attrs = $entry['attributes'];
            $entry['attributes'] = Sanitizer::sanitizeAttributes($attrs);
            $spans[] = $entry;
        }

        return [
            'traceId' => $this->traceId,
            'label' => $this->label,
            'wallClockStartMs' => $this->wallClockStartMs,
            'totalDurationMs' => round($root?->durationMs() ?? 0.0, 3),
            'spanCount' => \count($this->spans),
            'status' => $this->hasError() ? 'error' : 'ok',
            'spans' => $spans,
        ];
    }
}
