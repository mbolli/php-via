<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Exception;
use OpenSwoole\Table;

/**
 * Shared key-value store backed by OpenSwoole\Table.
 *
 * OpenSwoole\Table is allocated in the master process before $server->start()
 * and is shared across all worker processes via mmap (fork-inherited). This
 * makes it the right primitive for per-machine shared state without any
 * external dependency.
 *
 * Values are PHP-serialized before storage, so any serializable type works.
 *
 * Limits (measured on ext-openswoole 26.2.0):
 *   - Row capacity is fixed at construction time, but it is NOT $maxRows. OpenSwoole rounds the
 *     allocation up (power of two, floor 64) and the usable count runs well past that: 1024 rows
 *     admits ~1776 keys, 4096 admits ~8043. Rejection is per-key-hash and intermittent — at
 *     $maxRows = 1024 the first failure came at insert 1621 yet 1776 succeeded in total — so the
 *     effective ceiling is not a number a caller can plan against. Treat $maxRows as a floor.
 *     There is no eviction: once full, further distinct keys are rejected.
 *   - Maximum serialized byte size of a single value is $maxValueBytes.
 *   - Keys may be at most MAX_KEY_LENGTH characters; longer keys are rejected, not truncated.
 *
 * In VIA_TEST_MODE the OpenSwoole extension is not loaded; a plain PHP array
 * is used as a fallback so unit tests can exercise SharedTable without
 * starting a real server.
 */
final class SharedTable {
    /**
     * OpenSwoole's usable key length is 63, not 64.
     *
     * At 64 it accepts the write but emits "key[...] is too long" as a PHP warning on EVERY
     * write. It does not truncate — two 64-character keys differing only in the final character
     * stay distinct — so the old limit of 64 was log noise on the hot path rather than
     * corruption. Rejecting at 64 turns a per-write warning into one clear exception.
     */
    private const int MAX_KEY_LENGTH = 63;

    /** @var null|Table OpenSwoole shared-memory table (null in test mode) */
    private ?Table $table;

    /** @var array<string, string> Fallback store used in VIA_TEST_MODE */
    private array $fallback = [];

    /** @var array<string, true> Keys written since the last takeDirty(), test-mode only */
    private array $fallbackDirty = [];

    private bool $testMode;

    private int $maxValueBytes;

    public function __construct(int $maxRows = 1024, int $maxValueBytes = 32768, bool $testMode = false) {
        $this->testMode = $testMode;
        $this->maxValueBytes = $maxValueBytes;

        if ($testMode) {
            $this->table = null;

            return;
        }

        $this->table = new Table($maxRows);
        // Single 'value' column holds the serialized PHP value.
        $this->table->column('value', Table::TYPE_STRING, $maxValueBytes);
        // Set on every write, cleared by takeDirty(). Lets the durable tier flush only what
        // changed instead of rewriting the whole table on each tick.
        $this->table->column('dirty', Table::TYPE_INT, 1);
        $this->table->create();
    }

    /**
     * Store a value under the given key.
     *
     * @throws \OverflowException        if the serialized value exceeds the column byte limit,
     *                                   or if the table has no room left for a new key
     * @throws \InvalidArgumentException if the key exceeds MAX_KEY_LENGTH characters
     */
    public function set(string $key, mixed $value): void {
        $key = $this->normalizeKey($key);
        $serialized = serialize($value);

        // Check size limit in all modes — prevents silent data loss in production
        // and makes the constraint visible during development/testing.
        if (\strlen($serialized) > $this->maxValueBytes) {
            throw new \OverflowException(
                "GlobalState value for key \"{$key}\" exceeds SharedTable column size "
                . "({$this->maxValueBytes} bytes). Use Config::withGlobalStateTableSize() to increase the limit."
            );
        }

        if ($this->testMode) {
            $this->fallback[$key] = $serialized;
            $this->fallbackDirty[$key] = true;

            return;
        }

        try {
            $this->table->set($key, ['value' => $serialized, 'dirty' => 1]);
        } catch (Exception $e) {
            // OpenSwoole throws a bare exception once the rows are exhausted, which reaches the
            // caller as a generic 500. Shape it like the value-size guard above so the failure
            // names its own remedy.
            throw new \OverflowException(
                "GlobalState has no room for key \"{$key}\": the shared table is full. "
                . 'Raise the row count with Config::withGlobalStateTableSize(). Note that the '
                . 'usable capacity is not exactly the configured row count — OpenSwoole rounds '
                . 'the allocation and rejects keys by hash, so leave headroom.',
                0,
                $e
            );
        }
    }

    /**
     * Retrieve a value by key, returning $default if not set.
     */
    public function get(string $key, mixed $default = null): mixed {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            if (!isset($this->fallback[$key])) {
                return $default;
            }

            return unserialize($this->fallback[$key]);
        }

        $row = $this->table->get($key);

        if ($row === false || !isset($row['value'])) {
            return $default;
        }

        return unserialize($row['value']);
    }

    /**
     * Delete a key. No-op if the key does not exist.
     */
    public function delete(string $key): void {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            unset($this->fallback[$key]);

            return;
        }

        $this->table->del($key);
    }

    /**
     * Seed a value without marking it dirty.
     *
     * Used when loading the durable snapshot at start-up: those entries came FROM storage, so
     * flushing them straight back would write the whole set again on the first tick.
     */
    public function seed(string $key, string $serialized): void {
        $key = $this->normalizeKey($key);

        if ($this->testMode) {
            $this->fallback[$key] = $serialized;

            return;
        }

        $this->table->set($key, ['value' => $serialized, 'dirty' => 0]);
    }

    /**
     * Take every key written since the last call, clearing the flags as it goes.
     *
     * Racy by construction and deliberately so: a write landing between the read and the clear
     * has its flag reset while its value is already in the batch, so it is persisted once rather
     * than twice. A write landing after the clear stays dirty for the next tick. Neither loses
     * data — the table always holds the authoritative value.
     *
     * @return array<string, string> key => serialized value
     */
    public function takeDirty(): array {
        if ($this->testMode) {
            $dirty = [];
            foreach (array_keys($this->fallbackDirty) as $key) {
                if (isset($this->fallback[$key])) {
                    $dirty[$key] = $this->fallback[$key];
                }
            }
            $this->fallbackDirty = [];

            return $dirty;
        }

        $dirty = [];
        foreach ($this->table as $key => $row) {
            if ((int) ($row['dirty'] ?? 0) === 1) {
                $dirty[(string) $key] = (string) $row['value'];
            }
        }

        foreach (array_keys($dirty) as $key) {
            $this->table->set($key, ['dirty' => 0]);
        }

        return $dirty;
    }

    private function normalizeKey(string $key): string {
        if (\strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException(
                "SharedTable key \"{$key}\" exceeds the maximum of " . self::MAX_KEY_LENGTH . ' characters.'
            );
        }

        return $key;
    }
}
