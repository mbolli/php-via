<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Exception;
use OpenSwoole\Table;

/**
 * Session data shared by every worker (Context::sessionData() and friends).
 *
 * Allocated in the master process before the fork, like the other shared stores. One row per
 * session holds all of its keys as one serialized map, so the byte cap is per session and the
 * row count is the number of sessions. Every write and every eviction of a session holds the
 * ticket lock of its stripe, so two workers writing different keys of one session both land, a
 * key keeps its last write, and eviction never deletes a row while a write works on it.
 *
 * The last access time lives in `meta`, the map in `data`: a scan costs in proportion to the row
 * width, and eviction scans every row (4096 rows: 0.6 ms for `meta`, 52 ms with a 32 KB column).
 * The locks live in `locks`, whose rows are created once and never deleted.
 *
 * The leader evicts once a second. A write that finds the table full evicts on its own worker,
 * since more new sessions than the table holds can arrive within one second.
 *
 * @internal
 */
final class SharedSessionStore {
    /** Lock rows; sessions hash onto them, so a session shares its lock with a few others. */
    private const int STRIPES = 1024;

    private Table $meta;

    private Table $data;

    private TicketLock $lock;

    /**
     * @param int $maxRows         Sessions kept before the least recently used are evicted
     * @param int $maxSessionBytes Serialized byte cap for all of one session's data
     */
    public function __construct(private int $maxRows = 4096, private int $maxSessionBytes = 32768) {
        $meta = new Table($maxRows);
        $meta->column('at', Table::TYPE_INT, 8);
        $meta->column('size', Table::TYPE_INT, 8);
        $meta->create();
        $this->meta = $meta;

        $data = new Table($maxRows);
        $data->column('map', Table::TYPE_STRING, $maxSessionBytes);
        $data->create();
        $this->data = $data;

        // Headroom: rows are placed by hash, and a set() that finds no free slot throws.
        $locks = new Table(self::STRIPES * 2);
        $locks->column('next', Table::TYPE_INT, 8);
        $locks->column('serving', Table::TYPE_INT, 8);
        $locks->column('lease', Table::TYPE_INT, 8);
        $locks->create();
        for ($stripe = 0; $stripe < self::STRIPES; ++$stripe) {
            $locks->set((string) $stripe, ['next' => 0, 'serving' => 0, 'lease' => 0]);
        }

        $this->lock = new TicketLock(
            $locks,
            static fn (string $stripe): string => "Timed out waiting to write session data (lock stripe {$stripe}). "
                . 'A worker died holding the lock or its event loop is blocked.',
        );
    }

    public function get(string $sessionId, string $name, mixed $default = null): mixed {
        $key = self::key($sessionId);
        $serialized = $this->data->get($key, 'map');

        if (!\is_string($serialized) || $serialized === '') {
            return $default;
        }

        $this->touch($key);

        return self::decode($serialized)[$name] ?? $default;
    }

    /**
     * @throws \InvalidArgumentException if the value cannot be serialized
     * @throws \OverflowException        if the session's data would exceed the byte cap, or the table is full
     * @throws \RuntimeException         if the lock is not taken in time
     */
    public function set(string $sessionId, string $name, mixed $value): void {
        $this->write($sessionId, $name, static function (array $map) use ($name, $value): array {
            $map[$name] = $value;

            return $map;
        });
    }

    /**
     * Remove one key, or every key when $name is null.
     *
     * @throws \RuntimeException if the lock is not taken in time
     */
    public function clear(string $sessionId, ?string $name = null): void {
        if (!$this->meta->exists(self::key($sessionId))) {
            return;
        }

        $this->write($sessionId, $name ?? '', static function (array $map) use ($name): array {
            if ($name === null) {
                return [];
            }
            unset($map[$name]);

            return $map;
        });
    }

