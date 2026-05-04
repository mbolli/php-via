<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

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
 * Limits:
 *   - Maximum number of rows is fixed at construction time ($maxRows).
 *   - Maximum serialized byte size of a single value is $maxValueBytes.
 *   - Keys are trimmed to 64 characters (OpenSwoole Table key limit).
 *
 * In VIA_TEST_MODE the OpenSwoole extension is not loaded; a plain PHP array
 * is used as a fallback so unit tests can exercise SharedTable without
 * starting a real server.
 */
final class SharedTable {
    private const int MAX_KEY_LENGTH = 64;

    /** @var null|Table OpenSwoole shared-memory table (null in test mode) */
    private ?Table $table;

    /** @var array<string, string> Fallback store used in VIA_TEST_MODE */
    private array $fallback = [];

    private bool $testMode;

    private int $maxValueBytes;

    public function __construct(int $maxRows = 1024, int $maxValueBytes = 4096, bool $testMode = false) {
        $this->testMode = $testMode;
        $this->maxValueBytes = $maxValueBytes;

        if ($testMode) {
            $this->table = null;

            return;
        }

        $this->table = new Table($maxRows);
        // Single 'value' column holds the serialized PHP value.
        $this->table->column('value', Table::TYPE_STRING, $maxValueBytes);
        $this->table->create();
    }

    /**
     * Store a value under the given key.
     *
     * @throws \OverflowException        if the serialized value exceeds the column byte limit
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

            return;
        }

        $this->table->set($key, ['value' => $serialized]);
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

    private function normalizeKey(string $key): string {
        if (\strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException(
                "SharedTable key \"{$key}\" exceeds the maximum of " . self::MAX_KEY_LENGTH . ' characters.'
            );
        }

        return $key;
    }
}
