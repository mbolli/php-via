<?php

declare(strict_types=1);

/*
 * Fixture for CountClientsTest: a real server with four workers.
 *
 * Opens five SSE streams, three on pages in room:a and two in room:b, spread across workers by fd dispatch,
 * then asks several workers at once for countClients() of room:a, room:b, room:*, the route and GLOBAL.
 *
 * Prints connected=<n>, one count=<counts> line per probe and pids=<distinct pids that answered>.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The SSE loop needs the real Channel, which VIA_TEST_MODE swaps for an array.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$port = FixturePort::pick(4420, 20);
$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')
        ->withWorkerNum(4)->withBroker(new SwooleBroker())
);

$app->page('/room/{name}', function (Context $c, string $name): void {
    $c->addScope("room:{$name}");
    $c->view(fn (): string => 'CTX:' . $c->getId() . ':END');
});

$app->page('/count', function (Context $c) use ($app): void {
    $c->view(fn (): string => sprintf(
        'COUNT:a=%d b=%d any=%d route=%d global=%d:PID:%d:END',
        $app->countClients('room:a'),
        $app->countClients('room:b'),
        $app->countClients('room:*'),
        $app->countClients(Scope::routeScope('/room/{name}')),
        $app->countClients(Scope::GLOBAL),
        getmypid(),
    ));
});

/** Open an SSE stream on a raw socket and hold it. */
function holdStream(int $port, string $contextId, string $cookie): mixed {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return null;
    }
    $query = rawurlencode((string) json_encode(['via_ctx' => $contextId]));
    fwrite($sock, "GET /_sse?datastar={$query} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: {$cookie}\r\n\r\n");
    stream_set_timeout($sock, 3);
    $status = trim((string) fgets($sock));

    return str_contains($status, ' 200 ') ? $sock : null;
}

$app->setInterval(static function () use ($app, $port): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port): void {
        $sockets = [];
        foreach (['a', 'a', 'a', 'b', 'b'] as $room) {
            $client = new Client('127.0.0.1', $port);
            $client->set(['timeout' => 5]);
            $client->get("/room/{$room}");
            $cookie = '';
            foreach ((array) ($client->set_cookie_headers ?? []) as $raw) {
                $cookie .= explode(';', (string) $raw)[0] . '; ';
            }
            preg_match('/CTX:(.+?):END/', (string) $client->body, $m);
            $client->close();
            $sock = isset($m[1]) ? holdStream($port, $m[1], $cookie) : null;
            if ($sock !== null) {
                $sockets[] = $sock;
            }
        }
        echo 'connected=', count($sockets), "\n";

        Coroutine::usleep(300_000);

        $probes = 12;
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
        $pids = [];
        for ($i = 0; $i < $probes; ++$i) {
            if (preg_match('/COUNT:(.+?):PID:(\d+):END/', (string) $results->pop(10), $m) === 1) {
                echo 'count=', $m[1], "\n";
                $pids[$m[2]] = true;
            }
        }
        echo 'pids=', count($pids), "\n";

        foreach ($sockets as $sock) {
            fclose($sock);
        }
        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
