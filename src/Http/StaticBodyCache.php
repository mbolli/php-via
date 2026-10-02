<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

/**
 * Static file bodies kept in memory while the file's mtime and size match, within a byte budget.
 *
 * @internal
 */
final class StaticBodyCache {
    /** @var array<string, array{mtime: int, size: int, body: string, final: bool}> by file path */
    private array $entries = [];

    private int $bytes = 0;

    /** When put() last dropped deleted and changed files to make room. */
    private int $sweptAt = 0;

    /**
     * @param int $capacity bytes of bodies kept in total
     * @param int $maxBody  largest body kept
     */
    public function __construct(private int $capacity, private int $maxBody) {}

    /**
     * The entry for this version of the file, or null. An entry for another version is dropped.
     *
     * @return null|array{mtime: int, size: int, body: string, final: bool}
     */
    public function get(string $path, int $mtime, int $size): ?array {
        $entry = $this->entries[$path] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['mtime'] === $mtime && $entry['size'] === $size) {
            return $entry;
        }
        $this->forget($path);

        return null;
    }

    /**
     * Keep a body in place of the file's previous one. False, keeping the previous one, when it is too big or does
     * not fit.
     *
     * @param bool $final false for a stand-in that a better body replaces later
     * @param bool $sweep when it does not fit, first drop files that were deleted or changed, at most once a second
     */
    public function put(string $path, int $mtime, int $size, string $body, bool $final = true, bool $sweep = false): bool {
        if (!$this->fits(\strlen($body), $sweep, $path)) {
            return false;
        }
        $this->forget($path);
        $this->entries[$path] = ['mtime' => $mtime, 'size' => $size, 'body' => $body, 'final' => $final];
        $this->bytes += \strlen($body);

        return true;
    }

    /**
     * Whether a body of $length bytes would be kept, in place of $path's current one.
     *
     * @param bool $sweep see put()
     */
    public function fits(int $length, bool $sweep = false, ?string $path = null): bool {
        if ($length > $this->maxBody) {
            return false;
        }
        $replaced = $path !== null && isset($this->entries[$path]) ? \strlen($this->entries[$path]['body']) : 0;
        if ($sweep && $this->bytes - $replaced + $length > $this->capacity) {
            $this->dropStale();
            $replaced = $path !== null && isset($this->entries[$path]) ? \strlen($this->entries[$path]['body']) : 0;
        }

        return $this->bytes - $replaced + $length <= $this->capacity;
    }

    public function forget(string $path): void {
        if (isset($this->entries[$path])) {
            $this->bytes -= \strlen($this->entries[$path]['body']);
            unset($this->entries[$path]);
        }
    }

    public function bytes(): int {
        return $this->bytes;
    }

    /**
     * @return array<string, array{mtime: int, size: int, body: string, final: bool}>
     */
    public function entries(): array {
        return $this->entries;
    }

    /**
     * Drop entries whose file was deleted or changed, such as the previous builds of a bundle with a content hash in
     * its name. Each entry costs a stat(), so this runs at most once a second.
     */
    private function dropStale(): void {
        $now = time();
        if ($this->sweptAt === $now) {
            return;
        }
        $this->sweptAt = $now;

        $stale = [];
        foreach ($this->entries as $path => $entry) {
            clearstatcache(true, $path);
            if (!is_file($path) || filemtime($path) !== $entry['mtime'] || filesize($path) !== $entry['size']) {
                $stale[] = $path;
            }
        }
        foreach ($stale as $path) {
            $this->forget($path);
        }
    }
}
