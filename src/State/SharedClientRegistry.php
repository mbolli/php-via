<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use Mbolli\PhpVia\Support\IdGenerator;
use OpenSwoole\Atomic\Long;
use OpenSwoole\Exception;
use OpenSwoole\Process;
use OpenSwoole\Table;

/**
 * Cross-worker registry of connected SSE clients.
 *
 * Application::$clients was a plain array written when a stream connects, so each worker saw
 * only the streams it happened to serve. "N users online" showed a random fraction, and a
 * worker that had served none showed zero. Measured with 6 connections held open, one count
 * per probed worker: 1 worker gave 6,6,6,6,6,6,6,6; 4 workers gave 2,1,2,2,2,1,1,1; 8 workers
 * gave 1,1,1,1,1,1,0,0.
 *
 * The identicon is deliberately NOT stored. It is a 1,550-byte SVG derived deterministically
 * from the client ID, so storing it would multiply the row size by five to hold something any
 * worker can regenerate.
 *
 * A row belongs to the worker process that wrote it: its key includes the worker ID and the
 * process ID, so no process can overwrite or delete another's row. The owner removes it when
 * the stream ends. A tab that reconnected to another worker before its old stream ended has a
 * row on each for that moment, and all() lists it once. Reads never write. A process that dies
 * without running its disconnect path leaves its rows until OpenSwoole starts the next process
 * under the same worker ID, whose claimWorker() removes them, and removeDeadProcesses() catches
 * whatever that misses.
 *
 * Every change bumps a version shared by all workers. Each worker keeps the list it built last
 * and returns it until the version moves, then rebuilds it, reusing the identicons it already made.
 *
 * A row also holds the scopes a broadcast reaches the tab through, for Via::countClients(): as many
 * whole scopes as fit in SCOPES_BYTES.
 */
final class SharedClientRegistry {
    /** Room for a client's scopes, separated by newlines. */
    public const int SCOPES_BYTES = 512;

    /** Most scans a removal makes while other workers keep writing to the table. */
    private const int MAX_REMOVAL_SCANS = 5;

    private Table $table;

    /** Bumped after every change to the table, by any worker. */
    private Long $version;

    /** Worker ID this process registers under; set by claimWorker(). */
    private int $workerId = 0;

    /** @var array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}> */
    private array $snapshot = [];

    /** @var array<string, array<string, true>> Scope => the context ids of the snapshot in it */
    private array $scopeIndex = [];

    /** Version $snapshot was read at; -1 before the first read. */
    private int $snapshotVersion = -1;

    /** Read epoch that reuses $snapshot without checking the version; 0 for none. */
    private int $pinnedEpoch = 0;

    /**
     * Create before the server forks, so every worker shares the table and the version.
     *
     * @param int $maxRows Concurrent SSE clients across all workers. A floor, not a ceiling.
     */
    public function __construct(int $maxRows = 4096) {
        $table = new Table($maxRows);
        $table->column('ctx', Table::TYPE_STRING, 255);
        $table->column('id', Table::TYPE_STRING, 64);
        // 45 characters is the longest IPv6 form, plus room for a port or a zone index.
        $table->column('ip', Table::TYPE_STRING, 64);
        $table->column('at', Table::TYPE_INT, 8);
        $table->column('wid', Table::TYPE_INT, 8);
        $table->column('pid', Table::TYPE_INT, 8);
        $table->column('scopes', Table::TYPE_STRING, self::SCOPES_BYTES);
        $table->create();
        $this->table = $table;
        $this->version = new Long(0);
    }

    /**
     * Bind this process to a worker ID and remove the rows an earlier process with that ID left.
     *
     * OpenSwoole starts a worker under the same ID after a crash, a reload or a max_request
     * recycle. With reload_async it starts the new process while the old one still drains its
     * streams, so this removes the rows of a live process too; the old process's own unregister()
     * calls then find nothing, and they cannot touch the new process's rows.
     *
     * @return int number of rows removed
     */
    public function claimWorker(int $workerId): int {
        $this->workerId = $workerId;
        $pid = getmypid();

        return $this->removeRows(static fn (array $row): bool => $row['wid'] === $workerId && $row['pid'] !== $pid);
    }

    /**
     * Remove the rows of every process that no longer exists, whatever its worker ID.
     *
     * The safety net for rows claimWorker() did not remove: a stopping process can register a
     * stream after its successor claimed, and then be killed at max_wait_time.
     *
     * @return int number of rows removed
     */
    public function removeDeadProcesses(): int {
        /** @var array<int, bool> $alive */
        $alive = [];

        return $this->removeRows(static function (array $row) use (&$alive): bool {
            $pid = (int) $row['pid'];

            // Signal 0 only checks. It also fails when the ID now belongs to another user's process.
            return !($alive[$pid] ??= Process::kill($pid, 0));
        });
    }

    /**
     * Record a connected client, owned by this process.
     *
     * @param list<string> $scopes the scopes a broadcast reaches it through; those past SCOPES_BYTES are left out
     *
     * @return bool false when the table is full, which costs an inaccurate list and must not fail the SSE connection
     */
    public function register(string $contextId, string $clientId, string $ip, int $connectedAt, array $scopes = []): bool {
        try {
            $this->table->set(self::key($contextId, $this->workerId, getmypid()), [
                'ctx' => $contextId,
                'id' => $clientId,
                'ip' => $ip,
                'at' => $connectedAt,
                'wid' => $this->workerId,
                'pid' => getmypid(),
                'scopes' => self::packScopes($scopes),
            ]);
        } catch (Exception) {
            return false;
        }
        $this->changed();

        return true;
    }

