<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\State;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Table;

/**
 * Cross-process ticket lock on a Table row with `next`, `serving` and `lease` integer columns.
 *
 * Table::incr() is the only cross-process atomic available: OpenSwoole\Lock blocks the whole
 * worker inside a coroutine, and Table has no compare-and-swap. Coroutines of one worker queue
 * on a per-key Channel gate first, so a worker holds at most one ticket and one poller per key.
 *
 * Exclusion holds while every holder finishes within LEASE_MS. A holder that runs longer, or a
 * worker that is next in line but cannot run for that long, is skipped and can overlap the next.
 *
 * @internal
 */
final class TicketLock {
    /** How long a holder may keep the lock, or the queue stand still, before waiters move it on. */
    private const int LEASE_MS = 2000;

    /** Budget for the cross-process wait, counted from taking a ticket. */
    private const int ACQUIRE_TIMEOUT_MS = 5000;

    /**
     * Yield while `serving` moved this recently, park after. Parking on time waited instead idles
     * the lock whenever the next in line sleeps; bench/contention/lock_contention.php shows it.
     */
    private const int PARK_AFTER_STILL_NS = 2_000_000;

    /** Any Coroutine::usleep() under 1000 µs resumes on the next reactor tick, so this is a yield. */
    private const int YIELD_US = 1;

    /** The shortest real coroutine sleep. */
    private const int PARK_US = 1000;

    /** Sleep between polls outside a coroutine while the queue moves. */
    private const int CLI_POLL_US = 200;

    /** How long the ticket of a caller that timed out is watched so its turn can be passed on. */
    private const int ORPHAN_WATCH_MS = 60_000;

    /** @var array<string, Channel> */
    private array $gates = [];

    /** @var array<string, int> hrtime at which the lease of the local gate holder runs out */
    private array $gateLeaseEnds = [];

    /** @var array<string, array<int, int>> per key, ticket of a caller that timed out => hrtime to stop watching it */
    private array $orphans = [];

    /**
     * @param \Closure(string): string $timeoutMessage Builds the exception text for a key
     */
    public function __construct(
        private readonly Table $table,
        private readonly \Closure $timeoutMessage,
    ) {}

    /**
     * Run $critical while holding the lock on $key, whose row must already exist.
     *
     * A coroutine first waits up to LEASE_MS behind earlier callers of its own worker, so a call
     * can take up to LEASE_MS + ACQUIRE_TIMEOUT_MS before it throws.
     *
     * @template T
     *
     * @param callable(): T $critical
     *
     * @return T
     *
     * @throws \RuntimeException if the lock is not taken within ACQUIRE_TIMEOUT_MS of taking a ticket
     */
    public function run(string $key, callable $critical): mixed {
        $gate = $this->enterGate($key);

        try {
            $ticket = $this->acquire($key);
            if ($gate !== null) {
                $this->gateLeaseEnds[$key] = self::nowNs() + self::LEASE_MS * 1_000_000;
            }

            try {
                return $critical();
            } finally {
                $this->release($key, $ticket);
            }
        } finally {
            if ($gate !== null) {
                $this->leaveGate($key, $gate);
            }
        }
    }

    private function enterGate(string $key): ?Channel {
        if (Coroutine::getCid() <= 0) {
            return null;
        }

        // A local holder past its lease is bypassed, as a remote one is broken. That also lets a
        // re-entrant mutate() or a lock-order inversion through.
        $waitS = self::LEASE_MS / 1000;
        if (isset($this->gateLeaseEnds[$key])) {
            $waitS = min($waitS, ($this->gateLeaseEnds[$key] - self::nowNs()) / 1e9);
            if ($waitS <= 0) {
                return null;
            }
        }

        $gate = $this->gates[$key] ??= new Channel(1);
        $queued = !$gate->isEmpty();
        if (!$gate->push(true, $waitS)) {
            return null;
        }

        // pop() resumes this coroutine inside the releasing one; yield so the releaser returns first.
        if ($queued) {
            Coroutine::usleep(self::YIELD_US);
        }

        return $gate;
    }

    private function leaveGate(string $key, Channel $gate): void {
        unset($this->gateLeaseEnds[$key]);
        // pop() lets a queued coroutine push before it returns, so an empty gate has no one waiting.
        $gate->pop();

        if ($gate->isEmpty() && $gate->stats()['producer_num'] === 0 && ($this->gates[$key] ?? null) === $gate) {
            unset($this->gates[$key]);
        }
    }

