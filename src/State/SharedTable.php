<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Coroutine;
use OpenSwoole\Exception;
use OpenSwoole\Table;

/**
 * Shared key-value store backed by OpenSwoole\Table.
 *
 * OpenSwoole\Table is allocated in the master process before $server->start()
 * and is shared across all worker processes via mmap (fork-inherited). This
 * makes it the right primitive for per-machine shared state without any
 * external dependency.
 *
 * Values are PHP-serialized before storage, so any serializable type works.
 *
 * ## Two storage paths
 *
 * Integers get a dedicated `TYPE_INT` column, because `Table::incr()` on it is atomic across
 * processes. That makes {@see increment()} race-free by construction, with no lock and no owner
 * worker. Everything else is PHP-serialized into a string column with last-write-wins, which is
 * correct for a value assigned wholesale — there is nothing to lose. Read-modify-write on a
 * non-integer is the shape LWW cannot handle, and {@see mutate()} covers it with a ticket lock.
 *
 * Which column a key uses follows its current value and can change with it: writing a string
 * over a counter moves it to the serialized path, which is why {@see increment()} rejects a key
 * that is not holding an integer rather than silently restarting it from zero.
 *
 * The distinction survives the durable snapshot. {@see takeDirty()} serializes integer rows on
 * the way out and {@see seed()} recognises them on the way back in, so a counter reloaded at
 * start-up stays on the atomic path instead of coming back as an opaque blob that reads
 * correctly and then throws on the next increment.
 *
 * Limits (measured on ext-openswoole 26.2.0):
 *   - Row capacity is fixed at construction time, but it is NOT $maxRows. OpenSwoole rounds the
 *     allocation up (power of two, floor 64) and the usable count runs well past that: 1024 rows
 *     admits ~1776 keys, 4096 admits ~8043. Rejection is per-key-hash and intermittent — at
 *     $maxRows = 1024 the first failure came at insert 1621 yet 1776 succeeded in total — so the
 *     effective ceiling is not a number a caller can plan against. Treat $maxRows as a floor.
 *     There is no eviction: once full, further distinct keys are rejected.
 *   - Maximum serialized byte size of a single value is $maxValueBytes.
 *   - Keys may be at most MAX_KEY_LENGTH characters; longer keys are rejected, not truncated.
 *
 * In VIA_TEST_MODE the OpenSwoole extension is not loaded; a plain PHP array
 * is used as a fallback so unit tests can exercise SharedTable without
 * starting a real server.
 */
final class SharedTable {
    /**
     * OpenSwoole's usable key length is 63, not 64.
     *
     * At 64 it accepts the write but emits "key[...] is too long" as a PHP warning on EVERY
     * write. It does not truncate — two 64-character keys differing only in the final character
     * stay distinct — so the old limit of 64 was log noise on the hot path rather than
     * corruption. Rejecting at 64 turns a per-write warning into one clear exception.
     */
    private const int MAX_KEY_LENGTH = 63;

    /** Row uses the atomic integer column. */
    private const int KIND_INT = 1;

    /** Row uses the serialized string column. */
    private const int KIND_SERIALIZED = 0;

    /**
     * How long a mutate() lock may be held before waiters assume the holder died.
     *
     * The callback runs on the calling worker between an acquire and a release, so a worker
     * killed mid-callback would otherwise stall every later mutate() on that key forever.
     */
    private const int LEASE_MS = 2000;

    /** How long acquire() waits before giving up entirely. */
    private const int ACQUIRE_TIMEOUT_MS = 5000;

    /** @var null|Table OpenSwoole shared-memory table (null in test mode) */
    private ?Table $table;

    /** @var array<string, string> Fallback store used in VIA_TEST_MODE */
    private array $fallback = [];

    /** @var array<string, true> Keys written since the last takeDirty(), test-mode only */
    private array $fallbackDirty = [];

    private bool $testMode;

    private int $maxValueBytes;

