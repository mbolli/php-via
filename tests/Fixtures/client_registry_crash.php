<?php

declare(strict_types=1);

/*
 * Fixture for ClientRegistryTest: SIGKILL a worker that holds SSE streams, then watch every
 * worker's getClients() count until the dead worker's clients are gone from it.
 *
 * The driver runs in worker 0 and kills the other one, so it survives. Each stream's first event
 * carries the PID of the worker serving it, which is how the fixture knows what it kills.
 *
 * Prints connected=<n> killed=<streams the victim held> before=<counts> after=<counts>
 * elapsed_ms=<from the kill until every probe answered the new count, or -1>.
 *
 * argv[1] = SSE connections
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The real server is needed; see client_registry_workers.php.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;

$connections = (int) ($argv[1] ?? 6);
$probes = 8;

$port = 3550 + (getmypid() % 150);
$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withDevMode(true)
        ->withWorkerNum(2)->withBroker(new SwooleBroker())
);

$app->page('/probe', function (Context $c): void {
    $c->view(fn (): string => 'CTX:' . $c->getId() . ':PID:' . getmypid() . ':END');
});

$app->page('/count', function (Context $c) use ($app): void {
    $c->view(fn (): string => 'COUNT:' . count($app->getClients()) . ':PID:' . getmypid() . ':END');
});

/**
 * Open an SSE stream on a raw socket, hold it, and read the PID of the worker serving it.
 *
 * @return array{0: mixed, 1: int} the socket and the serving worker's PID, 0 when unknown
 */
function openSse(int $port, string $contextId, string $cookie): array {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return [null, 0];
    }
    $query = rawurlencode((string) json_encode(['via_ctx' => $contextId]));
    fwrite($sock, "GET /_sse?datastar={$query} HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\nAccept: text/event-stream\r\n"
        . "Cookie: {$cookie}\r\nConnection: keep-alive\r\n\r\n");

    stream_set_timeout($sock, 3);
    $seen = '';
    $deadline = microtime(true) + 3;
    while (microtime(true) < $deadline && !preg_match('/:PID:(\d+):END/', $seen)) {
        $line = fgets($sock);
        if ($line === false) {
            break;
        }
        $seen .= $line;
    }

    return [$sock, preg_match('/:PID:(\d+):END/', $seen, $m) ? (int) $m[1] : 0];
}

/** @return array<int, int> count by answering PID, one probe per concurrent request */
function probe(int $port, int $probes): array {
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

    $counts = [];
    for ($i = 0; $i < $probes; ++$i) {
        if (preg_match('/COUNT:(\d+):PID:(\d+):END/', (string) $results->pop(10), $m)) {
            $counts[] = (int) $m[1];
        }
    }

    return $counts;
}

$app->setInterval(static function () use ($app, $port, $connections, $probes): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, $connections, $probes): void {
        $sockets = [];
        $byPid = [];
        for ($i = 0; $i < $connections; ++$i) {
            $client = new Client('127.0.0.1', $port);
            $client->set(['timeout' => 5]);
            $client->get('/probe');
            $body = (string) $client->body;
            $cookie = '';
            foreach ((array) ($client->set_cookie_headers ?? []) as $raw) {
                $cookie .= explode(';', (string) $raw)[0] . '; ';
            }
            $client->close();

            if (preg_match('/CTX:(.+?):PID/', $body, $m)) {
                [$sock, $pid] = openSse($port, $m[1], $cookie);
                $sockets[] = $sock;
                $byPid[$pid] = ($byPid[$pid] ?? 0) + 1;
            }
        }

        $victims = array_diff_key($byPid, [getmypid() => true, 0 => true]);
        $victim = (int) array_key_first($victims);
        $killed = $victims[$victim] ?? 0;
        $expected = count($sockets) - $killed;

        Coroutine::usleep(300_000);
        $before = probe($port, $probes);

        $elapsedMs = -1;
        $after = [];
        if ($victim > 0) {
            posix_kill($victim, SIGKILL);
            $killedAt = hrtime(true);
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                $after = probe($port, $probes);
                if ($after !== [] && array_unique($after) === [$expected]) {
                    $elapsedMs = (int) ((hrtime(true) - $killedAt) / 1e6);

                    break;
                }
                Coroutine::usleep(50_000);
            }
        }

        echo 'connected=', count($sockets), "\n";
        echo 'killed=', $killed, "\n";
        echo 'before=', implode(',', $before), "\n";
        echo 'after=', implode(',', $after), "\n";
        echo 'elapsed_ms=', $elapsedMs, "\n";

        foreach ($sockets as $sock) {
            if (is_resource($sock)) {
                fclose($sock);
            }
        }

        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
