<?php

declare(strict_types=1);

/*
 * Fixture for RouteScopeWorkersTest: a ROUTE signal on a route with a parameter, /blog/{slug}, with two workers.
 *
 * Holds a tab's SSE stream, then writes the signal from route() requests over fresh connections until one lands on
 * the other worker. Its write broadcasts route:/blog/{slug}, which has to reach the stream's worker through the
 * broker for the stream to show the new value.
 *
 * Prints stream_pid=<pid>, writer_pid=<pid>, hits=<value written on the other worker> and seen=<1 when the stream
 * sent that value>.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The SSE loop needs the real Channel, which VIA_TEST_MODE swaps for an array.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$port = FixturePort::pick(4700, 40);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withWorkerNum(2));

$app->page('/blog/{slug}', static function (Context $c): void {
    $hits = $c->signal(0, 'hits', Scope::ROUTE);
    $c->view(static fn (): string => '<p id="hits">HITS:' . $hits->int() . ':PID:' . getmypid() . ':CTX:' . $c->getId() . ':END</p>');
});

$app->route('POST', '/hit', new class($app) implements RequestHandlerInterface {
    public function __construct(private Via $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $hits = $this->app->getScopedSignalByName(Scope::routeScope('/blog/{slug}'), 'hits');
        $value = $hits?->increment() ?? -1;

        return new Psr7Response(200, [], 'HIT:' . $value . ':PID:' . getmypid());
    }
});

/** Open the SSE stream of a tab on a raw socket. */
function openStream(int $port, string $contextId, string $cookie): mixed {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return null;
    }
    $query = rawurlencode((string) json_encode(['via_ctx' => $contextId]));
    fwrite($sock, "GET /_sse?datastar={$query} HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: {$cookie}\r\n\r\n");
    stream_set_timeout($sock, 0, 200_000);

    return $sock;
}

/** Read the stream until $pattern matches what it sent, or $seconds pass. */
function readUntil(mixed $sock, string $pattern, float $seconds, string &$seen): ?array {
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        $chunk = fread($sock, 65536);
        if (is_string($chunk) && $chunk !== '') {
            $seen .= $chunk;
        }
        if (preg_match($pattern, $seen, $m) === 1) {
            return $m;
        }
    }

    return null;
}

$app->setInterval(static function () use ($app, $port): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port): void {
        $client = new Client('127.0.0.1', $port);
        $client->set(['timeout' => 5]);
        $client->get('/blog/first-post');
        $cookie = '';
        foreach ((array) ($client->set_cookie_headers ?? []) as $raw) {
            $cookie .= explode(';', (string) $raw)[0] . '; ';
        }
        preg_match('/CTX:(.+?):END/', (string) $client->body, $m);
        $client->close();

        $sock = openStream($port, $m[1] ?? '', $cookie);
        $seen = '';
        $first = $sock === null ? null : readUntil($sock, '/HITS:(\d+):PID:(\d+)/', 5, $seen);
        $streamPid = (int) ($first[2] ?? 0);
        echo 'stream_pid=', $streamPid, "\n";

        // Writes on the stream's worker render there at once; the first write elsewhere has to cross.
        $hits = -1;
        $writerPid = 0;
        for ($i = 0; $i < 20 && $streamPid > 0; ++$i) {
            $client = new Client('127.0.0.1', $port);
            $client->set(['timeout' => 5]);
            $client->post('/hit', '');
            $body = (string) $client->body;
            $client->close();
            if (preg_match('/HIT:(-?\d+):PID:(\d+)/', $body, $h) === 1 && (int) $h[2] !== $streamPid) {
                $hits = (int) $h[1];
                $writerPid = (int) $h[2];

                break;
            }
        }
        echo 'writer_pid=', $writerPid, "\n", 'hits=', $hits, "\n";

        $seen = '';
        $crossed = $hits > 0 && readUntil($sock, '/HITS:' . $hits . ':PID:' . $streamPid . '\b/', 3, $seen) !== null;
        echo 'seen=', $crossed ? 1 : 0, "\n";

        if (is_resource($sock)) {
            fclose($sock);
        }
        $app->getServer()?->shutdown();
    });
}, 300);

$app->start();