    public function __construct(int $maxRows = 1024, int $maxValueBytes = 32768, bool $testMode = false) {
        $this->testMode = $testMode;
        $this->maxValueBytes = $maxValueBytes;

        if ($testMode) {
            $this->table = null;

            return;
        }

        $this->table = new Table($maxRows);
        // Which of the two value columns this row is using.
        $this->table->column('kind', Table::TYPE_INT, 1);
        // Integer fast path: Table::incr() on this column is atomic across processes.
        $this->table->column('n', Table::TYPE_INT, 8);
        // Everything else: the serialized PHP value.
        $this->table->column('value', Table::TYPE_STRING, $maxValueBytes);
        // Set on every write, cleared by takeDirty(). Lets the durable tier flush only what
        // changed instead of rewriting the whole table on each tick.
        $this->table->column('dirty', Table::TYPE_INT, 1);
        // Ticket lock for mutate(), carried on the value's own row. A partial Table::set()
        // leaves unlisted columns untouched, so value writes, dirty flags and lock writes do
        // not disturb each other, and all take the same per-row lock inside OpenSwoole.
        $this->table->column('next', Table::TYPE_INT, 8);
        $this->table->column('serving', Table::TYPE_INT, 8);
        $this->table->column('lease', Table::TYPE_INT, 8);
        $this->table->create();
    }

    /**
     * Store a value under the given key.
     *
     * @throws \OverflowException        if the serialized value exceeds the column byte limit,
     *                                   or if the table has no room left for a new key
     * @throws \InvalidArgumentException if the key exceeds MAX_KEY_LENGTH characters
     */
    public function set(string $key, mixed $value): void {
        $key = $this->normalizeKey($key);
        $serialized = serialize($value);

        // Check size limit in all modes — prevents silent data loss in production
        // and makes the constraint visible during development/testing.
        if (\strlen($serialized) > $this->maxValueBytes) {
            throw new \OverflowException(
                "GlobalState value for key \"{$key}\" exceeds SharedTable column size "
                . "({$this->maxValueBytes} bytes). Use Config::withGlobalStateTableSize() to increase the limit."
            );
        }

        if ($this->testMode) {
            $this->fallback[$key] = $serialized;
            $this->fallbackDirty[$key] = true;

            return;
        }

        try {
            $this->table->set($key, \is_int($value)
                ? ['kind' => self::KIND_INT, 'n' => $value, 'value' => '', 'dirty' => 1]
                : ['kind' => self::KIND_SERIALIZED, 'n' => 0, 'value' => $serialized, 'dirty' => 1]);
        } catch (Exception $e) {
            // OpenSwoole throws a bare exception once the rows are exhausted, which reaches the
            // caller as a generic 500. Shape it like the value-size guard above so the failure
            // names its own remedy.
            throw new \OverflowException(
                "GlobalState has no room for key \"{$key}\": the shared table is full. "
                . 'Raise the row count with Config::withGlobalStateTableSize(). Note that the '
                . 'usable capacity is not exactly the configured row count — OpenSwoole rounds '
                . 'the allocation and rejects keys by hash, so leave headroom.',
                0,
                $e
            );
        }
    }

    /**
     * Retrieve a value by key, returning $default if not set.
     */
    public function get(string $key, mixed $default = null): mixed {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            if (!isset($this->fallback[$key])) {
                return $default;
            }

            return unserialize($this->fallback[$key]);
        }

