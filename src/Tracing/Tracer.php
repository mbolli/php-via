<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Tracing;

use OpenSwoole\Coroutine;

/**
 * Per-worker tracer that records spans into coroutine-scoped traces.
 *
 * One Tracer instance exists per worker process (constructed by Via when
 * tracing is enabled) and is reachable ambiently via {@see Tracer::current()},
 * so deep framework code (ViewRenderer, PatchManager) can add spans without
 * threading a tracer through every signature. When tracing is off,
 * Tracer::current() is null and the `?->` call sites are zero-overhead.
 *
 * Trace state is keyed by OpenSwoole coroutine id: each request/SSE handler
 * runs in its own coroutine, so traces never bleed across concurrent requests.
 * In VIA_TEST_MODE (no OpenSwoole extension) a single synthetic coroutine id is
 * used so the tracer is fully unit-testable.
 *
 * startTrace() is a no-op when a trace is already open in the current
 * coroutine: a broadcast triggered synchronously inside an action keeps nesting
 * its render spans under the action trace, while a timer-driven broadcast (no
 * active trace) opens its own root trace.
 */
final class Tracer {
    private static ?self $instance = null;

    private bool $testMode;

    /** @var array<int, array{trace: Trace, stack: list<Span>}> coroutine id => open trace state */
    private array $states = [];

    public function __construct(private TraceStore $store, ?bool $testMode = null) {
        $this->testMode = $testMode ?? (getenv('VIA_TEST_MODE') === '1');
    }

    /**
     * The ambient tracer for this worker, or null when tracing is disabled.
     */
    public static function current(): ?self {
        return self::$instance;
    }

    public static function setCurrent(?self $tracer): void {
        self::$instance = $tracer;
    }

    public function getStore(): TraceStore {
        return $this->store;
    }

    /**
     * Open a root trace for the current coroutine.
     *
     * @return bool true if a new trace was started (caller owns endTrace), false
     *              if a trace was already active (caller must NOT call endTrace)
     */
    public function startTrace(string $label, string $rootCategory = 'request'): bool {
        $cid = $this->cid();
        if (isset($this->states[$cid])) {
            return false;
        }

        $traceId = self::generateId();
        $startNs = hrtime(true);
        $trace = new Trace($traceId, $label, $startNs, microtime(true) * 1000);
        $root = new Span(self::generateId(), $traceId, null, $label, $startNs, $rootCategory);
        $trace->addSpan($root);

        $this->states[$cid] = ['trace' => $trace, 'stack' => [$root]];

        return true;
    }

    /**
     * Close the current coroutine's root trace and push it to the store.
     */
    public function endTrace(): void {
        $cid = $this->cid();
        if (!isset($this->states[$cid])) {
            return;
        }

        $state = $this->states[$cid];
        // Close any spans the caller left open, then the root.
        foreach (array_reverse($state['stack']) as $span) {
            $span->end();
        }

        $this->store->push($state['trace']);
        unset($this->states[$cid]);
    }

    /**
     * Open a child span under the current coroutine's open span. No-op (returns
     * null) when no trace is active.
     *
     * @param array<string, mixed> $attributes
     */
    public function startSpan(string $name, array $attributes = [], ?string $category = null): ?Span {
        $cid = $this->cid();
        if (!isset($this->states[$cid])) {
            return null;
        }

        $stack = $this->states[$cid]['stack'];
        $parent = $stack === [] ? null : $stack[\count($stack) - 1];

        $span = new Span(
            self::generateId(),
            $this->states[$cid]['trace']->traceId,
            $parent?->id,
            $name,
            hrtime(true),
            $category ?? self::deriveCategory($name),
            $attributes,
        );

        $this->states[$cid]['trace']->addSpan($span);
        $this->states[$cid]['stack'][] = $span;

        return $span;
    }

    /**
     * Close the innermost open span for the current coroutine.
     */
    public function endSpan(): void {
        $cid = $this->cid();
        if (!isset($this->states[$cid])) {
            return;
        }

        // Never pop the root span (index 0) via endSpan — that is endTrace's job.
        if (\count($this->states[$cid]['stack']) <= 1) {
            return;
        }

        $span = array_pop($this->states[$cid]['stack']);
        $span->end();
    }

    /**
     * Run $fn wrapped in a span, recording errors and always closing the span.
     *
     * @param array<string, mixed> $attributes
     */
    public function span(string $name, callable $fn, array $attributes = [], ?string $category = null): mixed {
        $span = $this->startSpan($name, $attributes, $category);

        try {
            return $fn();
        } catch (\Throwable $e) {
            $span?->setStatus('error');
            $span?->setAttribute('error.message', $e->getMessage());

            throw $e;
        } finally {
            $this->endSpan();
        }
    }

    /**
     * Annotate the innermost open span for the current coroutine.
     */
    public function setAttribute(string $key, mixed $value): void {
        $span = $this->currentSpan();
        $span?->setAttribute($key, $value);
    }

    /**
     * Mark the innermost open span (and therefore the trace) as errored.
     */
    public function markError(string $message): void {
        $span = $this->currentSpan();
        $span?->setStatus('error');
        $span?->setAttribute('error.message', $message);
    }

    /**
     * Whether a trace is currently open in this coroutine.
     */
    public function hasActiveTrace(): bool {
        return isset($this->states[$this->cid()]);
    }

    private function currentSpan(): ?Span {
        $cid = $this->cid();
        if (!isset($this->states[$cid])) {
            return null;
        }
        $stack = $this->states[$cid]['stack'];

        return $stack === [] ? null : $stack[\count($stack) - 1];
    }

    private function cid(): int {
        if ($this->testMode) {
            return 0;
        }

        return Coroutine::getCid();
    }

    private static function deriveCategory(string $name): string {
        $pos = strpos($name, '.');

        return $pos === false ? 'app' : substr($name, 0, $pos);
    }

    private static function generateId(): string {
        return bin2hex(random_bytes(6));
    }
}
