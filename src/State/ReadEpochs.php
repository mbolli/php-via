<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Coroutine;

/**
 * Read epochs of one worker's broadcast fan-outs, per coroutine.
 *
 * A coroutine running a flush or a fan-out holds an epoch, taken before it reads any state, so
 * everything it reads is at least as new as its epoch. Epochs come from one counter and are
 * never reused, so comparing two orders them in time, and next() places another event on the
 * same line. SharedSignalStore reuses a scoped signal's value within one epoch; Via uses the
 * order to keep a frame rendered under an older epoch from being a client's last.
 *
 * @internal
 */
final class ReadEpochs {
    /** Bumped by every renew(): a fan-out looks its own epoch up again only after this moved. */
    public private(set) int $renewals = 0;

    /** @var array<int, int> Coroutine id => epoch of the fan-out it is running */
    private array $open = [];

    private int $last = 0;

    /**
     * Open an epoch for the calling coroutine, or join the one it already holds.
     *
     * Pair every call with end() in a finally block of the same coroutine.
     *
     * @return bool true when the coroutine already held an epoch, which then stays open
     */
    public function begin(): bool {
        $cid = Coroutine::getCid();
        if (isset($this->open[$cid])) {
            return true;
        }

        $this->open[$cid] = ++$this->last;

        return false;
    }

    /**
     * Move the calling coroutine's open epoch to a new one, so everything is read again.
     */
    public function renew(): void {
        $cid = Coroutine::getCid();
        if (isset($this->open[$cid])) {
            $this->open[$cid] = ++$this->last;
            ++$this->renewals;
        }
    }

    /**
     * @param bool $joined what the matching begin() returned
     */
    public function end(bool $joined): void {
        if (!$joined) {
            unset($this->open[Coroutine::getCid()]);
        }
    }

    /**
     * The calling coroutine's epoch, or 0 when it holds none.
     */
    public function current(): int {
        return $this->open === [] ? 0 : ($this->open[Coroutine::getCid()] ?? 0);
    }

    /**
     * A number above every epoch handed out so far, for an event that later epochs must follow.
     */
    public function next(): int {
        return ++$this->last;
    }

    /**
     * Hand out only epochs above $epoch from here on.
     */
    public function skipPast(int $epoch): void {
        $this->last = max($this->last, $epoch);
    }
}
