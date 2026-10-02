<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use OpenSwoole\Table;

/**
 * Per-IP request rate limiting that holds across worker processes.
 *
 * The counters must be shared: Via builds its handlers in the master process, before
 * $server->start() forks the workers, so a plain PHP property gives every worker its own
 * copy-on-write budget and the effective limit becomes `limit * worker_num`. OpenSwoole's
 * default fd-based dispatch spreads one client's connections across workers on its own, so
 * that multiplication is not hypothetical.
 *
 * OpenSwoole\Table is mmap'd and fork-inherited, so a table allocated before start() is one
 * table for every worker. It must therefore be constructed in the master process.
 *
 * ## Algorithm
 *
 * A sliding-window counter, not a timestamp list. Each IP gets one row per window bucket,
 * keyed "<ip>|<bucket>", and the allowance is estimated from the current bucket plus the
 * decaying tail of the previous one:
 *
 *     estimate = prev * (1 - elapsed / window) + curr
 *
 * A timestamp list cannot be shared safely: read-modify-write on a Table row is not atomic
 * across processes and the Table offers no compare-and-swap, so two workers pruning and
 * appending concurrently would lose entries. `Table::incr()` and `decr()` ARE atomic (row
 * spinlock), which is why the counter form is the one that survives sharing.
 *
 * The estimate is the standard approximation: it smooths the fixed-window boundary burst
 * (which would otherwise allow 2x the limit across two adjacent windows) without needing to
 * retain per-request timestamps.
 */
final class RateLimiter {
    /**
     * Columns are tiny; the row count is what governs memory.
     *
     * Measured usable capacity is 1.7-2.0x the requested rows (8192 -> 15,827 keys on
     * ext-openswoole 26), and each active IP holds up to two bucket rows, so this comfortably
     * tracks several thousand concurrent client IPs.
     */
    private const int DEFAULT_MAX_ROWS = 8192;

    /** @var null|Table Shared counters (null when running single-worker) */
    private ?Table $table = null;

    /** @var array<string, int> Per-process counters used when the limiter is not shared */
    private array $local = [];

    /** Unix timestamp of the last sweep of expired buckets */
    private int $lastSweep = 0;

    /** True once an overflow has been reported, so the log is not flooded */
    private bool $overflowLogged = false;

    /** True while the store is known to be full; cleared by the next sweep */
    private bool $full = false;

    /**
     * @param bool $shared  Allocate the shared table. Must be true only in the master process,
     *                      before the workers are forked.
     * @param int  $maxRows Row capacity. Each active IP occupies up to two rows (its current
     *                      and previous window bucket).
     */
    public function __construct(bool $shared = false, int $maxRows = self::DEFAULT_MAX_ROWS) {
        if (!$shared || !class_exists(Table::class)) {
            return;
        }

        $table = new Table($maxRows);
        $table->column('count', Table::TYPE_INT, 8);
        $table->create();
        $this->table = $table;
    }

    /** Whether the counters are shared across workers. */
    public function isShared(): bool {
        return $this->table !== null;
    }

    /**
     * Record a request from $ip and report whether it is within $limit per $window seconds.
     *
     * A denied request is not counted, matching the previous behaviour: a client that keeps
     * hammering does not extend its own lockout.
     *
     * @param int $limit  Requests allowed per window. Zero or less disables limiting.
     * @param int $window Window length in seconds
     */
    public function allow(string $ip, int $limit, int $window): bool {
        if ($limit <= 0 || $window <= 0) {
            return true;
        }

        $now = microtime(true);
        $bucket = (int) floor($now / $window);
        $elapsed = $now - ($bucket * $window);

        $this->sweep($bucket, (int) $now);

        // Count this request first: incr is the only cross-process-atomic operation available,
        // so the increment has to happen before the comparison rather than after it.
        $curr = $this->bump($ip . '|' . $bucket, 1);
        if ($curr === null) {
            return true; // capacity exhausted, see reportOverflow()
        }

        $prev = $this->read($ip . '|' . ($bucket - 1));
        $estimate = ($prev * (1.0 - ($elapsed / $window))) + $curr;

        if ($estimate > $limit) {
            // Undo, so a blocked client's continued hammering does not inflate the window.
            $this->bump($ip . '|' . $bucket, -1);

            return false;
        }

        return true;
    }

    /**
     * Whether the shared table has run out of rows.
     *
     * Reported rather than thrown: an exhausted table means an unusually large number of
     * distinct client IPs, and refusing every request would turn that into an outage. The
     * limiter fails open and says so once.
     */
    public function hasOverflowed(): bool {
        return $this->overflowLogged;
    }

    /**
     * Atomically add $delta to a counter, returning its new value, or null if the store is full.
     */
    private function bump(string $key, int $delta): ?int {
        if ($this->table === null) {
            $next = ($this->local[$key] ?? 0) + $delta;
            $this->local[$key] = $next;

            return $next;
        }

        // Decrement only ever follows a successful increment, so the row is already allocated.
        if ($delta < 0) {
            return (int) @$this->table->decr($key, 'count', -$delta);
        }

        // Only a key that does not exist yet needs a row allocated, and once the store is known
        // to be full every such attempt is doomed until the next sweep frees something. Skipping
        // them matters: at capacity incr() does not throw the way Table::set() does. It emits
        // "unable to allocate memory" as a PHP warning, once per request, under exactly the load
        // that filled the table.
        $isNew = !$this->table->exists($key);
        if ($isNew && $this->full) {
            return null;
        }

        $rowsBefore = \count($this->table);
        $result = @$this->table->incr($key, 'count', $delta);

        // Success is judged by whether a row actually appeared, not by the return value:
        // OpenSwoole's signature says incr() returns an int, but at capacity it returns false.
        // Counting rows is true to the runtime and independent of the declared type.
        if ($isNew && \count($this->table) === $rowsBefore) {
            $this->overflowLogged = true;
            $this->full = true;

            return null;
        }

        return (int) $result;
    }

    private function read(string $key): int {
        if ($this->table === null) {
            return $this->local[$key] ?? 0;
        }

        $row = $this->table->get($key);

        return \is_array($row) ? (int) ($row['count'] ?? 0) : 0;
    }

    /**
     * Drop buckets older than the previous window, at most once a second.
     *
     * Without this the store fills with dead per-IP-per-bucket rows and eventually overflows.
     * The scan is O(rows) but runs at most once a second against a few thousand rows.
     */
    private function sweep(int $bucket, int $nowSeconds): void {
        if ($nowSeconds <= $this->lastSweep) {
            return;
        }
        $this->lastSweep = $nowSeconds;

        $stale = static function (string $key) use ($bucket): bool {
            $sep = strrpos($key, '|');

            return $sep !== false && (int) substr($key, $sep + 1) < $bucket - 1;
        };

        if ($this->table === null) {
            foreach ($this->local as $key => $_) {
                if ($stale($key)) {
                    unset($this->local[$key]);
                }
            }

            return;
        }

        $expired = [];
        foreach ($this->table as $key => $_) {
            if ($stale((string) $key)) {
                $expired[] = (string) $key;
            }
        }
        foreach ($expired as $key) {
            $this->table->del($key);
        }

        // Rows may have been freed: let allocation be attempted again.
        $this->full = false;
    }
}
