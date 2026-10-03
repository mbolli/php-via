<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Exception;
use OpenSwoole\Table;

/**
 * Cross-worker directory of how to rebuild a context.
 *
 * A context object cannot cross a process boundary, but it does not have to: php-via already
 * rebuilds one by re-running its route handler under the original ID. All a worker needs to do
 * that is a flat record (route, params, session), and that record is small enough to live in
 * an OpenSwoole\Table, which is mmap'd and fork-inherited.
 *
 * Without it a context exists only on the worker that served its page. An action landing
 * anywhere else returns HTTP 400 "Invalid context", so action success tracks 1/worker_num.
 *
 * ## Why entries are written at CREATION, not at destruction
 *
 * The revival records this replaces were written by destroyContext(), for a returning tab whose
 * context had been cleaned up. That is a strictly smaller set: a context that is alive on
 * another worker right now has no revival record at all, so sharing the revival records alone
 * would not have fixed cross-worker actions. An entry is therefore written when the context is
 * registered, refreshed while its SSE stream is alive, and left to expire after the revival
 * window once it is gone.
 *
 * Keys are hashed rather than used raw: a context ID is its route plus 18 characters, and
 * OpenSwoole's usable key length is 63, which would cap routes at 45 characters.
 */
final class SharedContextDirectory {
    private Table $table;

    private int $pruneBlockedUntilNs = 0;

    /**
     * @param int $maxRows        Concurrent contexts to track across all workers. As with the
     *                            other shared tables this is a floor, not a ceiling.
     * @param int $maxRecordBytes Serialized byte cap per record. Measured real records run
     *                            92-341 bytes (route, params, session, expiry), plus a page
     *                            query of up to 512 bytes.
     */
    public function __construct(int $maxRows = 4096, private int $maxRecordBytes = 1024) {
        $table = new Table($maxRows);
        $table->column('record', Table::TYPE_STRING, $maxRecordBytes);
        $table->column('expires', Table::TYPE_INT, 8);
        $table->create();
        $this->table = $table;
    }

    /**
     * Store or replace the record for a context.
     *
     * @param array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int, query?: string} $record
     *
     * @throws \OverflowException if the serialized record exceeds the column, or the table is full
     */
    public function put(string $contextId, array $record): void {
        $serialized = serialize($record);

        if (\strlen($serialized) > $this->maxRecordBytes) {
            throw new \OverflowException(
                "Context record for \"{$contextId}\" exceeds {$this->maxRecordBytes} bytes. "
                . 'Route parameters and the page query are the variable-length parts; raise the limit with '
                . 'Config::withContextDirectorySize().'
            );
        }

        $key = self::key($contextId);
        $row = ['record' => $serialized, 'expires' => $record['expiresAt']];

        if ($this->trySet($key, $row)) {
            return;
        }

        // Expired records leave only when pruned, so a full table may just need a sweep. The
        // sweep reads every row, so after one that frees nothing the next waits a second.
        $now = hrtime(true);
        if ($now >= $this->pruneBlockedUntilNs) {
            if ($this->prune() > 0 && $this->trySet($key, $row)) {
                return;
            }
            $this->pruneBlockedUntilNs = $now + 1_000_000_000;
        }

        throw new \OverflowException(
            'The shared context directory is full, so contexts can no longer be rebuilt on '
            . 'other workers. Raise the row count with Config::withContextDirectorySize().'
        );
    }

    /**
     * Look up a context record, or null when absent or expired.
     *
     * @return null|array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int, query?: string}
     */
    public function get(string $contextId): ?array {
        $key = self::key($contextId);
        $row = $this->table->get($key);

        if (!\is_array($row)) {
            return null;
        }

        if ((int) $row['expires'] <= time()) {
            $this->table->del($key);

            return null;
        }

        return self::decode((string) $row['record']);
    }

    public function forget(string $contextId): void {
        $this->table->del(self::key($contextId));
    }

    /** Number of context records currently held. */
    public function count(): int {
        return \count($this->table);
    }

    /**
     * Drop every expired record. Bounded scan, called from the same prune path as the
     * in-process records it replaces.
     */
    public function prune(): int {
        $now = time();
        $expired = [];

        foreach ($this->table as $key => $row) {
            if ((int) $row['expires'] <= $now) {
                $expired[] = (string) $key;
            }
        }

        foreach ($expired as $key) {
            $this->table->del($key);
        }

        return \count($expired);
    }

    /**
     * Turn a stored record back into a usable one, or null if it is not one.
     *
     * The shape is checked rather than asserted: these rows outlive a deploy, so a record
     * written by an older build can still be sitting in shared memory when a new one reads it.
     * A malformed record has to mean "cannot revive" (the caller's existing fallback), not a
     * TypeError inside the action path.
     *
     * @return null|array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int, query?: string}
     */
    private static function decode(string $serialized): ?array {
        $record = unserialize($serialized);

        if (!\is_array($record)
            || !\is_string($record['route'] ?? null)
            || !\is_array($record['params'] ?? null)
            || !\is_int($record['expiresAt'] ?? null)
            || !(($record['sessionId'] ?? null) === null || \is_string($record['sessionId']))
            || !\is_string($record['query'] ?? '')) {
            return null;
        }

        $params = [];
        foreach ($record['params'] as $name => $value) {
            $params[(string) $name] = (string) $value;
        }

        $decoded = [
            'route' => $record['route'],
            'params' => $params,
            'sessionId' => $record['sessionId'],
            'expiresAt' => $record['expiresAt'],
        ];
        if (isset($record['query']) && $record['query'] !== '') {
            $decoded['query'] = $record['query'];
        }

        return $decoded;
    }

    /**
     * Write a row, false when the table has no room for it.
     *
     * @param array{record: string, expires: int} $row
     *
     * @phpstan-impure
     */
    private function trySet(string $key, array $row): bool {
        try {
            if ($this->table->set($key, $row) === false) {
                return false;
            }
        } catch (Exception) {
            // OpenSwoole 26 throws "failed to set key value" on a full table.
            return false;
        }

        return $this->table->exists($key);
    }

    private static function key(string $contextId): string {
        return substr(sha1($contextId), 0, 32);
    }
}
