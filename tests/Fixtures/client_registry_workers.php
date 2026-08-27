<?php

declare(strict_types=1);

/*
 * Fixture for ClientRegistryTest: a real multi-worker server.
 *
 * Opens N SSE connections (spread across workers by fd dispatch), then asks several workers
 * how many clients they can see.
 *
 * Prints connected=<n> counts=<comma-separated counts, one per probe> pids=<distinct pids>.
 *
 * argv[1] = worker count, argv[2] = SSE connections, argv[3] = probes
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Pest sets VIA_TEST_MODE=1 and the child inherits it. That swaps PatchManager's Channel for
// an array, so getPatch() returns immediately instead of parking for the poll interval and the
// SSE loop spins — starving the scheduler so the driver's own requests never complete. This
// fixture needs the real server, so clear it.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;

$workers = (int) ($argv[1] ?? 4);
$connections = (int) ($argv[2] ?? 4);
$probes = (int) ($argv[3] ?? 8);

// Derived from the PID rather than fixed: two runs overlapping on one port makes the second
// fatal with "Address already in use" and the test read it as a product failure.
$port = 3400 + (getmypid() % 150);
$app = new Via(
    (new Config())
        ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withDevMode(true)
        ->withWorkerNum($workers)->withBroker(new SwooleBroker())
);

$app->page('/probe', function (Context $c): void {
    // Render the context ID explicitly rather than scraping it out of the Datastar
    // attribute, where it is JSON-escaped.
    $c->view(fn (): string => 'CTX:' . $c->getId() . ':END');
});

// Reports what THIS worker believes about the server-wide client list.
$app->page('/count', function (Context $c) use ($app): void {
    $c->view(fn (): string => 'COUNT:' . count($app->getClients()) . ':PID:' . getmypid() . ':END');
});

/** Open an SSE stream on a raw socket and hold it. */
function openSse(int $port, string $contextId, string $cookie): mixed {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return null;
    }
    // Datastar carries signals as ?datastar=<json> on GET; SSE reads via_ctx from them.
    $query = rawurlencode((string) json_encode(['via_ctx' => $contextId]));
    // The session-ownership gate applies to SSE too: without the cookie this is a 403.
    fwrite($sock, "GET /_sse?datastar={$query} HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\nAccept: text/event-stream\r\n"
        . "Cookie: {$cookie}\r\nConnection: keep-alive\r\n\r\n");

    // Read the status line so a failed handshake is visible instead of silent.
    stream_set_timeout($sock, 3);
    $status = trim((string) fgets($sock));
    if (!str_contains($status, ' 200 ')) {
        fwrite(STDERR, "sse-handshake-failed: {$status}\n");
    }

    return $sock;
}

$app->setInterval(static function () use ($app, $port, $connections, $probes): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, $connections, $probes): void {
        // Each SSE stream needs its own context, so load a page per connection first.
        $sockets = [];
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

            preg_match('/CTX:(.+?):END/', $body, $m);
            $ctxId = $m[1] ?? '';
            fwrite(STDERR, 'ctx=' . var_export($ctxId, true) . '
');
            if ($ctxId !== '') {
                $sockets[] = openSse($port, $ctxId, $cookie);
            }
        }

        Coroutine::usleep(400_000);

        // Concurrent so fd-based dispatch actually spreads them over workers; sequential
        // connections reuse the same fd number and land on the same one every time.
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
        $pids = [];
        for ($i = 0; $i < $probes; ++$i) {
            preg_match('/COUNT:(\d+):PID:(\d+):END/', (string) $results->pop(10), $m);
            if (isset($m[1])) {
                $counts[] = (int) $m[1];
                $pids[$m[2]] = true;
            }
        }

        echo 'connected=', count($sockets), "\n";
        echo 'counts=', implode(',', $counts), "\n";
        echo 'pids=', count($pids), "\n";

        foreach ($sockets as $sock) {
            if (is_resource($sock)) {
                fclose($sock);
            }
        }

        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
