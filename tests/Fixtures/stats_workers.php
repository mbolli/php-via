<?php

declare(strict_types=1);

/*
 * Fixture for StatsTest: a real server whose traffic touches every Via::getStats()->getAll() figure. A page load, a
 * static file, a route() request, an SSE stream, an action posted on another connection that broadcasts one scope
 * twice and renders slower than the broadcast tick, and cycles for the collector timer, all on one worker; then the
 * figures as that worker and another one answer them.
 *
 * argv[1] = worker count
 *
 * Prints key=value lines: a_<field> from the tab's worker, b_<field> from another connection's worker, and the
 * growth of the server-wide counters over the traffic.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Coroutine;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$workers = (int) ($argv[1] ?? 1);
$port = FixturePort::pick(5700, 100);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withWorkerNum($workers)->withGcIntervalMs(100));

$app->page('/p', static function (Context $c) use ($app): void {
    $n = $c->signal(0, 'n');
    $c->addScope('room');
    $c->action(static function () use ($n, $app): void {
        $n->setValue($n->int() + 1);
        $app->broadcast('room');
        $app->broadcast('room');
    }, 'bump');
    $c->view(static function (bool $isUpdate) use ($c, $n): string {
        if ($isUpdate) {
            Coroutine::usleep(40_000);
        }

        return '<p id="v">CTX:' . $c->getId() . ':N:' . $n->int() . ':END</p>';
    });
});

$app->route('GET', '/stats', new class($app) implements RequestHandlerInterface {
    public function __construct(private Via $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Psr7Response(200, ['Content-Type' => 'application/json'], (string) json_encode(['pid' => getmypid(), 'stats' => $this->app->getStats()->getAll()]));
    }
});

$app->route('GET', '/garbage', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        for ($i = 0; $i < 50; ++$i) {
            $a = new stdClass();
            $b = new stdClass();
            $a->peer = $b;
            $b->peer = $a;
        }

        return new Psr7Response(200, [], 'ok');
    }
});

/**
 * One request on a keep-alive socket.
 *
 * @return array{0: int, 1: string, 2: string} status, head and body
 */
function statsRequest(mixed $sock, string $head, string $body = ''): array {
    fwrite($sock, $head . "\r\n" . $body);
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n") && !feof($sock)) {
        $raw .= (string) fread($sock, 1);
    }
    preg_match('/^HTTP\/1\.1 (\d+)/', $raw, $status);
    $content = '';
    if (preg_match('/content-length:\s*(\d+)/i', $raw, $m)) {
        while (strlen($content) < (int) $m[1] && !feof($sock)) {
            $content .= (string) fread($sock, (int) $m[1] - strlen($content));
        }
    } elseif (stripos($raw, 'chunked') !== false) {
        while (($size = hexdec(trim((string) fgets($sock)))) > 0) {
            $chunk = '';
            while (strlen($chunk) < $size && !feof($sock)) {
                $chunk .= (string) fread($sock, (int) $size - strlen($chunk));
            }
            $content .= $chunk;
            fgets($sock);
        }
        fgets($sock);
    }

    return [(int) ($status[1] ?? 0), $raw, $content];
}

/** @return array{pid: int, stats: array<string, float|int>} */
function statsRead(mixed $sock): array {
    [, , $body] = statsRequest($sock, "GET /stats HTTP/1.1\r\nHost: 127.0.0.1\r\n");
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : ['pid' => 0, 'stats' => []];
}

/**
 * A keep-alive socket served by the worker with pid $pid, or by any other when $other, and the pid that serves it.
 *
 * @return array{0: resource, 1: int}
 */
function statsSocketOn(int $port, int $pid, bool $other): array {
    // Rejected sockets stay open until the end: a closed one frees its fd, and dispatch goes by fd.
    static $rejected = [];
    for ($i = 0; $i < 40; ++$i) {
        $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        $served = statsRead($sock)['pid'];
        if ($pid === 0 || ($served === $pid) !== $other) {
            return [$sock, $served];
        }
        $rejected[] = $sock;
    }

    throw new RuntimeException('no connection reached the wanted worker');
}

function statsDrive(int $port, int $workers): void {
    [$onA, $pidA] = statsSocketOn($port, 0, false);
    [$onB, $pidB] = statsSocketOn($port, $pidA, $workers > 1);
    [$stream] = statsSocketOn($port, $pidA, false);
    echo 'other_worker=', (int) ($pidA !== $pidB), "\n";

    $before = statsRead($onA)['stats'];

    [, $head, $page] = statsRequest($onA, "GET /p HTTP/1.1\r\nHost: 127.0.0.1\r\n");
    preg_match('/CTX:(.+?):N:/', html_entity_decode($page), $m);
    preg_match('/set-cookie: via_session_id=([0-9a-f]+)/i', $head, $c);
    $contextId = $m[1] ?? '';
    $cookie = $c[1] ?? '';
    echo 'static=', statsRequest($onA, "GET /datastar.js HTTP/1.1\r\nHost: 127.0.0.1\r\n")[0], "\n";

    fwrite($stream, 'GET /_sse?datastar=' . rawurlencode((string) json_encode(['via_ctx' => $contextId]))
        . " HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: via_session_id={$cookie}\r\n\r\n");
    Coroutine::usleep(300_000);

    $body = (string) json_encode(['via_ctx' => $contextId]);
    [$bump] = statsRequest($onB, "POST /_action/bump HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\n"
        . "Cookie: via_session_id={$cookie}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n", $body);
    echo 'bump=', $bump, "\n";
    statsRequest($onA, "GET /garbage HTTP/1.1\r\nHost: 127.0.0.1\r\n");
    Coroutine::usleep(400_000);

    $a = statsRead($onA)['stats'];
    $b = statsRead($onB)['stats'];
    foreach ($a as $field => $value) {
        echo 'a_', $field, '=', $value, "\n";
    }
    foreach ($b as $field => $value) {
        echo 'b_', $field, '=', $value, "\n";
    }
    // Before: the first read; then the page, the static file and /garbage. The b_ read also counts the a_ read.
    echo 'grew_requests=', $a['requests'] - $before['requests'], "\n";
    echo 'grew_actions=', $a['actions'] - $before['actions'], "\n";
    echo 'grew_sse_connections=', $a['sse_connections'] - $before['sse_connections'], "\n";
    echo 'shared_requests=', (int) ($b['requests'] === $a['requests'] + 1), "\n";
    echo 'shared_actions=', (int) ($b['actions'] === $a['actions']), "\n";
    echo 'shared_sse_connections=', (int) ($b['sse_connections'] === $a['sse_connections']), "\n";
    fclose($stream);
}

$app->setInterval(static function () use ($app, $port, $workers): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port, $workers): void {
        try {
            statsDrive($port, $workers);
        } catch (Throwable $e) {
            echo 'error=', str_replace("\n", ' ', $e->getMessage()), "\n";
        } finally {
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
