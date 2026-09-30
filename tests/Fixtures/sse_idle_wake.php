<?php

declare(strict_types=1);

/*
 * Fixture for SseIdleWakeTest: a real server whose SSE streams sit idle unless their page sends
 * patches on a timer.
 *
 * argv[1] = disconnect: open argv[3] idle streams, close the first, report how long onClientDisconnect
 *           took and how many clients each probed worker lists afterwards
 *           keepalive: open an idle and a busy stream with a 200 ms keep-alive, read both for 700 ms
 *           and count the keep-alive comments; argv[3] = br asks for Brotli (the server runs h2c)
 * argv[2] = worker count
 * argv[3] = disconnect: stream count
 * argv[4] = disconnect: dispatch_mode, optional. With 1, 3 or 7 OpenSwoole sends no close event; the keep-alive
 *           is then off, and a wake of every stream after the close stands in for the idle backstop
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The SSE loop under test needs the Channel-backed PatchManager, not the test-mode array.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;

$mode = (string) ($argv[1] ?? 'disconnect');
$workers = (int) ($argv[2] ?? 1);
$arg = (string) ($argv[3] ?? '');
$dispatchMode = isset($argv[4]) ? (int) $argv[4] : null;
$brotli = $mode === 'keepalive' && $arg === 'br';
$marker = sys_get_temp_dir() . '/via_sse_idle_wake_' . getmypid();
@unlink($marker);

// enable_reuse_port would let a second server share a busy port without an error.
for ($i = 0; $i < 150; ++$i) {
    $port = 4400 + ((getmypid() + $i) % 150);
    $probe = @stream_socket_server("tcp://127.0.0.1:{$port}");
    if ($probe !== false) {
        fclose($probe);

        break;
    }
}

$config = (new Config())
    ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')
    ->withWorkerNum($workers)->withSseKeepAliveMs($mode === 'keepalive' ? 200 : ($dispatchMode !== null ? 0 : 15_000))
;
if ($dispatchMode !== null) {
    $config = $config->withSwooleSettings(['dispatch_mode' => $dispatchMode]);
}
if ($workers > 1) {
    $config = $config->withBroker(new SwooleBroker());
}
if ($brotli) {
    $config = $config->withBrotli()->withH2c();
}
$app = new Via($config);

$app->page('/probe', function (Context $c): void {
    $c->view(fn (): string => '<div id="v">CTX:' . $c->getId() . ':END</div>');
});

$app->page('/busy', function (Context $c): void {
    $c->view(fn (): string => '<div id="v">CTX:' . $c->getId() . ':END</div>');
    $c->setInterval(static fn () => $c->execScript('void 0'), 50);
});

// What THIS worker sees: the client list, and whether it runs the directory heartbeat timer.
$app->page('/count', function (Context $c) use ($app, $config): void {
    $c->view(static function () use ($app, $config): string {
        $heartbeat = 0;
        foreach (Timer::list() as $id) {
            if ((Timer::info($id)['interval'] ?? 0) === Via::sseHeartbeatIntervalMs($config)) {
                $heartbeat = 1;
            }
        }

        return 'COUNT:' . count($app->getClients()) . ':PID:' . getmypid() . ':HB:' . $heartbeat . ':END';
    });
});

$app->page('/wake', function (Context $c) use ($app): void {
    foreach ($app->contexts as $context) {
        $context->getPatchManager()->wakeConsumers();
    }
    $c->view(static fn (): string => 'woken');
});

$app->onClientDisconnect(static function () use ($marker): void {
    file_put_contents($marker, sprintf("disconnect %.6f\n", microtime(true)), FILE_APPEND | LOCK_EX);
});

/** @return array{0: string, 1: string} context ID and session cookie of a fresh page load */
function loadPage(int $port, string $path): array {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 5]);
    $client->get($path);
    preg_match('/CTX:(.+?):END/', (string) $client->body, $m);
    $cookie = '';
    foreach ((array) ($client->set_cookie_headers ?? []) as $raw) {
        $cookie .= explode(';', (string) $raw)[0] . '; ';
    }
    $client->close();

    return [$m[1] ?? '', $cookie];
}

/** @return null|resource an open SSE stream whose status line was read */
function openSse(int $port, string $contextId, string $cookie, bool $brotli): mixed {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return null;
    }
    $query = rawurlencode((string) json_encode(['via_ctx' => $contextId]));
    fwrite($sock, "GET /_sse?datastar={$query} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\n"
        . ($brotli ? "Accept-Encoding: br\r\n" : '') . "Cookie: {$cookie}\r\nConnection: keep-alive\r\n\r\n");

    return $sock;
}

