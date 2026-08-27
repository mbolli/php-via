<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Table;

/**
 * Cross-worker directory of how to rebuild a context.
 *
 * A context object cannot cross a process boundary, but it does not have to: php-via already
 * rebuilds one by re-running its route handler under the original ID. All a worker needs to do
 * that is a flat record — route, params, session — and that record is small enough to live in
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

    /**
     * @param int $maxRows        Concurrent contexts to track across all workers. As with the
     *                            other shared tables this is a floor, not a ceiling.
     * @param int $maxRecordBytes Serialized byte cap per record. Measured real records run
     *                            92-341 bytes (route, params, session, expiry).
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
     * @param array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int} $record
     *
     * @throws \OverflowException if the serialized record exceeds the column, or the table is full
     */
    public function put(string $contextId, array $record): void {
        $serialized = serialize($record);

        if (\strlen($serialized) > $this->maxRecordBytes) {
            throw new \OverflowException(
                "Context record for \"{$contextId}\" exceeds {$this->maxRecordBytes} bytes. "
                . 'Route parameters are the only variable-length part; raise the limit with '
                . 'Config::withContextDirectorySize().'
            );
        }

        $key = self::key($contextId);
        $rowsBefore = \count($this->table);
        $isNew = !$this->table->exists($key);

        $this->table->set($key, ['record' => $serialized, 'expires' => $record['expiresAt']]);

        if ($isNew && \count($this->table) === $rowsBefore) {
            throw new \OverflowException(
                'The shared context directory is full, so contexts can no longer be rebuilt on '
                . 'other workers. Raise the row count with Config::withContextDirectorySize().'
            );
        }
    }

    /**
     * Look up a context record, or null when absent or expired.
     *
     * @return null|array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int}
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

    /**
     * Push an existing entry's expiry forward. No-op when the context is not in the directory.
     *
     * Called from the SSE loop's idle branch, which is the natural heartbeat for "this context
     * is still alive" — the alternative is an entry expiring underneath a connected tab.
     */
    public function touch(string $contextId, int $expiresAt): void {
        $key = self::key($contextId);

        if (!$this->table->exists($key)) {
            return;
        }

        $this->table->set($key, ['expires' => $expiresAt]);
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
     * A malformed record has to mean "cannot revive" — the caller's existing fallback — not a
     * TypeError inside the action path.
     *
     * @return null|array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int}
     */
    private static function decode(string $serialized): ?array {
        $record = unserialize($serialized);

        if (!\is_array($record)
            || !\is_string($record['route'] ?? null)
            || !\is_array($record['params'] ?? null)
            || !\is_int($record['expiresAt'] ?? null)
            || !(($record['sessionId'] ?? null) === null || \is_string($record['sessionId']))) {
            return null;
        }

        $params = [];
        foreach ($record['params'] as $name => $value) {
            $params[(string) $name] = (string) $value;
        }

        return [
            'route' => $record['route'],
            'params' => $params,
            'sessionId' => $record['sessionId'],
            'expiresAt' => $record['expiresAt'],
        ];
    }

    private static function key(string $contextId): string {
        return substr(sha1($contextId), 0, 32);
    }
}