    /**
     * Replace the scopes of the client this process registered for a context.
     *
     * @param list<string> $scopes as for register()
     */
    public function setScopes(string $contextId, array $scopes): void {
        $key = self::key($contextId, $this->workerId, getmypid());
        if (!$this->table->exists($key)) {
            return;
        }
        $this->table->set($key, ['scopes' => self::packScopes($scopes)]);
        $this->changed();
    }

    /**
     * The clients of all() by scope, from the same read.
     *
     * @param int $readEpoch as for all()
     *
     * @return array<string, array<string, true>> scope => context ids
     */
    public function scopeIndex(int $readEpoch = 0): array {
        $this->all($readEpoch);

        return $this->scopeIndex;
    }

    /**
     * Remove the row this process registered for a client. A row another process wrote for the
     * same context, as when the tab reconnected to another worker before this stream ended, stays.
     */
    public function unregister(string $contextId): void {
        if ($this->table->del(self::key($contextId, $this->workerId, getmypid()))) {
            $this->changed();
        }
    }

    /**
     * Every client connected to any worker.
     *
     * The array is shared between calls: it is rebuilt only after a client registers or leaves on
     * any worker. Calls with the same non-zero read epoch get the same array without checking for
     * changes, so a fan-out renders one list. A change made by this process ends that, and so does
     * a call with another epoch, or none, in between.
     *
     * @param int $readEpoch the caller's read epoch ({@see ReadEpochs}), or 0 outside a fan-out
     *
     * @return array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}>
     */
    public function all(int $readEpoch = 0): array {
        if ($readEpoch !== 0 && $readEpoch === $this->pinnedEpoch) {
            return $this->snapshot;
        }

        // Read before scanning: a change landing mid-scan leaves the version ahead, so the next call rebuilds.
        $version = $this->version->get();
        if ($version !== $this->snapshotVersion) {
            $this->snapshot = $this->scan();
            $this->snapshotVersion = $version;
        }
        $this->pinnedEpoch = $readEpoch;

        return $this->snapshot;
    }

    /**
     * Rows in the table. A tab that reconnected to another worker counts twice until its old
     * stream ends.
     */
    public function count(): int {
        return \count($this->table);
    }

    /**
     * Delete the rows $isStale picks, scanning again while other workers write to the table.
     *
     * Only for rows no live process writes any more: the key embeds the writer's process ID, so a
     * row picked here cannot be replaced by a live one between the scan and the delete.
     *
     * @param \Closure(array<string, mixed>): bool $isStale
     *
     * @return int number of rows removed
     */
    private function removeRows(\Closure $isStale): int {
        $removed = 0;

        for ($scan = 1; $scan <= self::MAX_REMOVAL_SCANS; ++$scan) {
            $versionBefore = $this->version->get();
            $stale = [];
            foreach ($this->table as $key => $row) {
                if ($isStale($row)) {
                    $stale[] = (string) $key;
                }
            }
            // A delete by another worker in a hash chain the scan is walking makes it skip a row.
            $complete = $this->version->get() === $versionBefore;

            foreach ($stale as $key) {
                if ($this->table->del($key)) {
                    ++$removed;
                }
            }

            if ($complete) {
                break;
            }
        }

        if ($removed > 0) {
            $this->changed();
        }

        return $removed;
    }

    /**
     * @return array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}>
     */
    private function scan(): array {
        $previous = $this->snapshot;
        $clients = [];
        $scopes = [];

        foreach ($this->table as $row) {
            $contextId = (string) $row['ctx'];
            $connectedAt = (int) $row['at'];
            // The same tab on two workers, while its old stream ends: list the newer connection.
            if (isset($clients[$contextId]) && $clients[$contextId]['connected_at'] > $connectedAt) {
                continue;
            }
            $scopes[$contextId] = (string) $row['scopes'];

            $clientId = (string) $row['id'];
            $known = $previous[$contextId] ?? null;

            $clients[$contextId] = [
                'id' => $clientId,
                // Regenerated rather than stored: deterministic from the ID, and 1.5 KB.
                'identicon' => $known !== null && $known['id'] === $clientId
                    ? $known['identicon']
                    : IdGenerator::generateIdenticon($clientId),
                'connected_at' => $connectedAt,
                'ip' => (string) $row['ip'],
                'context_id' => $contextId,
            ];
        }

        $this->scopeIndex = [];
        foreach ($scopes as $contextId => $packed) {
            foreach ($packed === '' ? [] : explode("\n", $packed) as $scope) {
                $this->scopeIndex[$scope][$contextId] = true;
            }
        }

        return $clients;
    }

    /**
     * The scopes joined by newlines, as many whole ones as fit in SCOPES_BYTES.
     *
     * @param list<string> $scopes
     */
    private static function packScopes(array $scopes): string {
        $packed = '';
        foreach ($scopes as $scope) {
            $next = $packed === '' ? $scope : $packed . "\n" . $scope;
            if (\strlen($next) <= self::SCOPES_BYTES) {
                $packed = $next;
            }
        }

        return $packed;
    }

    /** After a write by this process: tell every worker, and stop reusing a pinned list. */
    private function changed(): void {
        $this->version->add(1);
        $this->pinnedEpoch = 0;
    }

    private static function key(string $contextId, int $workerId, int $pid): string {
        return substr(sha1("{$workerId}:{$pid}:{$contextId}"), 0, 32);
    }
}
