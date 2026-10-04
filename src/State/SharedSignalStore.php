<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use Mbolli\PhpVia\Signal;
use OpenSwoole\Exception;
use OpenSwoole\Process;
use OpenSwoole\Table;

/**
 * Cross-worker storage for the VALUES of scoped (non-TAB) signals.
 *
 * SignalManager holds Signal objects in a plain PHP array, so a ROUTE/SESSION/GLOBAL-scoped
 * signal is shared between the contexts of one worker and no further. Each worker gets its own
 * copy, initialised from the declared default, and they diverge from the first mutation. This
 * store backs the value, and only the value, with an OpenSwoole\Table, which is mmap'd and
 * fork-inherited, so it must be allocated in the master process before $server->start().
 *
 * The Signal object itself stays per-process. Its identity, scope, client-writable flag and
 * dirty-tracking are all per-worker concerns: each worker tracks what IT still owes its own
 * clients.
 *
 * ## Two storage paths
 *
 * Integers get a dedicated `TYPE_INT` column, because `Table::incr()` on it is atomic across
 * processes, measured at 100% retention with 8 processes racing on one row, against 31% for
 * read-modify-write through get()+set(). That makes {@see increment()} race-free by
 * construction, with no lock and no owner worker.
 *
 * Everything else is PHP-serialized into a string column with last-write-wins. That is correct
 * for the mutation shape it actually sees: a value assigned wholesale (a name, a status, a
 * rendered snapshot) has no lost-update problem, because there is nothing to lose. What LWW
 * cannot do is read-modify-write: `setValue($signal->array() + [...])` on two workers at once
 * drops one side. Signals shaped that way need {@see increment()} where they are numeric, and
 * a single writer where they are not.
 *
 * ## Read snapshots
 *
 * A broadcast flush renders every context of its scopes, and each render reads the same rows.
 * While a coroutine runs a flush or a fan-out it holds a read epoch ({@see ReadEpochs}), and a
 * Signal read under that epoch keeps the value it loaded, so each row is read once per flush
 * rather than once per context. The epoch is new for every flush and never reused, so a value
 * served in one was read after it began. Code outside a flush (actions, timers, hooks, page
 * loads) has no epoch and reads through on every call.
 */
final class SharedSignalStore {
    /** Row uses the atomic integer column. */
    private const int KIND_INT = 1;

    /** Row uses the serialized string column. */
    private const int KIND_SERIALIZED = 0;

    /** Lock rows that serialise holding, releasing and purging scopes. Never deleted, so no waiter loses its ticket. */
    private const int SCOPE_LOCK_STRIPES = 64;

    /** Width of one holder entry in the `pids` column: a process ID and a comma. */
    private const int HOLDER_WIDTH = 8;

    /** How often a full table is reported, per process. */
    private const int FULL_REPORT_INTERVAL_S = 10;

    private Table $table;

    private TicketLock $lock;

    /** Per scope: the process IDs that hold it, one entry per holder, and how many index rows it has. */
    private Table $scopes;

    /** Per scope and position: the key of one of its value rows. */
    private Table $index;

    private TicketLock $scopeLock;

    /** @var null|\Closure(string): void */
    private ?\Closure $reporter = null;

    private int $fullReportedAt = 0;

    private int $fullDropped = 0;

    private ReadEpochs $readEpochs;

