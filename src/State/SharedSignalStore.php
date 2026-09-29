<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use Mbolli\PhpVia\Signal;
use OpenSwoole\Table;

/**
 * Cross-worker storage for the VALUES of scoped (non-TAB) signals.
 *
 * SignalManager holds Signal objects in a plain PHP array, so a ROUTE/SESSION/GLOBAL-scoped
 * signal is shared between the contexts of one worker and no further. Each worker gets its own
 * copy, initialised from the declared default, and they diverge from the first mutation. This
 * store backs the value — and only the value — with an OpenSwoole\Table, which is mmap'd and
 * fork-inherited, so it must be allocated in the master process before $server->start().
 *
 * The Signal object itself stays per-process. Its identity, scope, client-writable flag and
 * dirty-tracking are all per-worker concerns: each worker tracks what IT still owes its own
 * clients.
 *
 * ## Two storage paths
 *
 * Integers get a dedicated `TYPE_INT` column, because `Table::incr()` on it is atomic across
 * processes — measured at 100% retention with 8 processes racing on one row, against 31% for
 * read-modify-write through get()+set(). That makes {@see increment()} race-free by
 * construction, with no lock and no owner worker.
 *
 * Everything else is PHP-serialized into a string column with last-write-wins. That is correct
 * for the mutation shape it actually sees: a value assigned wholesale (a name, a status, a
 * rendered snapshot) has no lost-update problem, because there is nothing to lose. What LWW
 * cannot do is read-modify-write — `setValue($signal->array() + [...])` on two workers at once
 * drops one side. Signals shaped that way need {@see increment()} where they are numeric, and
 * a single writer where they are not.
 */
final class SharedSignalStore {
    /** Row uses the atomic integer column. */
    private const int KIND_INT = 1;

    /** Row uses the serialized string column. */
    private const int KIND_SERIALIZED = 0;

    private Table $table;

    private TicketLock $lock;

    /**
     * @param int $maxRows      Distinct scoped signals to track. See SharedTable for why this is
     *                          a floor rather than a ceiling.
     * @param int $maxValueSize Serialized byte cap for non-integer values. Lazily mapped, so
     *                          raising it costs nothing until large values are actually stored.
     */
    public function __construct(int $maxRows = 1024, private int $maxValueSize = 32768) {
        $table = new Table($maxRows);
        $table->column('kind', Table::TYPE_INT, 1);
        $table->column('n', Table::TYPE_INT, 8);
        $table->column('s', Table::TYPE_STRING, $maxValueSize);
        // Ticket lock for mutate(), carried on the value's own row. A partial Table::set()
        // leaves unlisted columns untouched, so value writes and lock writes do not disturb
        // each other, and both take the same per-row lock inside OpenSwoole.
        $table->column('next', Table::TYPE_INT, 8);
        $table->column('serving', Table::TYPE_INT, 8);
        $table->column('lease', Table::TYPE_INT, 8);
        $table->create();
        $this->table = $table;
        $this->lock = new TicketLock(
            $table,
            static fn (string $key): string => "Timed out waiting to mutate scoped signal (key {$key}). A mutate() callback "
                . 'is blocking or a worker died holding the lock.',
        );
    }

    /**
     * Seed a signal's value if no worker has stored one yet, and return the value in force.
     *
     * A worker mounting a route that another worker already serves must ADOPT the live value,
     * not reset it to the declared default — that is the whole point of a scoped signal. The
     * seed race between two workers starting together is benign: both write the same default.
     */
    public function initialize(string $id, mixed $default): mixed {
        $key = self::key($id);

        if ($this->table->exists($key)) {
            return $this->read($key, $default);
        }

        $this->write($key, $id, $default);

        return $default;
    }

    public function get(string $id, mixed $default = null): mixed {
        return $this->read(self::key($id), $default);
    }

    /**
     * Replace a signal's value (last-write-wins).
     *
     * @throws \OverflowException if a non-integer value exceeds the serialized byte cap
     */
    public function set(string $id, mixed $value): void {
        $this->write(self::key($id), $id, $value);
    }

