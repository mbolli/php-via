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
 * and OpenSwoole's manager keeps the default SIGINT action, so on Ctrl-C it died and left the
 * workers running under init once the worker handler stopped exiting on its own.
 */

/** @return array{out: string, marker: list<string>, leftover: int} */
function runGracefulShutdownServer(int $workers, string $mode): array {
    $fixture = dirname(__DIR__) . '/Fixtures/graceful_shutdown_server.php';
    $marker = sys_get_temp_dir() . '/via_shutdown_' . bin2hex(random_bytes(6));
    $log = $marker . '.log';

    try {
        // setsid gives the server its own process group, so INTGRP cannot reach the test runner.
        // Output goes to a file: orphaned workers would hold a pipe open and hang shell_exec().
        shell_exec(
            'timeout 30 setsid --wait ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
            . ' ' . $workers . ' ' . escapeshellarg($mode) . ' ' . escapeshellarg($marker)
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
        @unlink($marker);
        @unlink($log);
    }
}

/** @param array{out: string, marker: list<string>, leftover: int} $r */
function expectCleanStop(array $r, int $workers): void {
    $context = 'fixture output: ' . var_export($r['out'], true) . ' marker: ' . var_export($r['marker'], true);

    expect(str_contains($r['out'], 'sse=open') && str_contains($r['out'], 'sse=eof'))->toBeTrue($context);

    $shutdownPids = array_map(
        static fn (string $line): string => substr($line, strlen('shutdown ')),
        array_values(array_filter($r['marker'], static fn (string $l): bool => str_starts_with($l, 'shutdown '))),
    );
    expect($shutdownPids)->toHaveCount($workers, $context);
    expect(array_unique($shutdownPids))->toHaveCount($workers, 'onShutdown must run once in each worker');

    // The SSE loop left through its normal exit path rather than being cut off.
    expect(array_filter($r['marker'], static fn (string $l): bool => str_starts_with($l, 'disconnect ')))
        ->toHaveCount(1, $context)
    ;

    foreach (['deadlock', 'ExitException', 'processor has been registered', 'worker exit timeout'] as $needle) {
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