    /**
     * @param int $maxRows      Distinct scoped signals to track. See SharedTable for why this is
     *                          a floor rather than a ceiling.
     * @param int $maxValueSize Serialized byte cap for non-integer values. Lazily mapped, so
     *                          raising it costs nothing until large values are actually stored.
     */
    /**
     * @param int $holderSlots Holders one scope can list: a process per worker, plus processes still draining
     *                         after a reload or dead ones not yet swept
     */
    public function __construct(int $maxRows = 1024, private int $maxValueSize = 32768, private int $holderSlots = 16) {
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
        $this->readEpochs = new ReadEpochs();

        $this->scopes = new Table($maxRows);
        $this->scopes->column('pids', Table::TYPE_STRING, $holderSlots * self::HOLDER_WIDTH);
        $this->scopes->column('n', Table::TYPE_INT, 8);
        $this->scopes->create();

        $this->index = new Table($maxRows);
        $this->index->column('k', Table::TYPE_STRING, 32);
        $this->index->create();

        $locks = new Table(self::SCOPE_LOCK_STRIPES);
        $locks->column('next', Table::TYPE_INT, 8);
        $locks->column('serving', Table::TYPE_INT, 8);
        $locks->column('lease', Table::TYPE_INT, 8);
        $locks->create();
        for ($i = 0; $i < self::SCOPE_LOCK_STRIPES; ++$i) {
            $locks->set((string) $i, ['next' => 0, 'serving' => 0, 'lease' => 0]);
        }
        $this->scopeLock = new TicketLock(
            $locks,
            static fn (string $stripe): string => "Timed out waiting for the scoped signal scope lock (stripe {$stripe}).",
        );

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
     * not reset it to the declared default. That is the whole point of a scoped signal. The
     * seed race between two workers starting together is benign: both write the same default.
     */
    public function initialize(string $id, mixed $default, ?string $scope = null): mixed {
        $key = self::key($id);

        if ($this->table->exists($key)) {
            return $this->read($key, $default);
        }

        if ($this->write($key, $id, $default) && $scope !== null) {
            $this->addToIndex(self::scopeKey($scope), $key);
        }

        return $default;
    }

    /**
     * Report a full table through $reporter, at most every FULL_REPORT_INTERVAL_S seconds.
     *
     * @param \Closure(string): void $reporter
     */
    public function onTableFull(\Closure $reporter): void {
        $this->reporter = $reporter;
    }

    /**
     * Count the calling process as a holder of $scope. Call it before the scope's signals are attached, so a
     * concurrent release on another worker cannot delete the rows they adopt.
     *
     * @return bool false when the scope cannot be tracked: its rows then stay until the server stops
     *
     * @throws \RuntimeException if the scope lock is not taken in time
     */
    public function holdScope(string $scope): bool {
        $key = self::scopeKey($scope);

        return $this->scopeLock->run(self::stripe($key), function () use ($key, $scope): bool {
            $pids = $this->holders($key);
            if (\count($pids) >= $this->holderSlots) {
                $pids = array_values(array_filter($pids, self::isAlive(...)));
            }
            $pids[] = getmypid();

            if (\count($pids) > $this->holderSlots || !self::insert($this->scopes, $key, ['pids' => implode(',', $pids)])) {
                $this->reportFull("scope \"{$scope}\" could not be tracked, so its rows stay until the server stops");

                return false;
            }

            return true;
        });
    }

    /**
     * Drop one hold of the calling process on $scope, and delete the scope's rows when no holder is left.
     *
     * @throws \RuntimeException if the scope lock is not taken in time
     */
    public function releaseScope(string $scope): void {
        $key = self::scopeKey($scope);

        $this->scopeLock->run(self::stripe($key), function () use ($key): void {
            $pids = $this->holders($key);
            $at = array_search(getmypid(), $pids, true);
            if ($at !== false) {
                unset($pids[$at]);
            }
            $this->storeHolders($key, $pids);
        });
    }

    /**
     * Drop the holds of processes that no longer run, deleting the rows of scopes no live process holds. A
     * worker that crashed or was killed never released its scopes.
     *
     * @return int scopes whose rows were deleted
     */
    public function removeDeadHolders(): int {
        /** @var array<int, bool> $alive */
        $alive = [];
        $isAlive = static function (int $pid) use (&$alive): bool {
            return $alive[$pid] ??= self::isAlive($pid);
        };

        $stale = [];
        foreach ($this->scopes as $key => $row) {
            $pids = self::parseHolders((string) $row['pids']);
            if (\count(array_filter($pids, $isAlive)) < \count($pids) || $pids === []) {
                $stale[] = (string) $key;
            }
        }

        $removed = 0;
        foreach ($stale as $key) {
            $removed += $this->scopeLock->run(self::stripe($key), function () use ($key, $isAlive): int {
                if (!$this->scopes->exists($key)) {
                    return 0;
                }

                return $this->storeHolders($key, array_filter($this->holders($key), $isAlive)) ? 1 : 0;
            });
        }

        return $removed;
    }

    /** Number of scopes that hold rows in the store. */
    public function scopeCount(): int {
        return \count($this->scopes);
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
                . 'Use setValue(), but note that read-modify-write on a non-integer scoped signal '
                . 'can lose updates when more than one worker writes it.'
            );
        }