/** Everything the server sent within $seconds, as raw bytes. */
function readFor(mixed $sock, float $seconds): string {
    $raw = '';
    $deadline = microtime(true) + $seconds;
    stream_set_timeout($sock, 0, 50_000);
    while (microtime(true) < $deadline && !feof($sock)) {
        $raw .= (string) fread($sock, 65536);
    }

    return $raw;
}

/** The body of a chunked HTTP/1.1 response, decoded from Brotli when the server compressed it. */
function sseBody(string $raw): string {
    [$head, $rest] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
    $body = '';
    while (preg_match('/^([0-9a-fA-F]+)\r\n/', $rest, $m)) {
        $size = hexdec($m[1]);
        $body .= substr($rest, strlen($m[0]), (int) $size);
        $rest = (string) substr($rest, strlen($m[0]) + (int) $size + 2);
    }

    if (stripos($head, 'content-encoding: br') === false) {
        return $body;
    }

    return (string) brotli_uncompress_add(brotli_uncompress_init(), $body, BROTLI_FLUSH);
}

/** @return array{0: list<int>, 1: int, 2: list<int>} counts, distinct pids, heartbeat flags */
function probeWorkers(int $port, int $probes): array {
    // Concurrent, so the connections spread over the workers.
    $results = new Coroutine\Channel($probes);
    for ($i = 0; $i < $probes; ++$i) {
        Coroutine::create(static function () use ($port, $results): void {
            $client = new Client('127.0.0.1', $port);
            $client->set(['timeout' => 5]);
            $client->get('/count');
            $results->push((string) $client->body);
            $client->close();
        });
    }

    $counts = $heartbeats = [];
    $pids = [];
    for ($i = 0; $i < $probes; ++$i) {
        if (preg_match('/COUNT:(\d+):PID:(\d+):HB:(\d):END/', (string) $results->pop(10), $m)) {
            $counts[] = (int) $m[1];
            $pids[$m[2]] = true;
            $heartbeats[] = (int) $m[3];
        }
    }

    return [$counts, count($pids), $heartbeats];
}

$app->setInterval(static function () use ($app, $port, $mode, $arg, $brotli, $marker, $dispatchMode): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port, $mode, $arg, $brotli, $marker, $dispatchMode): void {
        try {
            if ($mode === 'keepalive') {
                [$idleId, $idleCookie] = loadPage($port, '/probe');
                [$busyId, $busyCookie] = loadPage($port, '/busy');
                $idle = openSse($port, $idleId, $idleCookie, $brotli);
                $busy = openSse($port, $busyId, $busyCookie, $brotli);

                $out = new Coroutine\Channel(2);
                foreach (['idle' => $idle, 'busy' => $busy] as $name => $sock) {
                    Coroutine::create(static fn () => $out->push([$name, sseBody(readFor($sock, 0.7))]));
                }
                for ($i = 0; $i < 2; ++$i) {
                    [$name, $text] = $out->pop(5);
                    echo "{$name}_keepalives=", substr_count($text, ': keep-alive'), "\n";
                    echo "{$name}_events=", substr_count($text, 'event: datastar'), "\n";
                }
                fclose($idle);
                fclose($busy);

                return;
            }

            $streams = [];
            for ($i = 0; $i < max(2, (int) $arg); ++$i) {
                [$id, $cookie] = loadPage($port, '/probe');
                $streams[] = openSse($port, $id, $cookie, false);
            }
            Coroutine::usleep(400_000);

            [$before] = probeWorkers($port, 8);
            echo 'connected=', count($streams), "\n";
            echo 'before=', implode(',', $before), "\n";

            // Nothing is broadcast: only the close itself can end the idle stream in time.
            $closedAt = microtime(true);
            fclose(array_shift($streams));
            if ($dispatchMode !== null) {
                Coroutine::usleep(50_000);
                $client = new Client('127.0.0.1', $port);
                $client->get('/wake');
                $client->close();
            }
            $disconnectedAt = null;
            for ($i = 0; $i < 200 && $disconnectedAt === null; ++$i) {
                Coroutine::usleep(10_000);
                if (preg_match('/disconnect (\S+)/', (string) @file_get_contents($marker), $m)) {
                    $disconnectedAt = (float) $m[1];
                }
            }
            echo 'disconnect_ms=', $disconnectedAt === null ? -1 : (int) round(($disconnectedAt - $closedAt) * 1000), "\n";

            [$after, $pids, $heartbeats] = probeWorkers($port, 12);
            echo 'after=', implode(',', $after), "\n";
            echo 'afterpids=', $pids, "\n";
            echo 'heartbeat=', implode(',', array_unique($heartbeats)), "\n";

            foreach ($streams as $sock) {
                fclose($sock);
            }
        } finally {
            @unlink($marker);
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
