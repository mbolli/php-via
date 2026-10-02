<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

/**
 * Durable backing for GlobalState, written behind the in-memory table rather than in front of it.
 *
 * `OpenSwoole\Table` is memory-only: it survives a worker restart, because it is allocated in the
 * master and inherited on fork, but not a server restart. SQLite fixes that, but putting SQLite
 * *in front* of reads would be the wrong trade. A point SELECT is 2.8 us against 0.19 us for a
 * SharedTable read, and every one of those microseconds is non-yielding CPU that stalls the whole
 * worker's event loop, not just the calling coroutine.
 *
 * So reads never touch SQLite. Writes land in the table at full speed and set a dirty flag; a
 * timer on the leader worker drains the dirty set into one batched transaction. That is the same
 * shape Anders Murphy's writer process uses (drain a queue on a fixed tick inside one
 * transaction), implemented with the leader-gated timer php-via already has, so it needs no extra
 * process and changes no read semantics.
 *
 * Measured flush cost (WAL, synchronous=NORMAL):
 *
 *   dirty keys | value size | flush     | per row
 *           10 |      200 B |   11.5 us |  1.15 us
 *          100 |      200 B |  102.5 us |  1.03 us
 *         1000 |      200 B |  799.9 us |  0.80 us
 *         1000 |      4 KB  | 1592.0 us |  1.59 us
 *
 *   wal_checkpoint(TRUNCATE) of a 4.3 MB WAL: 659.6 us
 *
 * The cost of durability is therefore a sub-millisecond stall once per flush interval on one
 * worker, and a bounded loss window: anything written since the last flush is lost if the process
 * dies. That window is the flush interval, and it is the price of not paying 2.8 us on every read.
 */
final class SqliteSnapshot {
    private ?\SQLite3 $db = null;

    private ?\SQLite3Stmt $upsert = null;

    /** Process that opened $db. SQLite forbids carrying a connection across fork(). */
    private int $ownerPid = 0;

    /** Writes since the last checkpoint, used to keep the WAL from growing without bound. */
    private int $writesSinceCheckpoint = 0;

    /**
     * @param string $path            File to persist to. Created if absent.
     * @param int    $checkpointEvery Rows written between WAL checkpoints
     */
    public function __construct(private string $path, private int $checkpointEvery = 5000) {
        // Opened eagerly so a bad path fails at boot rather than on the first flush.
        $this->db();
    }

    /**
     * Every persisted entry, as raw serialized values ready to seed the in-memory table.
     *
     * @return array<string, string>
     */
    public function load(): array {
        $rows = [];
        $result = $this->db()->query('SELECT k, v FROM global_state');

        if ($result === false) {
            return $rows;
        }

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[(string) $row['k']] = (string) $row['v'];
        }

        // Finalise explicitly: this scan is drained to EOF so it would release anyway, but the
        // seed path runs at start-up beside other connections and a cursor left open here would
        // pin the WAL. See tests/Feature/WalCheckpointReadersTest.php.
        $result->finalize();

        return $rows;
    }

    /**
     * Persist a batch of serialized values in one transaction.
     *
     * @param array<string, string> $rows key => serialized value
     *
     * @return int rows written
     */
    public function save(array $rows): int {
        if ($rows === []) {
            return 0;
        }

        // IMMEDIATE, not deferred: this is the single writer, and taking the write lock up front
        // avoids the SQLITE_BUSY_SNAPSHOT upgrade failure that no busy_timeout can absorb.
        //
        // The return value is checked because SQLite3::exec() reports a failed BEGIN by
        // returning false, not by throwing. Ignoring it would let the batch proceed with no
        // transaction open, so each row would commit on its own and a mid-batch failure would
        // leave the snapshot half-applied with nothing to roll back.
        $db = $this->db();
        $upsert = $this->upsert ?? throw new \LogicException('db() always prepares the upsert');

        try {
            $db->exec('BEGIN IMMEDIATE');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'GlobalState snapshot could not begin a write transaction: ' . $e->getMessage()
                . '. Another process is writing this file. It is meant to have a single writer.',
                0,
                $e
            );
        }

        try {
            foreach ($rows as $key => $serialized) {
                $upsert->bindValue(':k', $key, SQLITE3_TEXT);
                $upsert->bindValue(':v', $serialized, SQLITE3_BLOB);
                $upsert->execute();
                $upsert->reset();
            }
            $db->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $db->exec('ROLLBACK');
            } catch (\Throwable) {
                // Nothing useful to do: the batch is already lost and the caller is about to
                // hear about it. Swallowing keeps the original cause as the reported failure.
            }

            throw $e;
        }

        $this->writesSinceCheckpoint += \count($rows);
        if ($this->writesSinceCheckpoint >= $this->checkpointEvery) {
            $this->checkpoint();
        }

        return \count($rows);
    }

    /**
     * Fold the WAL back into the database file.
     *
     * Returns false when a reader blocked it, which is not an error: it means try again later.
     * The health signal for this store is WAL size and blocked-checkpoint count, never
     * SQLITE_BUSY, which is not raised here at all.
     */
    public function checkpoint(): bool {
        $this->writesSinceCheckpoint = 0;

        $result = $this->db()->query('PRAGMA wal_checkpoint(TRUNCATE)');
        if ($result === false) {
            return false;
        }

        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();

        return (int) ($row['busy'] ?? 1) === 0;
    }

    /**
     * Close the connection. The next call opens a fresh one, so close before a fork() and the
     * child gets its own.
     */
    public function close(): void {
        $this->upsert?->close();
        $this->db?->close();
        $this->db = null;
        $this->upsert = null;
    }

    /**
     * The connection, opened on first use in the calling process.
     */
    private function db(): \SQLite3 {
        $pid = (int) getmypid();

        if ($this->db !== null) {
            if ($this->ownerPid !== $pid) {
                throw new \LogicException(
                    "GlobalState snapshot connection opened in pid {$this->ownerPid} used from pid {$pid}; "
                    . 'SQLite connections must not cross fork(), close() it first'
                );
            }

            return $this->db;
        }

        $db = new \SQLite3($this->path);
        // Failures arrive as exceptions rather than PHP warnings plus a false return, which is
        // what SQLite3 does by default. A false return from exec('BEGIN IMMEDIATE') is very easy
        // to miss, and missing it means writing a batch with no transaction open.
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA synchronous=NORMAL');
        // Measured to carry the whole warm-read win; mmap_size is deliberately not set (it
        // doubles cold-connection first-query cost for no further gain), and busy_timeout is
        // deliberately left at 0: on a coroutine loop it converts a blocked checkpoint into a
        // multi-second freeze.
        $db->exec('PRAGMA cache_size=15625');
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('CREATE TABLE IF NOT EXISTS global_state (k TEXT PRIMARY KEY, v BLOB NOT NULL)');

        $upsert = $db->prepare(
            'INSERT INTO global_state (k, v) VALUES (:k, :v) ON CONFLICT(k) DO UPDATE SET v = excluded.v'
        );
        if ($upsert === false) {
            throw new \RuntimeException('GlobalState snapshot could not prepare its upsert statement');
        }

        $this->db = $db;
        $this->upsert = $upsert;
        $this->ownerPid = $pid;

        return $db;
    }
}
