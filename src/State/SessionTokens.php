<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Table;

/**
 * The session cookies regenerateSession() issued, and the ones it retired.
 *
 * A session's key is a hash of the first cookie it had, so a session that never rotated needs no row.
 * Rotation adds a row for the new cookie that names the key, and marks the old cookie's row retired
 * after a grace period. A cookie without a row hashes to its own key, so the first cookie's row is
 * what refuses it once retired: it lives as long as the session can hold anything, and goes only
 * when the table is over capacity and the session has no data, no rotation in its grace period and
 * no request for an hour. A retired later cookie's row can go at any time, since its hash names no
 * session.
 *
 * With more than one worker the rows live in a table allocated before the fork, which every worker
 * shares; with one they live in an array.
 *
 * @internal
 */
final class SessionTokens {
    /** How long a retired cookie keeps working, for requests other tabs sent before the new one arrived. */
    public const int GRACE_SECONDS = 10;

    /** A cookie that never rotated. */
    public const string FRESH = 'fresh';

    /** The cookie a rotation issued last. */
    public const string CURRENT = 'current';

    /** A rotated cookie within its grace period. */
    public const string GRACE = 'grace';

    /** A rotated cookie past its grace period, which starts a new session. */
    public const string RETIRED = 'retired';

    /** A session without requests this long may lose its rows, once it has no data. */
    private const int IDLE_SECONDS = 3600;

    /** How often a request refreshes its cookie's last-seen time. */
    private const int SEEN_EVERY = 60;

    private ?Table $table = null;

    /** @var array<string, array{key: string, until: int, seen: int, claimed: int}> the rows without a table */
    private array $rows = [];

    private int $pruneBlockedUntil = 0;

    /**
     * @param int                    $maxRows      Rows kept before pruning starts; a rotated session takes two
     * @param \Closure(string): bool $hasData      Whether a session key holds session data
     * @param int                    $graceSeconds How long a retired cookie keeps working
     * @param null|\Closure(): int   $clock        Unix time, for tests
     */
    public function __construct(
        private int $maxRows,
        private \Closure $hasData,
        private int $graceSeconds = self::GRACE_SECONDS,
        private ?\Closure $clock = null,
    ) {}

    /**
     * The session key a cookie value names when it never rotated.
     */
    public static function key(string $token): string {
        return substr(hash('sha256', 'via.session|' . $token), 0, 32);
    }

    /**
     * Keep the rows in a table, which the workers forked afterwards share.
     */
    public function share(): void {
        if ($this->table !== null) {
            return;
        }

        $table = new Table($this->maxRows);
        $table->column('key', Table::TYPE_STRING, 32);
        $table->column('until', Table::TYPE_INT, 8);
        $table->column('seen', Table::TYPE_INT, 8);
        $table->column('claimed', Table::TYPE_INT, 8);
        $table->create();
        $this->table = $table;
    }

    /**
     * The session a cookie belongs to and the state of the cookie.
     *
     * @return array{string, self::CURRENT|self::FRESH|self::GRACE|self::RETIRED}
     */
    public function lookup(string $token): array {
        $hash = self::key($token);
        $row = $this->get($hash);
        // A claim creates a row with an empty key, which the rotation fills in right after.
        if ($row === null || $row['key'] === '') {
            return [$hash, self::FRESH];
        }

        $now = $this->now();
        if ($row['until'] === 0) {
            if ($now - $row['seen'] >= self::SEEN_EVERY) {
                $this->set($hash, ['seen' => $now]);
            }

            return [$row['key'], self::CURRENT];
        }

        return [$row['key'], $row['until'] > $now ? self::GRACE : self::RETIRED];
    }

    /**
     * Issue a new cookie for the session of $token and retire $token after the grace period.
     *
     * Only a fresh or current cookie rotates, and only once: a second rotation of the same cookie,
     * on any worker, gets null and leaves the cookie to the first.
     *
     * @throws \OverflowException when the table has no room for the new cookie
     */
    public function rotate(string $token): ?string {
        [$key, $state] = $this->lookup($token);
        if ($state !== self::FRESH && $state !== self::CURRENT) {
            return null;
        }

        $hash = self::key($token);
        $this->reserve();
        $claimed = $this->claim($hash);
        if ($claimed !== 1) {
            return null;
        }

        $new = bin2hex(random_bytes(16));
        $now = $this->now();
        // The new cookie resolves before the old one starts its grace period.
        if (!$this->set(self::key($new), ['key' => $key, 'until' => 0, 'seen' => $now, 'claimed' => 0])) {
            if ($state === self::FRESH) {
                $this->del($hash);
            } else {
                $this->set($hash, ['claimed' => 0]);
            }

            throw new \OverflowException(self::fullMessage());
        }
        $this->set($hash, ['key' => $key, 'until' => $now + $this->graceSeconds, 'seen' => $now]);

        return $new;
    }