        // First touch: seed at zero so the increment below is the only mutation.
        if (!\is_array($row) && !self::insert($this->table, $key, ['kind' => self::KIND_INT, 'n' => 0, 's' => ''])) {
            $this->reportFull("increment of \"{$id}\" was dropped");

            return $by;
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
     * a NON-integer value: appending to a list, updating one key of a map. increment() handles
     * numbers atomically and a wholesale assignment has no lost update to suffer, but
     * `setValue($signal->array() + [...])` is a read and a write with a gap in between, and
     * concurrent workers each write back a result computed from the same stale read. Measured
     * over 4 forked workers appending to one list: 79 of 160 entries survived.
     *
     * $mutator receives the current value and returns the new one. It runs on the calling
     * worker (no closure crosses a process boundary) while a ticket lock on the row keeps
     * every other worker out. Keep it fast and side-effect free: it runs inside the lock, and a
     * callback that blocks holds up every other writer of the same signal. Concurrent callers in
     * one worker queue locally, and one per worker polls the row. Exclusion holds while each holder
     * finishes within 2 s: a holder that runs longer, or a worker that is next in line but cannot
     * run for 2 s, is skipped, and its write can overlap the next.
     *
     * Two things to know:
     *  - The mutator receives null for a signal nothing has written yet, so handle that case.
     *  - Do not mix mutate() and increment() on the SAME signal. increment() deliberately skips
     *    the lock (a single atomic operation needs no help), so a mutate() running beside it
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
     * The epochs a Signal backed by this store reads under. Via runs its fan-outs on them.
     *
     * @internal
     */
    public function readEpochs(): ReadEpochs {
        return $this->readEpochs;
    }

    /**
     * The calling coroutine's read epoch, or 0 when it holds none and must read through.
     *
     * @internal
     */
    public function readEpoch(): int {
        return $this->readEpochs->current();
    }

    /**
     * Signal IDs are scope-qualified and can outrun OpenSwoole's 63-character key limit
     * (a custom scope plus a namespaced name), so they are hashed rather than truncated.
     */
    private static function key(string $id): string {
        return substr(sha1($id), 0, 32);
    }

    private static function scopeKey(string $scope): string {
        return substr(sha1($scope), 0, 32);
    }

    private static function stripe(string $scopeKey): string {
        return (string) (hexdec(substr($scopeKey, 0, 6)) % self::SCOPE_LOCK_STRIPES);
    }

    /**
     * Table::set() that reports a full table as false. OpenSwoole 26 throws there, older releases return false.
     *
     * @param array<string, int|string> $row
     */
    private static function insert(Table $table, string $key, array $row): bool {
        try {
            return $table->set($key, $row);
        } catch (Exception) {
            return false;
        }
    }

    private static function isAlive(int $pid): bool {
        // Signal 0 only checks. It also fails when the ID now belongs to another user's process.
        return $pid === getmypid() || Process::kill($pid, 0);
    }

    /** @return list<int> */
    private static function parseHolders(string $pids): array {
        return $pids === '' ? [] : array_map(intval(...), explode(',', $pids));
    }

    /** @return list<int> */
    private function holders(string $scopeKey): array {
        $pids = $this->scopes->get($scopeKey, 'pids');

        return \is_string($pids) ? self::parseHolders($pids) : [];
    }

    /**
     * Write a scope's holders, or delete the scope's rows when there are none. Runs under the scope lock.
     *
     * @param array<int> $pids
     *
     * @return bool whether the rows were deleted
     */
    private function storeHolders(string $scopeKey, array $pids): bool {
        if ($pids !== []) {
            $this->scopes->set($scopeKey, ['pids' => implode(',', $pids)]);

            return false;
        }

        $count = (int) $this->scopes->get($scopeKey, 'n');
        for ($i = 0; $i < $count; ++$i) {
            $row = $this->index->get("{$scopeKey}.{$i}", 'k');
            if (\is_string($row)) {
                $this->table->del($row);
                $this->index->del("{$scopeKey}.{$i}");
            }
        }
        $this->scopes->del($scopeKey);

        return true;
    }

    /**
     * List a value row under its scope. Two workers that create the same row at once list it twice, and
     * deleting it twice does no harm.
     */
    private function addToIndex(string $scopeKey, string $key): void {
        // incr() creates a scope row nobody holds when the signal was attached without holdScope(); a sweep finds
        // it with no holders and deletes it.
        // At capacity incr() warns and returns false.
        $position = (int) @$this->scopes->incr($scopeKey, 'n', 1);
        if ($position < 1 || !self::insert($this->index, "{$scopeKey}." . ($position - 1), ['k' => $key])) {
            $this->reportFull('a scoped signal row could not be indexed, so it stays until the server stops');
        }
    }

    private function reportFull(string $what): void {
        ++$this->fullDropped;
        $now = time();
        if ($this->reporter === null || $now - $this->fullReportedAt < self::FULL_REPORT_INTERVAL_S) {
            return;
        }

        $dropped = $this->fullDropped;
        $this->fullDropped = 0;
        $this->fullReportedAt = $now;
        ($this->reporter)(
            "Scoped signal table is full: {$what}"
            . ($dropped > 1 ? \sprintf(' (%d failures since the last report)', $dropped) : '')
            . '. Values of scoped signals stop reaching the other workers until rows free up. Raise the row count '
            . 'with Config::withScopedSignalTableSize().'
        );
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

    private function write(string $key, string $id, mixed $value): bool {
        if (\is_int($value)) {
            $row = ['kind' => self::KIND_INT, 'n' => $value, 's' => ''];
        } else {
            $row = ['kind' => self::KIND_SERIALIZED, 'n' => 0, 's' => $this->serialize($id, $value)];
        }

        if (!self::insert($this->table, $key, $row)) {
            $this->reportFull("write of \"{$id}\" was dropped");

            return false;
        }

        return true;
    }

    private function serialize(string $id, mixed $value): string {
        $serialized = serialize($value);
        if (\strlen($serialized) > $this->maxValueSize) {
            throw new \OverflowException(
                "Scoped signal \"{$id}\" exceeds the shared-value size limit ({$this->maxValueSize} bytes). "
                . 'Raise it with Config::withScopedSignalTableSize(), or keep the large value out of a '
                . 'scoped signal.'
            );
        }

        return $serialized;
    }
}