    /**
     * Drop the least recently used sessions once more than $maxRows are held, down to 1% below.
     * Sessions without data go first.
     *
     * @return int sessions removed
     */
    public function evict(): int {
        if (\count($this->meta) <= $this->maxRows) {
            return 0;
        }

        $order = [];
        foreach ($this->meta as $key => $row) {
            $order[(string) $key] = [(int) $row['size'] > 0 ? 1 : 0, (int) $row['at']];
        }
        asort($order);

        $floor = $this->maxRows - max(1, intdiv($this->maxRows, 100));
        $removed = 0;
        foreach ($order as $key => [, $at]) {
            // Counted afresh each time: other workers may be evicting too.
            if (\count($this->meta) <= $floor) {
                break;
            }

            $removed += $this->lock->run(self::stripe((string) $key), function () use ($key, $at): int {
                // Used since the scan, or already evicted by another worker.
                if ($this->meta->get((string) $key, 'at') !== $at) {
                    return 0;
                }

                $this->data->del((string) $key);
                $this->meta->del((string) $key);

                return 1;
            });
        }

        return $removed;
    }

    /** Number of sessions with a row. */
    public function count(): int {
        return \count($this->meta);
    }

    public function capacity(): int {
        return $this->maxRows;
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private function write(string $sessionId, string $name, \Closure $change): void {
        $key = self::key($sessionId);
        $apply = fn (): bool => $this->apply($key, $name, $change);

        if ($this->lock->run(self::stripe($key), $apply)) {
            return;
        }

        $this->evict();
        if (!$this->lock->run(self::stripe($key), $apply)) {
            throw new \OverflowException(self::fullMessage());
        }
    }

    /**
     * Run under the session's lock.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     *
     * @return bool false when the table has no room for a new session
     */
    private function apply(string $key, string $name, \Closure $change): bool {
        $current = $this->data->get($key, 'map');
        $map = $change(\is_string($current) && $current !== '' ? self::decode($current) : []);

        if ($map === []) {
            $this->data->del($key);

            return $this->setMeta($key, 0);
        }

        try {
            $serialized = serialize($map);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "Session data \"{$name}\" cannot be serialized, so it cannot be shared across workers: {$e->getMessage()}",
                0,
                $e,
            );
        }

        $size = \strlen($serialized);
        if ($size > $this->maxSessionBytes) {
            throw new \OverflowException(
                "Session data would take {$size} bytes after writing \"{$name}\", over the shared limit of "
                . "{$this->maxSessionBytes} bytes per session. Raise it with Config::withSessionTableSize(), "
                . 'or keep large values out of session data.'
            );
        }

        // meta first: evict() only finds a data row through its meta row.
        if (!$this->setMeta($key, $size)) {
            return false;
        }

        try {
            $this->data->set($key, ['map' => $serialized]);
        } catch (Exception) {
            // Only a new row can fail to allocate, so the session had no data before.
            $this->meta->set($key, ['size' => 0]);

            return false;
        }

        return true;
    }

    private function setMeta(string $key, int $size): bool {
        try {
            return $this->meta->set($key, ['at' => time(), 'size' => $size]);
        } catch (Exception) {
            return false;
        }
    }

    /** A read keeps the session from eviction; once a second is precise enough for that. */
    private function touch(string $key): void {
        $at = $this->meta->get($key, 'at');
        $now = time();

        if ($at === false || (int) $at === $now) {
            return;
        }

        try {
            $this->meta->set($key, ['at' => $now]);
        } catch (Exception) {
            // Evicted since the read and no room to recreate it: nothing to keep alive.
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $serialized): array {
        $map = unserialize($serialized);

        return \is_array($map) ? $map : [];
    }

    private static function fullMessage(): string {
        return 'The shared session table is full, so this session cannot store data. Raise the row count '
            . 'with Config::withSessionTableSize().';
    }

    /** Session IDs are caller-supplied strings and OpenSwoole keys stop at 63 characters. */
    private static function key(string $sessionId): string {
        return substr(sha1($sessionId), 0, 32);
    }

    private static function stripe(string $key): string {
        return (string) (crc32($key) % self::STRIPES);
    }
}
