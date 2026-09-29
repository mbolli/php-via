<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\MessageBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use OpenSwoole\Coroutine\Http\Client;

/*
 * onShutdown must run when the server is stopped the way deployments stop it.
 *
 * OpenSwoole owns SIGTERM in server processes, so Process::signal(SIGTERM, ...) returned false in
 * the master and in every worker and the only callers of the shutdown callbacks never ran. Measured
 * before the fix with 2 workers and one open SSE stream:
 *
 *   kill -TERM <master>    no onShutdown, "processor has been registered" x3, scheduler deadlock
 *   kill -INT <master>     no onShutdown, scheduler deadlock
 *   SIGINT to the group    onShutdown ran, then "Uncaught OpenSwoole\ExitException" per worker
 *
 * OpenSwoole's manager keeps the default SIGINT action, so once the worker SIGINT handler stopped
 * calling exit() a Ctrl-C killed the manager and left the workers running under init.
 */

/** @return array{out: string, marker: list<string>, leftover: int} */
function runGracefulShutdownServer(int $workers, string $mode, string $options = ''): array {
    $fixture = dirname(__DIR__) . '/Fixtures/graceful_shutdown_server.php';
    $marker = sys_get_temp_dir() . '/via_shutdown_' . bin2hex(random_bytes(6));
    $log = $marker . '.log';

    try {
        // setsid gives the server its own process group, so INTGRP cannot reach the test runner.
        // Output goes to a file: orphaned workers would hold a pipe open and hang shell_exec().
        shell_exec(
            'timeout 30 setsid --wait ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
            . ' ' . $workers . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($marker) . ' ' . escapeshellarg($options)
            . ' > ' . escapeshellarg($log) . ' 2>&1'
        );
        $out = (string) @file_get_contents($log);

        usleep(200_000);
        // Bracketed so the pattern does not match the shell running pgrep.
        $pattern = '[' . $marker[0] . ']' . substr($marker, 1);
        $leftover = array_filter(explode("\n", trim((string) shell_exec('pgrep -f ' . escapeshellarg($pattern)))));
        foreach ($leftover as $pid) {
            posix_kill((int) $pid, SIGKILL);
        }

        return [
            'out' => $out,
            'marker' => array_values(array_filter(explode("\n", (string) @file_get_contents($marker)))),
            'leftover' => count($leftover),
        ];
    } finally {
        foreach ([$marker, $marker . '.reloaded', $log] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

/** @param array{out: string, marker: list<string>, leftover: int} $r */
function expectCleanStop(array $r, int $workers, int $streams = 1): void {
    $context = 'fixture output: ' . var_export($r['out'], true) . ' marker: ' . var_export($r['marker'], true);

    if ($streams > 0) {
        expect(str_contains($r['out'], 'sse=open') && str_contains($r['out'], 'sse=eof'))->toBeTrue($context);
    }

    $shutdowns = array_values(array_filter($r['marker'], static fn (string $l): bool => str_starts_with($l, 'shutdown ')));
    $shutdownPids = array_map(static fn (string $line): string => explode(' ', $line)[1], $shutdowns);
    expect($shutdownPids)->toHaveCount($workers, $context);
    expect(array_unique($shutdownPids))->toHaveCount($workers, 'onShutdown must run once in each worker');

    foreach ($shutdowns as $line) {
        expect($line)->not->toEndWith('cid=-1', 'onShutdown must run inside a coroutine');
    }

    // The SSE loop left through its normal exit path rather than being cut off.
    expect(array_filter($r['marker'], static fn (string $l): bool => str_starts_with($l, 'disconnect ')))
        ->toHaveCount($streams, $context)
    ;

    foreach (['deadlock', 'ExitException', 'processor has been registered', 'worker exit timeout', 'must be called in the coroutine'] as $needle) {
        expect($r['out'])->not->toContain($needle);
    }

    expect($r['leftover'])->toBe(0, 'no server process may outlive the stop');
}

describe('stopping a real server', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class) || !function_exists('posix_kill')) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client and ext-posix required');
        }
        if (trim((string) shell_exec('command -v setsid')) === '' || trim((string) shell_exec('command -v timeout')) === '') {
            $this->markTestSkipped('setsid and timeout required');
        }
    });

    test('SIGTERM to the master runs onShutdown in every worker', function (int $workers): void {
        expectCleanStop(runGracefulShutdownServer($workers, 'TERM'), $workers);
    })->with([1, 2]);

    test('SIGINT to the master runs onShutdown in every worker', function (): void {
        expectCleanStop(runGracefulShutdownServer(2, 'INT'), 2);
    });

    test('SIGINT to the process group (Ctrl-C) stops cleanly and orphans nothing', function (): void {
        expectCleanStop(runGracefulShutdownServer(2, 'INTGRP'), 2);
    });

    // max_wait_time is counted in whole seconds, so 1 left anywhere from 0 to 1 s for this.
    test('an onShutdown callback that yields for 900 ms completes', function (): void {
        expectCleanStop(runGracefulShutdownServer(2, 'TERM', 'shutdownYieldMs=900'), 2);
    });

    test('onClientDisconnect finishes before onShutdown runs, even when it yields', function (): void {
        $r = runGracefulShutdownServer(1, 'TERM', 'disconnectYieldMs=50');
        expectCleanStop($r, 1);
        expect(explode(' ', $r['marker'][0])[0])->toBe('disconnect', var_export($r['marker'], true));
    });

    test('an idle worker with no timers still runs onShutdown in a coroutine', function (): void {
        expectCleanStop(runGracefulShutdownServer(1, 'IDLE', 'shutdownYieldMs=10'), 1, streams: 0);
    });

    test('SIGUSR1 runs onShutdown in the old workers and the new ones keep serving', function (): void {
        $r = runGracefulShutdownServer(2, 'USR1');
        expect($r['out'])->toContain('served=ok');
        expectCleanStop($r, 4);
    });
});

describe('runWorkerShutdown', function (): void {
    test('runs once, closes streams, clears intervals and disconnects the broker', function (): void {
        $broker = new class implements MessageBroker {
            public int $disconnects = 0;

            public function connect(): void {}

            public function disconnect(): void {
                ++$this->disconnects;
            }

            public function publish(string $scope): void {}

            public function subscribe(callable $handler): void {}

            public function getNodeId(): string {
                return 'shutdown-test';
            }

            public function isConnected(): bool {
                return true;
            }
        };

        $app = createVia((new Config())->withBroker($broker));
        $ctx = new Context('/_/' . bin2hex(random_bytes(6)), '/p', $app);
        $app->contexts[$ctx->getId()] = $ctx;
        $ctx->execScript('console.log(1)');

        $calls = 0;
        $app->onShutdown(static function () use (&$calls): void {
            ++$calls;
        });
        $app->onShutdown(static function (): void {
            throw new RuntimeException('a failing callback must not stop the rest');
        });
        $app->onShutdown(static function () use (&$calls): void {
            ++$calls;
        });

        $shutdown = new ReflectionMethod($app, 'runWorkerShutdown');
        ob_start();
        $shutdown->invoke($app);
        $shutdown->invoke($app);
        $log = (string) ob_get_clean();

        expect($log)->toContain('a failing callback must not stop the rest');

        expect($calls)->toBe(2);
        expect($broker->disconnects)->toBe(1);
        expect($app->isShuttingDown())->toBeTrue();
        expect($ctx->getPatch())->toBeNull('the patch channel must be closed');
        expect((new ReflectionProperty($app, 'serverIntervalIds'))->getValue($app))->toBe([]);
    });
});
