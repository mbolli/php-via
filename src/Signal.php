<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Mbolli\PhpVia\State\SharedSignalStore;

/**
 * Signal represents a reactive value synchronized between server and browser.
 *
 * Signals can be TAB-scoped (per-context) or shared across a scope.
 */
class Signal {
    private string $id;
    private mixed $value = null;
    private bool $changed = true;
    private ?string $scope = null;
    private bool $autoBroadcast = true;
    private bool $clientWritable = false;
    private ?Via $app = null;

    /**
     * Monotonic count of value-changing writes made through this Signal object.
     *
     * Lets a caller that hands the signal to arbitrary code find out afterwards whether that
     * code wrote it. PageMount uses it to tell a #[Signal] property the action left alone from
     * one whose signal the action wrote directly, so it does not assign a stale property back
     * over an explicit write. Per-process and not a version of the shared value: another
     * worker's write does not move it.
     */
    private int $writes = 0;

    /**
     * Cross-worker backing for this signal's value, or null when it lives in this process only.
     *
     * Attached by Via for scoped signals when worker_num > 1. TAB signals never get one: they
     * are per-connection, need no sharing, and are the hot path.
     */
    private ?SharedSignalStore $store = null;

    public function __construct(
        string $id,
        mixed $initialValue,
        ?string $scope = null,
        bool $autoBroadcast = true,
        bool $clientWritable = false,
        ?Via $app = null
    ) {
        $this->id = $id;
        $this->scope = $scope;
        $this->autoBroadcast = $autoBroadcast;
        $this->clientWritable = $clientWritable;
        $this->app = $app;
        $this->setValue($initialValue, false); // Don't trigger broadcast on init
        $this->changed = true; // But mark as changed for initial sync
    }

    /**
     * Get the signal ID.
     */
    public function id(): string {
        return $this->id;
    }

    /**
     * Attach cross-worker backing and adopt the value already in force.
     *
     * A worker mounting a route another worker already serves must take the LIVE value, not
     * reset the scope to this worker's declared default.
     *
     * @internal called by Via when registering a scoped signal in multi-worker mode
     */
    public function attachSharedStore(SharedSignalStore $store): void {
        $this->store = $store;
        $this->value = $store->initialize($this->id, $this->value);
    }

    /**
     * Get the signal value.
     */
    public function getValue(): mixed {
        if ($this->store !== null) {
            // Read through: another worker may have moved it since this one last looked.
            $this->value = $this->store->get($this->id, $this->value);
        }

        return $this->value;
    }

    /**
     * Read, transform and write this signal's value as one indivisible step.
     *
     * The supported way to do read-modify-write on a NON-integer scoped signal — appending to a
     * list, updating one key of a map. `setValue($signal->array() + [...])` is a read and a write
     * with a gap in between, so with more than one worker each writes back a result computed
     * from the same stale read; measured over 4 workers appending to one list, 79 of 160 entries
     * survived. increment() covers the numeric case; a wholesale assignment needs nothing.
     *
     * The callback runs on this worker while a lock keeps other workers out, so keep it fast and
     * free of side effects — it runs inside the lock, and one that blocks holds up every other
     * writer of the same signal.
     *
     * Do not mix mutate() and increment() on the same signal: increment() deliberately skips the
     * lock, since a single atomic operation needs no help, so a mutate() running beside it can
     * write back over an increment that landed in between. Pick one per signal.
     *
     * Single-worker behaviour is a plain read-transform-write, with no locking involved.
     *
     * @template T
     *
     * @param callable(mixed): T $mutator Receives the current value, returns the new one
     *
     * @return T the value written
     */
    public function mutate(callable $mutator, bool $broadcast = true): mixed {
        $next = $this->store !== null
            ? $this->store->mutate($this->id, $mutator)
            : $mutator($this->value);

        $this->value = $next;
        $this->changed = true;
        ++$this->writes;

        if ($broadcast && $this->isScoped() && $this->autoBroadcast && $this->app !== null) {
            $this->app->broadcast($this->scope);
        }

        return $next;
    }

