<?php

declare(strict_types=1);

namespace PhpVia\Website\Support;

use OpenSwoole\Coroutine;

/**
 * SQLite for the examples whose database file every worker opens.
 *
 * SQLite's busy timeout waits inside the C library, which stalls every coroutine of the worker,
 * not only the one that writes. So open() keeps a busy timeout only while it sets the file up,
 * when all workers start at once, and leaves it at 0, as php-via's own SqliteSnapshot does.
 * From then on a locked database fails at once, and retry() waits with coroutine sleeps instead.
 */
final class Sqlite {
    private const int SETUP_BUSY_TIMEOUT_MS = 1000;

    private const int SQLITE_BUSY = 5;

    private const int SQLITE_LOCKED = 6;

    /**
     * Open $path in WAL mode and run $schema, waiting for the locks of other workers meanwhile.
     *
     * @throws \RuntimeException when other workers hold the lock past the setup budget
     */
    public static function open(string $path, string $schema): \SQLite3 {
        $db = new \SQLite3($path);
        $db->enableExceptions(true);
        $db->busyTimeout(self::SETUP_BUSY_TIMEOUT_MS);
        // Two workers switching a fresh file to WAL get SQLITE_BUSY at once, without the busy timeout.
        foreach (['PRAGMA journal_mode=WAL', 'PRAGMA synchronous=NORMAL', $schema] as $sql) {
            if (self::retry(static fn (): bool => $db->exec($sql), self::SETUP_BUSY_TIMEOUT_MS * 5) === null) {
                throw new \RuntimeException("SQLite stayed locked while setting up {$path}.");
            }
        }
        $db->busyTimeout(0);

        return $db;
    }

    /**
     * Run $write, and run it again after a short sleep while another worker holds the lock.
     *
     * Use it for a statement in autocommit mode or for a BEGIN IMMEDIATE: both fail without
     * an effect, so running them again is safe.
     *
     * @template T
     *
     * @param callable(): T $write
     *
     * @return null|T null when the lock is still held after $budgetMs
     */
    public static function retry(callable $write, int $budgetMs = 1000): mixed {
        $deadline = hrtime(true) + $budgetMs * 1_000_000;
        $sleepUs = 1000;

        while (true) {
            try {
                return $write();
            } catch (\SQLite3Exception $e) {
                if (!\in_array($e->getCode(), [self::SQLITE_BUSY, self::SQLITE_LOCKED], true)) {
                    throw $e;
                }
            }

            if (hrtime(true) + $sleepUs * 1000 > $deadline) {
                return null;
            }

            // Outside a coroutine (a script or a test) there is nothing else to run meanwhile.
            Coroutine::getCid() > 0 ? Coroutine::usleep($sleepUs) : usleep($sleepUs);
            $sleepUs = min($sleepUs * 2, 50_000);
        }
    }
}
