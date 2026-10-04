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
 *
 * Each row also holds the tab state of its context (Context::setTabState()) in a column that
 * put() never writes, so a heartbeat rewriting the record keeps it and the row's expiry drops it.
 *
 * And the context's home: the worker process that holds its SSE stream, or before one connects the one that
 * rendered or rebuilt it. Requests of the tab that reach another worker are forwarded there (Http\Forwarder).
 * A home is not released when its context is destroyed: the worker then answers that it does not hold the
 * tab, and the next claim replaces it.
 *
 * With revival off a row holds only the home, with no record and no tab state (putHome()): no worker rebuilds the
 * context, but its requests still reach its worker. Such a row goes when its home destroys the context.
 */
final class SharedContextDirectory {
    /** Lock rows for tab state writes; contexts hash onto them. */
    private const int STATE_STRIPES = 256;

    private Table $table;

    private TicketLock $stateLock;

    private int $pruneBlockedUntilNs = 0;

    /**
     * @param int $maxRows        Concurrent contexts to track across all workers. As with the
     *                            other shared tables this is a floor, not a ceiling.
     * @param int $maxRecordBytes Serialized byte cap per record. Measured real records run
     *                            92-341 bytes (route, params, session, expiry), plus a page
     *                            query of up to 512 bytes.
     * @param int $maxStateBytes  Serialized byte cap of one context's tab state
     */
    public function __construct(int $maxRows = 4096, private int $maxRecordBytes = 1024, private int $maxStateBytes = 1024) {
        $table = new Table($maxRows);
        $table->column('record', Table::TYPE_STRING, $maxRecordBytes);
        $table->column('expires', Table::TYPE_INT, 8);
        $table->column('state', Table::TYPE_STRING, $maxStateBytes);
        // The home's worker id and process id; a process id of 0 means none.
        $table->column('hwid', Table::TYPE_INT, 4);
        $table->column('hpid', Table::TYPE_INT, 4);
        $table->create();
        $this->table = $table;

        // Headroom: rows are placed by hash, and a set() that finds no free slot throws.
        $locks = new Table(self::STATE_STRIPES * 2);
        $locks->column('next', Table::TYPE_INT, 8);
        $locks->column('serving', Table::TYPE_INT, 8);
        $locks->column('lease', Table::TYPE_INT, 8);
        $locks->create();
        for ($stripe = 0; $stripe < self::STATE_STRIPES; ++$stripe) {
            $locks->set((string) $stripe, ['next' => 0, 'serving' => 0, 'lease' => 0]);
        }
        $this->stateLock = new TicketLock(
            $locks,
            static fn (string $stripe): string => "Timed out waiting to write tab state (lock stripe {$stripe}). "
                . 'A worker died holding the lock or its event loop is blocked.',
        );
    }

    /**
     * Store or replace the record for a context.
     *
     * @param array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int, query?: string} $record
     * @param null|array{int, int}                                                                                        $home   worker id and process id that become its home, for a context no other worker knows yet; null keeps the home
     *
     * @throws \OverflowException if the serialized record exceeds the column, or the table is full
     */
    public function put(string $contextId, array $record, ?array $home = null): void {
        $serialized = serialize($record);

        if (\strlen($serialized) > $this->maxRecordBytes) {
            throw new \OverflowException(
                "Context record for \"{$contextId}\" exceeds {$this->maxRecordBytes} bytes. "
                . 'Route parameters and the page query are the variable-length parts; raise the limit with '
                . 'Config::withContextDirectorySize().'
            );
        }

        $row = ['record' => $serialized, 'expires' => $record['expiresAt']];
        if ($home !== null) {
            $row += ['hwid' => $home[0], 'hpid' => $home[1]];
        }

        $this->write(self::key($contextId), $row);
    }

    /**
     * Store or refresh a row that holds only a context's home, for a server with revival off.
     *
     * @param null|array{int, int} $home worker id and process id that become its home; null keeps the home
     *
     * @throws \OverflowException if the table is full
     */
    public function putHome(string $contextId, int $expiresAt, ?array $home = null): void {
        $row = ['record' => '', 'expires' => $expiresAt];
        if ($home !== null) {
            $row += ['hwid' => $home[0], 'hpid' => $home[1]];
        }

        $this->write(self::key($contextId), $row);
    }

    /**
     * Drop a row that putHome() wrote while $home is still its home, under the context's lock.
     *
     * @param array{int, int} $home worker id and process id
     *
     * @throws \RuntimeException if the lock is not taken in time
     */
    public function releaseHome(string $contextId, array $home): void {
        $key = self::key($contextId);
        if (!$this->table->exists($key)) {
            return;
        }

        $this->stateLock->run((string) (crc32($key) % self::STATE_STRIPES), function () use ($key, $home): void {
            $row = $this->table->get($key);
            if (\is_array($row) && (string) $row['record'] === '' && [(int) $row['hwid'], (int) $row['hpid']] === $home) {
                $this->table->del($key);
            }
        });
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

        return (string) $row['record'] === '' ? null : self::decode((string) $row['record']);
    }