    /**
     * Add to a numeric signal atomically and return the new value.
     *
     * `setValue($signal->int() + 1)` is a read-modify-write: with more than one worker, two
     * of them can read the same value and each write back the same increment, silently losing
     * one. This routes to an atomic shared-memory increment instead — measured at 100%
     * retention with 8 processes racing, against 31% for read-modify-write.
     *
     * Single-worker behaviour is identical to the equivalent setValue().
     *
     * @param bool $broadcast Whether to auto-broadcast (scoped signals with autoBroadcast only)
     *
     * @throws \LogicException if the signal does not hold an integer
     */
    public function increment(int $by = 1, bool $broadcast = true): int {
        if ($this->store !== null) {
            $next = $this->store->increment($this->id, $by);
        } else {
            $current = $this->value;
            if (!\is_int($current)) {
                throw new \LogicException(
                    "Signal \"{$this->id}\" does not hold an integer, so it cannot be incremented."
                );
            }
            $next = $current + $by;
        }

        $this->value = $next;
        $this->changed = true;
        ++$this->writes;

        if ($broadcast && $this->isScoped() && $this->autoBroadcast && $this->app !== null && $by !== 0) {
            $this->app->broadcast($this->scope);
        }

        return $next;
    }

    /**
     * Set the signal value.
     *
     * @param mixed $value       The new value to set
     * @param bool  $markChanged Whether to mark signal as changed for sync
     * @param bool  $broadcast   Whether to auto-broadcast (only applies if markChanged=true)
     */
    public function setValue(mixed $value, bool $markChanged = true, bool $broadcast = true): void {
        // Check if value actually changed. With a shared backing the comparison has to be
        // against what is actually stored, not against this worker's last-seen copy.
        $oldValue = $this->store !== null ? $this->store->get($this->id, $this->value) : $this->value;

        $this->value = $value;
        $this->store?->set($this->id, $value);

        if ($markChanged) {
            $this->changed = true;
            ++$this->writes;

            // Auto-broadcast for scoped signals (if enabled, broadcast=true, and value changed)
            if ($broadcast
                && $this->isScoped()
                && $this->autoBroadcast
                && $this->app !== null
                && $oldValue !== $this->value) {
                $this->app->broadcast($this->scope);
            }
        }
    }

    /**
     * Check if this signal is scoped (non-TAB scope).
     */
    public function isScoped(): bool {
        return $this->scope !== null && $this->scope !== Scope::TAB;
    }

    /**
     * Get the signal's scope.
     */
    public function getScope(): ?string {
        return $this->scope;
    }

    /**
     * Whether this signal accepts values from the client.
     * TAB-scoped signals are always client-writable.
     * Scoped signals default to server-authoritative; opt in with clientWritable: true.
     */
    public function isClientWritable(): bool {
        return $this->clientWritable || !$this->isScoped();
    }

    /**
     * Number of value-changing writes made through this Signal object so far.
     *
     * Only useful as a before/after comparison around a call into other code: an unchanged
     * count means that code did not write this signal.
     */
    public function writeCount(): int {
        return $this->writes;
    }

    /**
     * Check if signal has changed.
     */
    public function hasChanged(): bool {
        return $this->changed;
    }

    /**
     * Mark signal as synced.
     */
    public function markSynced(): void {
        $this->changed = false;
    }

    /**
     * Get value as string.
     */
    public function string(): string {
        $value = $this->store === null ? $this->value : $this->getValue();

        if (\is_array($value) || \is_object($value)) {
            return json_encode($value) ?: '';
        }

        return (string) $value;
    }

    /**
     * Get value as integer.
     *
     * The null check is inlined here and in the other typed accessors rather than deferred to
     * getValue(): these are the framework's hottest reads, and routing every one through an
     * extra method call cost 0.0149 -> 0.0242 us even with no store attached. Single-worker
     * deployments must not pay for a multi-worker feature.
     */
    public function int(): int {
        return (int) ($this->store === null ? $this->value : $this->getValue());
    }

    /**
     * Get value as float.
     */
    public function float(): float {
        return (float) ($this->store === null ? $this->value : $this->getValue());
    }

    /**
     * Get value as boolean.
     */
    public function bool(): bool {
        $value = $this->store === null ? $this->value : $this->getValue();

        if (\is_array($value) || \is_object($value)) {
            return !empty($value);
        }

        $val = mb_strtolower((string) $value);

        return \in_array($val, ['true', '1', 'yes', 'on'], true);
    }

    /**
     * Get value as array.
     *
     * @return array<mixed>
     */
    public function array(): array {
        $value = $this->store === null ? $this->value : $this->getValue();

        return \is_array($value) ? $value : (array) $value;
    }

    /**
     * Bind this signal to an HTML input element
     * Returns the data-bind attribute.
     */
    public function bind(): string {
        return 'data-bind="' . $this->id . '"';
    }

    /**
     * Display this signal's value as text in an HTML element
     * Returns a span element with the signal binding.
     */
    public function text(): string {
        return '<span data-text="$' . $this->id . '"></span>';
    }
}