        return $this->read($key, $default);
    }

    /**
     * Add to an integer value atomically, returning the new value.
     *
     * This is the race-free path: two workers each calling increment(1) always produce +2,
     * where two read-modify-write set() calls may produce +1. Measured over 4 forked workers
     * doing 500 mutations each on one key, increment() keeps all 2000 and read-modify-write
     * through set() keeps well under half.
     *
     * A key nothing has written yet starts at zero, so the first increment(1) returns 1.
     *
     * @throws \LogicException if the key is currently holding a non-integer
     */
    public function increment(string $key, int $by = 1): int {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            $current = isset($this->fallback[$key]) ? unserialize($this->fallback[$key]) : 0;
            $this->assertInt($key, $current);
            $next = $current + $by;
            $this->fallback[$key] = serialize($next);
            $this->fallbackDirty[$key] = true;

            return $next;
        }

        $row = $this->table->get($key);

        if (\is_array($row) && (int) $row['kind'] !== self::KIND_INT) {
            $this->assertInt($key, $this->read($key, 0));
        }

        if (!\is_array($row)) {
            // First touch. This MUST be a partial set naming only 'kind': it creates the row
            // with n = 0 when absent, and touches nothing but 'kind' when another worker got
            // there first. Seeding 'n' => 0 here instead loses that worker's increment — two
            // workers both find the row missing, and the second one's write resets the counter
            // the first has already advanced. Observed as a barrier that never fills.
            $this->table->set($key, ['kind' => self::KIND_INT]);
        }

        $next = (int) $this->table->incr($key, 'n', $by);
        // incr() touches only 'n', so the durable tier has to be told separately. Doing it
        // after the increment means a takeDirty() landing in between re-flushes next tick
        // rather than dropping the write — the same benign race takeDirty() already documents.
        $this->table->set($key, ['dirty' => 1]);

        return $next;
    }

    /**
     * Read, transform and write a value as one indivisible step.
     *
     * The answer for the shape neither other path covers: read-modify-write on a NON-integer
     * value — appending to a list, updating one key of a map. increment() handles numbers
     * atomically and a wholesale assignment has no lost update to suffer, but
     * `set($k, $table->get($k) + [...])` is a read and a write with a gap in between, and
     * concurrent workers each write back a result computed from the same stale read.
     *
     * $mutator receives the current value (null if nothing has been written yet) and returns
     * the new one. It runs on the calling worker — no closure crosses a process boundary —
     * while a ticket lock on the row keeps every other worker out. Keep it fast and side-effect
     * free: it runs inside the lock, and a callback that blocks holds up every other writer of
     * the same key.
     *
     * Do not mix mutate() and increment() on the SAME key. increment() deliberately skips the
     * lock — a single atomic operation needs no help — so a mutate() running beside it can
     * read, compute and write back over an increment that landed in between. Pick one per key.
     *
     * @template T
     *
     * @param callable(mixed): T $mutator
     *
     * @return T the value written
     */
    public function mutate(string $key, callable $mutator): mixed {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            $next = $mutator(isset($this->fallback[$key]) ? unserialize($this->fallback[$key]) : null);
            $this->set($key, $next);

            return $next;
        }

        $this->ensureRow($key);
        $ticket = $this->acquire($key);

        try {
            $next = $mutator($this->read($key, null));
            $this->set($key, $next);

            return $next;
        } finally {
            $this->release($key, $ticket);
        }
    }

    /**
     * Delete a key. No-op if the key does not exist.
     */
    public function delete(string $key): void {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            unset($this->fallback[$key]);

            return;
        }

        $this->table->del($key);
    }

    /**
     * Seed a value without marking it dirty.
     *
     * Used when loading the durable snapshot at start-up: those entries came FROM storage, so
     * flushing them straight back would write the whole set again on the first tick.
     *
     * Integers are unwrapped onto the atomic column rather than left as a blob. A counter
     * restored as KIND_SERIALIZED would still read back correctly and then throw on its next
     * increment() — a failure that surfaces only after a restart.
     */
    public function seed(string $key, string $serialized): void {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            $this->fallback[$key] = $serialized;

            return;
        }

        $value = unserialize($serialized);

        $this->table->set($key, \is_int($value)
            ? ['kind' => self::KIND_INT, 'n' => $value, 'value' => '', 'dirty' => 0]
            : ['kind' => self::KIND_SERIALIZED, 'n' => 0, 'value' => $serialized, 'dirty' => 0]);
    }

    /**
     * Take every key written since the last call, clearing the flags as it goes.
     *
     * Racy by construction and deliberately so: a write landing between the read and the clear
     * has its flag reset while its value is already in the batch, so it is persisted once rather
     * than twice. A write landing after the clear stays dirty for the next tick. Neither loses
     * data — the table always holds the authoritative value.
     *
     * @return array<string, string> key => serialized value
     */
    public function takeDirty(): array {
        if ($this->testMode) {
            $dirty = [];
            foreach (array_keys($this->fallbackDirty) as $key) {
                if (isset($this->fallback[$key])) {
                    $dirty[$key] = $this->fallback[$key];
                }
            }
            $this->fallbackDirty = [];

            return $dirty;
        }

        $dirty = [];
        foreach ($this->table as $key => $row) {
            if ((int) ($row['dirty'] ?? 0) === 1) {
                // Integer rows live in 'n', so serialize them here — the durable tier stores
                // blobs, and seed() unwraps them back onto the atomic column at start-up.
                $dirty[(string) $key] = (int) ($row['kind'] ?? self::KIND_SERIALIZED) === self::KIND_INT
                    ? serialize((int) $row['n'])
                    : (string) $row['value'];
            }
        }

        foreach (array_keys($dirty) as $key) {
            $this->table->set($key, ['dirty' => 0]);
        }

        return $dirty;
    }

    private function normalizeKey(string $key): string {
        if (\strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException(
                "SharedTable key \"{$key}\" exceeds the maximum of " . self::MAX_KEY_LENGTH . ' characters.'
            );
        }

        return $key;
    }

    /** Shared by both storage modes so the message does not depend on which one is live. */
    private function assertInt(string $key, mixed $current): void {
        if (!\is_int($current)) {
            throw new \LogicException(
                "GlobalState key \"{$key}\" does not hold an integer, so it cannot be incremented "
                . 'atomically. Use setGlobalState() — but note that read-modify-write on a shared '
                . 'value can lose updates when more than one worker writes it, and mutateGlobalState() '
                . 'is the race-free way to transform a non-integer.'
            );
        }
    }

    /** Read straight from the live row, honouring whichever column the value is stored in. */
    private function read(string $key, mixed $default): mixed {
        if ($this->testMode) {
            return isset($this->fallback[$key]) ? unserialize($this->fallback[$key]) : $default;
        }

        $row = $this->table->get($key);

        if (!\is_array($row)) {
            return $default;
        }

        if ((int) ($row['kind'] ?? self::KIND_SERIALIZED) === self::KIND_INT) {
            return (int) $row['n'];
        }

        $serialized = (string) ($row['value'] ?? '');

        return $serialized === '' ? $default : unserialize($serialized);
    }

    /**
     * Create the row if absent, so incr() on the ticket column never has to allocate.
     * incr() by zero creates a zeroed row atomically; a set() here would reset the ticket, serving
     * and value columns of a row another worker created and is already mutating.
     */
    private function ensureRow(string $key): void {
        if ($this->table->exists($key)) {
            return;
        }

        // At capacity incr() warns and fails rather than throwing, so check that the row appeared.
        @$this->table->incr($key, 'next', 0);
        if (!\is_array($this->table->get($key))) {
            throw new \OverflowException(
                "GlobalState has no room for key \"{$key}\": the shared table is full. "
                . 'Raise the row count with Config::withGlobalStateTableSize().'
            );
        }
    }

    /**
     * Take the ticket lock on a row, returning the ticket that must be released.
     *
     * A ticket lock rather than a mutex because Table::incr() is the only cross-process atomic
     * operation available: OpenSwoole\Lock must not be used inside coroutine context (it blocks
     * the whole worker, not just the caller) and Table offers no compare-and-swap.
     */
    private function acquire(string $key): int {
        $ticket = (int) $this->table->incr($key, 'next', 1) - 1;

        $startMs = (int) (microtime(true) * 1000);
        $forcedOnce = false;

        while (true) {
            $row = $this->table->get($key);
            $serving = \is_array($row) ? (int) $row['serving'] : 0;

            // >= rather than ==: a lease breaker may have advanced past this ticket, in which
            // case the lock has already degraded and waiting longer achieves nothing.
            if ($serving >= $ticket) {
                $this->table->set($key, ['lease' => (int) (microtime(true) * 1000) + self::LEASE_MS]);

                return $ticket;
            }

            $nowMs = (int) (microtime(true) * 1000);
            $lease = \is_array($row) ? (int) $row['lease'] : 0;

            // Holder overdue: assume the process died mid-callback and let the queue move.
            // Each waiter breaks the lease at most once, so a burst of waiters can over-advance
            // and skip tickets — those waiters take the >= branch above and proceed. Mutual
            // exclusion degrades to the unlocked behaviour rather than deadlocking.
            if (!$forcedOnce && $lease > 0 && $nowMs > $lease) {
                $forcedOnce = true;
                $this->table->incr($key, 'serving', 1);

                continue;
            }

            if ($nowMs - $startMs > self::ACQUIRE_TIMEOUT_MS) {
                throw new \RuntimeException(
                    "Timed out waiting to mutate GlobalState key \"{$key}\". A mutateGlobalState() "
                    . 'callback is blocking or a worker died holding the lock.'
                );
            }

            $this->pause();
        }
    }

    private function release(string $key, int $ticket): void {
        $row = $this->table->get($key);
        $serving = \is_array($row) ? (int) $row['serving'] : 0;

        // Only advance if this ticket is still the one being served; a lease breaker may have
        // moved on already, and advancing again would skip an innocent waiter.
        if ($serving === $ticket) {
            $this->table->incr($key, 'serving', 1);
        }

        $this->table->set($key, ['lease' => 0]);
    }

    /** Yield to other coroutines while spinning, or sleep when there is no scheduler. */
    private function pause(): void {
        if (class_exists(Coroutine::class) && Coroutine::getCid() > 0) {
            Coroutine::usleep(200);

            return;
        }

        usleep(200);
    }
}
