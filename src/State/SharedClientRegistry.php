<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use Mbolli\PhpVia\Support\IdGenerator;
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
 * Rows carry a last-seen stamp refreshed from the SSE loop. A worker that dies without running
 * its disconnect path would otherwise leave its clients in the list forever; anything not seen
 * within the TTL is dropped on read.
 */
final class SharedClientRegistry {
    private Table $table;

    /**
     * @param int $maxRows    Concurrent SSE clients across all workers. A floor, not a ceiling.
     * @param int $ttlSeconds How long an entry survives without a heartbeat
     */
    public function __construct(int $maxRows = 4096, private int $ttlSeconds = 120) {
        $table = new Table($maxRows);
        $table->column('ctx', Table::TYPE_STRING, 255);
        $table->column('id', Table::TYPE_STRING, 64);
        // 45 characters is the longest IPv6 form, plus room for a port or a zone index.
        $table->column('ip', Table::TYPE_STRING, 64);
        $table->column('at', Table::TYPE_INT, 8);
        $table->column('seen', Table::TYPE_INT, 8);
        $table->create();
        $this->table = $table;
    }

    /**
     * Record a connected client. Silently ignored when the table is full, since losing a row
     * costs an inaccurate count and must not fail the SSE connection itself.
     */
    public function register(string $contextId, string $clientId, string $ip, int $connectedAt): void {
        $now = time();

        $this->table->set(self::key($contextId), [
            'ctx' => $contextId,
            'id' => $clientId,
            'ip' => $ip,
            'at' => $connectedAt,
            'seen' => $now,
        ]);
    }

    public function unregister(string $contextId): void {
        $this->table->del(self::key($contextId));
    }

    /**
     * Refresh a client's last-seen stamp. No-op when it is not registered.
     *
     * Called from the SSE loop's idle branch — the same heartbeat that keeps the context
     * directory alive, and for the same reason.
     */
    public function touch(string $contextId): void {
        $key = self::key($contextId);

        if (!$this->table->exists($key)) {
            return;
        }

        $this->table->set($key, ['seen' => time()]);
    }

    /**
     * Every client connected to any worker, dropping entries that have stopped reporting in.
     *
     * @return array<string, array{id: string, identicon: string, connected_at: int, ip: string, context_id: string}>
     */
    public function all(): array {
        $cutoff = time() - $this->ttlSeconds;
        $clients = [];
        $stale = [];

        foreach ($this->table as $key => $row) {
            if ((int) $row['seen'] < $cutoff) {
                $stale[] = (string) $key;

                continue;
            }

            $contextId = (string) $row['ctx'];
            $clientId = (string) $row['id'];

            $clients[$contextId] = [
                'id' => $clientId,
                // Regenerated rather than stored: deterministic from the ID, and 1.5 KB.
                'identicon' => IdGenerator::generateIdenticon($clientId),
                'connected_at' => (int) $row['at'],
                'ip' => (string) $row['ip'],
                'context_id' => $contextId,
            ];
        }

        foreach ($stale as $key) {
            $this->table->del($key);
        }

        return $clients;
    }

    /** Number of clients currently registered, without building the full list. */
    public function count(): int {
        return \count($this->table);
    }

    private static function key(string $contextId): string {
        return substr(sha1($contextId), 0, 32);
    }
}