    /**
     * Make room for a rotation, or throw when the table is full of sessions that need their rows.
     *
     * @throws \OverflowException
     */
    public function reserve(): void {
        if ($this->count() < $this->maxRows) {
            return;
        }

        // A prune reads every row, so after one that frees too little the next waits a second.
        $now = $this->now();
        if ($now >= $this->pruneBlockedUntil) {
            $this->prune();
            if ($this->count() < $this->maxRows) {
                return;
            }
            $this->pruneBlockedUntil = $now + 1;
        }

        throw new \OverflowException(self::fullMessage());
    }

    /**
     * Drop the rows of retired later cookies, and the rows of sessions that can hold nothing any more.
     *
     * @return int rows removed
     */
    public function prune(): int {
        $now = $this->now();

        /** @var array<string, array{rows: list<string>, live: bool}> $sessions */
        $sessions = [];
        $drop = [];
        foreach ($this->table ?? $this->rows as $hash => $row) {
            $hash = (string) $hash;
            $key = (string) $row['key'];
            $until = (int) $row['until'];
            if ($key === '' || ($until > 0 && $until <= $now && $hash !== $key)) {
                $drop[] = $hash;

                continue;
            }

            $sessions[$key] ??= ['rows' => [], 'live' => false];
            $sessions[$key]['rows'][] = $hash;
            $sessions[$key]['live'] = $sessions[$key]['live'] || $until > $now || (int) $row['seen'] > $now - self::IDLE_SECONDS;
        }

        foreach ($sessions as $key => $session) {
            if (!$session['live'] && !($this->hasData)($key)) {
                array_push($drop, ...$session['rows']);
            }
        }

        foreach ($drop as $hash) {
            $this->del($hash);
        }

        return \count($drop);
    }

    public function count(): int {
        return $this->table !== null ? \count($this->table) : \count($this->rows);
    }

    public function capacity(): int {
        return $this->maxRows;
    }

    /** @return null|array{key: string, until: int, seen: int, claimed: int} */
    private function get(string $hash): ?array {
        if ($this->table === null) {
            return $this->rows[$hash] ?? null;
        }

        $row = $this->table->get($hash);

        return \is_array($row) ? ['key' => (string) $row['key'], 'until' => (int) $row['until'], 'seen' => (int) $row['seen'], 'claimed' => (int) $row['claimed']] : null;
    }

    /**
     * Write the columns in $values, creating the row when absent.
     *
     * @param array{key?: string, until?: int, seen?: int, claimed?: int} $values
     */
    private function set(string $hash, array $values): bool {
        if ($this->table === null) {
            $this->rows[$hash] = $values + ($this->rows[$hash] ?? ['key' => '', 'until' => 0, 'seen' => 0, 'claimed' => 0]);

            return true;
        }

        return @$this->table->set($hash, $values);
    }

    private function del(string $hash): void {
        if ($this->table === null) {
            unset($this->rows[$hash]);

            return;
        }

        $this->table->del($hash);
    }

    /**
     * Count a claim on a cookie's rotation, atomically across workers, and return the count.
     *
     * @throws \OverflowException when the table has no room for the row
     */
    private function claim(string $hash): int {
        if ($this->table === null) {
            $this->set($hash, ['claimed' => ($this->rows[$hash]['claimed'] ?? 0) + 1]);

            return $this->rows[$hash]['claimed'];
        }

        // At capacity incr() warns and fails rather than throwing, so check that the row is there.
        $claimed = @$this->table->incr($hash, 'claimed', 1);
        if (!$this->table->exists($hash)) {
            throw new \OverflowException(self::fullMessage());
        }

        return (int) $claimed;
    }

    private function now(): int {
        return $this->clock !== null ? ($this->clock)() : time();
    }

    private static function fullMessage(): string {
        return 'The session rotation table is full, so this session keeps its cookie. Raise the row count '
            . 'with Config::withSessionTableSize(), which sizes it at four rows per session.';
    }
}
