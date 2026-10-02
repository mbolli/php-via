<?php

declare(strict_types=1);

/*
 * Fixture for SlowConsumerTest: a real OpenSwoole server streaming to a client
 * that connects and never reads, exercising the send_queued_bytes check.
 *
 * Prints iterations=, dropped=, max_park_ms=.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Server;
use OpenSwoole\Timer;

// Derived from the PID rather than fixed: see client_registry_workers.php.
$port = 3850 + (getmypid() % 140);
$server = new Server('127.0.0.1', $port, Server::POOL_MODE);
$server->set([
    'worker_num' => 1,
    'log_level' => 5,
    'send_yield' => true,
    'socket_buffer_size' => 1024 * 1024,
    'hook_flags' => Via::HOOK_FLAGS_DEFAULT,
]);

$threshold = 1024 * 1024;

$server->on('request', static function ($req, $res) use ($server, $threshold): void {
    if (($req->server['request_uri'] ?? '') !== '/sse') {
        $res->end('ok');

        return;
    }

    // Anything thrown in here must still shut the server down, or the fixture
    // hangs forever and takes the test run with it.
    try {
        $res->header('Content-Type', 'text/event-stream');
        $frame = str_repeat('x', 64 * 1024);
        $iterations = 0;
        $dropped = 0;
        $maxParkMs = 0.0;
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            ++$iterations;

            $info = $server->getClientInfo($res->fd) ?: [];
            $queued = (int) ($info['send_queued_bytes'] ?? 0);

            if (SseHandler::shouldDropFrame('elements', $queued, $threshold)) {
                ++$dropped;
                Coroutine::usleep(1000);

                continue;
            }

            $t = microtime(true);
            $ok = $res->write("data: {$iterations} {$frame}\n\n");
            $maxParkMs = max($maxParkMs, (microtime(true) - $t) * 1000);

            if (!$ok) {
                break;
            }
        }

        echo 'iterations=', $iterations, "\n";
        echo 'dropped=', $dropped, "\n";
        echo 'max_park_ms=', (int) round($maxParkMs), "\n";
    } catch (Throwable $e) {
        echo 'fixture_error=', $e->getMessage(), "\n";
    } finally {
        $server->shutdown();
    }
});

$server->on('workerStart', static function ($srv, $id) use ($port): void {
    if ($id !== 0) {
        return;
    }

    // Hard watchdog: the fixture must terminate even if the handler never runs.
    Timer::after(10000, static function () use ($srv): void {
        echo "fixture_error=watchdog\n";
        $srv->shutdown();
    });

    Timer::after(200, static function () use ($port): void {
        $fp = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 3);
        if ($fp === false) {
            return;
        }
        fwrite($fp, "GET /sse HTTP/1.1\r\nHost: x\r\nConnection: keep-alive\r\n\r\n");
        // Deliberately never read; hold the socket open past the server deadline.
        Timer::after(6000, static fn () => fclose($fp));
    });
});

$server->start();