    /**
     * Add to an integer signal atomically, returning the new value.
     *
     * This is the race-free path: two workers each calling increment(1) always produce +2,
     * where two read-modify-write setValue() calls may produce +1.
     *
     * @throws \LogicException if the signal is not currently holding an integer
     */
    public function increment(string $id, int $by = 1): int {
        $key = self::key($id);
        $row = $this->table->get($key);

        if (\is_array($row) && (int) $row['kind'] !== self::KIND_INT) {
            throw new \LogicException(
                "Signal \"{$id}\" does not hold an integer, so it cannot be incremented atomically. "
                . 'Use setValue() — but note that read-modify-write on a non-integer scoped signal '
                . 'can lose updates when more than one worker writes it.'
            );
        }

        if (!\is_array($row)) {
            // First touch: seed at zero so the increment below is the only mutation.
            $this->table->set($key, ['kind' => self::KIND_INT, 'n' => 0, 's' => '']);
        }

        return (int) $this->table->incr($key, 'n', $by);
    }

    /**
     * Give a scoped signal cross-worker backing, adopting whatever value is already in force.
     */
    public function attachTo(Signal $signal): void {
        $signal->attachSharedStore($this);
    }

    /**
     * Read, transform and write a signal's value as one indivisible step.
     *
     * This is the answer for the mutation shape neither other path covers: read-modify-write on
     * a NON-integer value — appending to a list, updating one key of a map. increment() handles
     * numbers atomically and a wholesale assignment has no lost update to suffer, but
     * `setValue($signal->array() + [...])` is a read and a write with a gap in between, and
     * concurrent workers each write back a result computed from the same stale read. Measured
     * over 4 forked workers appending to one list: 79 of 160 entries survived.
     *
     * $mutator receives the current value and returns the new one. It runs on the calling
     * worker — no closure crosses a process boundary — while a ticket lock on the row keeps
     * every other worker out. Keep it fast and side-effect free: it runs inside the lock, and a
     * callback that blocks holds up every other writer of the same signal. Concurrent callers in
     * one worker queue locally, and one per worker polls the row. Exclusion holds while each holder
     * finishes within 2 s: a holder that runs longer, or a worker that is next in line but cannot
     * run for 2 s, is skipped, and its write can overlap the next.
     *
     * Two things to know:
     *  - The mutator receives null for a signal nothing has written yet, so handle that case.
     *  - Do not mix mutate() and increment() on the SAME signal. increment() deliberately skips
     *    the lock — a single atomic operation needs no help — so a mutate() running beside it
     *    can read, compute and write back over an increment that landed in between. Pick one
     *    per signal.
     *
     * @template T
     *
     * @param callable(mixed): T $mutator
     *
     * @return T the value written
     */
    public function mutate(string $id, callable $mutator): mixed {
        $key = self::key($id);
        $this->ensureRow($key);

        return $this->lock->run($key, function () use ($key, $id, $mutator): mixed {
            $next = $mutator($this->read($key, null));
            $this->write($key, $id, $next);

            return $next;
        });
    }

    public function has(string $id): bool {
        return $this->table->exists(self::key($id));
    }

    /** Number of scoped signals currently tracked. */
    public function count(): int {
        return \count($this->table);
    }

    /**
     * Signal IDs are scope-qualified and can outrun OpenSwoole's 63-character key limit
     * (a custom scope plus a namespaced name), so they are hashed rather than truncated.
     */
    private static function key(string $id): string {
        return substr(sha1($id), 0, 32);
    }

    /**
     * Create the row if absent, so incr() never has to allocate under contention.
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
                "Scoped signal table has no room for key \"{$key}\". "
                . 'Raise the row count with Config::withScopedSignalTableSize().'
            );
        }
    }

    private function read(string $key, mixed $default): mixed {
        $row = $this->table->get($key);

        if (!\is_array($row)) {
            return $default;
        }

        if ((int) $row['kind'] === self::KIND_INT) {
            return (int) $row['n'];
        }

        $serialized = (string) $row['s'];

        return $serialized === '' ? $default : unserialize($serialized);
    }

    private function write(string $key, string $id, mixed $value): void {
        if (\is_int($value)) {
            $this->table->set($key, ['kind' => self::KIND_INT, 'n' => $value, 's' => '']);

            return;
        }

        $serialized = serialize($value);
        if (\strlen($serialized) > $this->maxValueSize) {
            throw new \OverflowException(
                "Scoped signal \"{$id}\" exceeds the shared-value size limit ({$this->maxValueSize} bytes). "
                . 'Raise it with Config::withScopedSignalTableSize(), or keep the large value out of a '
                . 'scoped signal.'
            );
        }

        $this->table->set($key, ['kind' => self::KIND_SERIALIZED, 'n' => 0, 's' => $serialized]);
    }
}
