<?php

declare(strict_types=1);

/*
 * Fixture for SlowConsumerTest: a real OpenSwoole server streaming to a client
 * that connects and never reads, exercising the send_queued_bytes check with
 * php-via's default socket_buffer_size and threshold, as SseHandler runs it.
 *
 * Prints iterations=, dropped=, max_park_ms=.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Server;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$port = FixturePort::pick(3850, 140);
$settings = (new Config())->freeze();
$serverSettings = ['worker_num' => 1, 'log_level' => 5] + Via::serverSettings($settings);
$server = new Server('127.0.0.1', $port, Server::POOL_MODE);
$server->set($serverSettings);

$threshold = SseHandler::dropThreshold($settings->sseMaxQueuedBytes, $serverSettings['socket_buffer_size']);

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
        $backedUp = false;
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            ++$iterations;

            $info = $server->getClientInfo($res->fd) ?: [];
            $queued = (int) ($info['send_queued_bytes'] ?? 0);

            $backedUp = SseHandler::shouldDropFrame('elements', $queued, $threshold, $backedUp);
            if ($backedUp) {
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