    /**
     * The tab state of a context: key bucket => key => serialized value. Null when the context has
     * no live row, so its state lives on the worker that holds it.
     *
     * @return null|array<string, array<string, string>>
     */
    public function getState(string $contextId): ?array {
        $row = $this->table->get(self::key($contextId));
        if (!\is_array($row) || (int) $row['expires'] <= time() || (string) $row['record'] === '') {
            return null;
        }

        return self::decodeState((string) $row['state']);
    }

    /**
     * Change the tab state of a context under its lock, so two workers writing one tab both land.
     *
     * @param \Closure(array<string, array<string, string>>): array<string, array<string, string>> $change
     *
     * @return bool false when the context has no live row
     *
     * @throws \OverflowException if the state would exceed the byte cap; $name names the write in the message
     * @throws \RuntimeException  if the lock is not taken in time
     */
    public function changeState(string $contextId, \Closure $change, string $name): bool {
        $key = self::key($contextId);

        return $this->stateLock->run((string) (crc32($key) % self::STATE_STRIPES), function () use ($key, $change, $name): bool {
            $row = $this->table->get($key);
            if (!\is_array($row) || (int) $row['expires'] <= time() || (string) $row['record'] === '') {
                return false;
            }

            $state = $change(self::decodeState((string) $row['state']));
            // Only the state column, so a record put() in between stays.
            $this->table->set($key, ['state' => $this->encodeState($state, $name)]);

            return true;
        });
    }

    /**
     * Tab state as the state column holds it.
     *
     * @param array<string, array<string, string>> $state
     *
     * @throws \OverflowException if it exceeds the byte cap; $name names the write in the message
     */
    public function encodeState(array $state, string $name): string {
        $serialized = $state === [] ? '' : serialize($state);
        if (\strlen($serialized) > $this->maxStateBytes) {
            throw new \OverflowException(
                'The tab state would take ' . \strlen($serialized) . " bytes after writing {$name}, over the "
                . "{$this->maxStateBytes} bytes per tab that more than one worker share. Raise it with the "
                . '$maxTabStateBytes argument of Config::withContextDirectorySize(), or keep large values out of tab state.'
            );
        }

        return $serialized;
    }

    /**
     * The worker id and process id of a context's home, null when it has none or no live row.
     *
     * @return null|array{int, int}
     */
    public function home(string $contextId): ?array {
        $row = $this->table->get(self::key($contextId));
        if (!\is_array($row) || (int) $row['expires'] <= time() || (int) $row['hpid'] === 0) {
            return null;
        }

        return [(int) $row['hwid'], (int) $row['hpid']];
    }

    /**
     * Make a worker process the home of a context, under the context's lock.
     *
     * With $force it always does, as a stream that connects takes its tab. Otherwise only while the home is
     * none, $expected, the claimant itself, or a process $isLive refuses, so a home that moved meanwhile stays.
     *
     * @param array{int, int}                 $claimant worker id and process id
     * @param null|array{int, int}            $expected the home the claimant saw
     * @param \Closure(array{int, int}): bool $isLive   whether a home's process still runs
     *
     * @return array{0: bool, 1: null|array{int, int}} whether it claimed, and the home it found; [false, null] when the context has no live row
     *
     * @throws \RuntimeException if the lock is not taken in time
     */
    public function claimHome(string $contextId, array $claimant, bool $force, ?array $expected, \Closure $isLive): array {
        $key = self::key($contextId);
        // Without a row there is nothing to claim, and no lock to take for it.
        if (!$this->table->exists($key)) {
            return [false, null];
        }

        return $this->stateLock->run((string) (crc32($key) % self::STATE_STRIPES), function () use ($key, $claimant, $force, $expected, $isLive): array {
            $row = $this->table->get($key);
            if (!\is_array($row) || (int) $row['expires'] <= time()) {
                return [false, null];
            }

            $current = (int) $row['hpid'] === 0 ? null : [(int) $row['hwid'], (int) $row['hpid']];
            if (!$force && $current !== null && $current !== $claimant && $current !== $expected && $isLive($current)) {
                return [false, $current];
            }
            if ($current !== $claimant) {
                $this->table->set($key, ['hwid' => $claimant[0], 'hpid' => $claimant[1]]);
            }

            return [true, $current];
        });
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
     * @return array<string, array<string, string>>
     */
    private static function decodeState(string $serialized): array {
        if ($serialized === '') {
            return [];
        }

        $state = unserialize($serialized, ['allowed_classes' => false]);
        if (!\is_array($state)) {
            return [];
        }

        $valid = [];
        foreach ($state as $bucket => $values) {
            if (!\is_array($values)) {
                continue;
            }
            foreach ($values as $name => $value) {
                if (\is_string($value)) {
                    $valid[(string) $bucket][(string) $name] = $value;
                }
            }
        }

        return $valid;
    }

    /**
     * @param array{record: string, expires: int, hwid?: int, hpid?: int} $row
     *
     * @throws \OverflowException if the table is full
     */
    private function write(string $key, array $row): void {
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
            'The shared context directory is full, so other workers can no longer reach or rebuild new '
            . 'contexts. Raise the row count with Config::withContextDirectorySize().'
        );
    }

    /**
     * Write a row, false when the table has no room for it.
     *
     * @param array{record: string, expires: int, hwid?: int, hpid?: int} $row
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
