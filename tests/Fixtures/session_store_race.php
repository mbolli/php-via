<?php

declare(strict_types=1);

/*
 * Fixture for SharedSessionDataTest: eviction running while forked workers write.
 *
 * argv[1] = "race": a forked writer stores one session in a loop while this process keeps the
 * table over capacity and evicts in a tight loop. Prints "writes=<n> errors=<n> max_ms=<n>".
 *
 * argv[1] = "dead": a forked writer is killed while it holds the lock of a session, as a worker
 * killed by the OOM killer or a reload's max_wait_time would be. The table then goes over
 * capacity with that session the least recently used. Prints "victim=<present|evicted> ms=<n>".
 *
 * argv[2] = seconds the race runs
 */

namespace Mbolli\PhpVia\State {
    // Not time(): inside this namespace that would call itself.
    function time(): int {
        return (int) microtime(true) + (int) ($GLOBALS['clockSkew'] ?? 0);
    }
}

namespace {
    use Mbolli\PhpVia\State\SharedSessionStore;
    use OpenSwoole\Atomic;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    /** serialize() runs inside the lock, so this parks its writer there. */
    final class ParksInsideTheLock {
        /** @return array<never> */
        public function __serialize(): array {
            $GLOBALS['inside']->set(1);
            while (true) {
                usleep(100_000);
            }
        }

        /** @param array<mixed> $data */
        public function __unserialize(array $data): void {}
    }

    $mode = $argv[1] ?? 'race';
    $seconds = (float) ($argv[2] ?? 2);

    if ($mode === 'race') {
        $store = new SharedSessionStore(maxRows: 1);
        $done = new Atomic(0);

        $pid = pcntl_fork();
        if ($pid === 0) {
            $writes = $errors = 0;
            $maxMs = 0.0;
            $until = microtime(true) + $seconds;
            while (microtime(true) < $until) {
                $t = hrtime(true);

                try {
                    $store->set('victim', 'n', $writes);
                } catch (Throwable) {
                    ++$errors;
                }
                $maxMs = max($maxMs, (hrtime(true) - $t) / 1e6);
                ++$writes;
            }
            echo 'writes=', $writes, ' errors=', $errors, ' max_ms=', (int) $maxMs, "\n";
            $done->set(1);
            posix_kill(posix_getpid(), SIGKILL);
        }

        $i = 0;
        while ($done->get() === 0) {
            $store->set('filler' . ($i++ % 2), 'n', 1);
            $store->evict();
        }
        pcntl_waitpid($pid, $status);

        exit(0);
    }

    $store = new SharedSessionStore(maxRows: 8);
    $GLOBALS['inside'] = new Atomic(0);
    $store->set('victim', 'n', 1);

    $pid = pcntl_fork();
    if ($pid === 0) {
        $store->set('victim', 'held', new ParksInsideTheLock());

        exit(0);
    }

    $deadline = microtime(true) + 5;
    while ($GLOBALS['inside']->get() === 0 && microtime(true) < $deadline) {
        usleep(1000);
    }
    posix_kill($pid, SIGKILL);
    pcntl_waitpid($pid, $status);

    $GLOBALS['clockSkew'] = 10;
    for ($i = 0; $i < 20; ++$i) {
        $store->set("filler{$i}", 'n', $i);
    }

    $t = hrtime(true);
    $store->evict();
    $ms = (int) ((hrtime(true) - $t) / 1e6);

    echo 'victim=', $store->get('victim', 'n') === null ? 'evicted' : 'present', ' ms=', $ms, "\n";
}