    private function acquire(string $key): int {
        $ticket = (int) $this->table->incr($key, 'next', 1) - 1;
        $startNs = self::nowNs();
        $seenServing = -1;
        $seenSinceNs = $startNs;
        $brokenAt = -1;

        while (true) {
            $serving = (int) $this->table->get($key, 'serving');

            // >= rather than ==: a breaker may have advanced past this ticket, in which case the
            // lock has already degraded and waiting longer achieves nothing.
            if ($serving >= $ticket) {
                $this->table->set($key, ['lease' => self::nowMs() + self::LEASE_MS]);

                return $ticket;
            }

            $nowNs = self::nowNs();
            if ($serving !== $seenServing) {
                $seenServing = $serving;
                $seenSinceNs = $nowNs;
            }

            // An expired lease means the holder died in its callback. No lease and a queue that
            // stopped moving means the served ticket belongs to a process that died waiting.
            $lease = (int) $this->table->get($key, 'lease');
            $overdue = $lease > 0
                ? self::nowMs() > $lease
                : $nowNs - $seenSinceNs > self::LEASE_MS * 1_000_000;

            // Once per observed turn. Clearing the lease first stops waiters that read after it
            // from breaking the same turn on the lease rule.
            if ($overdue && $brokenAt !== $serving) {
                $brokenAt = $serving;
                $this->table->set($key, ['lease' => 0]);
                $this->table->incr($key, 'serving', 1);

                continue;
            }

            if ($nowNs - $startNs > self::ACQUIRE_TIMEOUT_MS * 1_000_000) {
                $this->orphan($key, $ticket);

                throw new \RuntimeException(($this->timeoutMessage)($key));
            }

            $this->pause($nowNs - $seenSinceNs < self::PARK_AFTER_STILL_NS);
        }
    }

    private function release(string $key, int $ticket): void {
        // Only while this ticket is still served. Clearing the lease after advancing could erase
        // the lease the next holder has just written.
        if ((int) $this->table->get($key, 'serving') === $ticket) {
            $this->table->set($key, ['lease' => 0]);
            $this->table->incr($key, 'serving', 1);
        }
    }

    /**
     * Pass the turn of a caller that timed out on as soon as it comes. Left behind, each such
     * ticket stalls the queue for LEASE_MS, and under load callers time out faster than that.
     */
    private function orphan(string $key, int $ticket): void {
        // Outside a coroutine nothing can watch it, and the stall rule skips it after LEASE_MS.
        if (Coroutine::getCid() <= 0) {
            return;
        }

        $this->orphans[$key][$ticket] = self::nowNs() + self::ORPHAN_WATCH_MS * 1_000_000;
        if (\count($this->orphans[$key]) === 1 && Coroutine::create($this->watchOrphans(...), $key) === false) {
            unset($this->orphans[$key]);
        }
    }

    private function watchOrphans(string $key): void {
        $lastServing = -1;

        while (($this->orphans[$key] ?? []) !== []) {
            $serving = $this->table->get($key, 'serving');
            // A deleted row counts from zero again, so the tickets taken before it mean nothing.
            if (!\is_int($serving) || $serving < $lastServing) {
                break;
            }

            while (isset($this->orphans[$key][$serving])) {
                unset($this->orphans[$key][$serving]);
                $this->release($key, $serving);
                $serving = (int) $this->table->get($key, 'serving');
            }
            $lastServing = $serving;

            $nowNs = self::nowNs();
            foreach ($this->orphans[$key] as $ticket => $untilNs) {
                if ($ticket < $serving || $nowNs > $untilNs) {
                    unset($this->orphans[$key][$ticket]);
                }
            }

            if ($this->orphans[$key] !== []) {
                Coroutine::usleep(self::PARK_US);
            }
        }

        unset($this->orphans[$key]);
    }

    private function pause(bool $moving): void {
        if (Coroutine::getCid() > 0) {
            Coroutine::usleep($moving ? self::YIELD_US : self::PARK_US);

            return;
        }

        usleep($moving ? self::CLI_POLL_US : self::PARK_US);
    }

    private static function nowNs(): int {
        return (int) hrtime(true);
    }

    private static function nowMs(): int {
        return (int) (microtime(true) * 1000);
    }
}
