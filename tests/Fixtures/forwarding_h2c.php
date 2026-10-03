<?php

declare(strict_types=1);

/*
 * Fixture for ForwardingTest: actions over h2c on a connection whose worker does not hold the tab's stream, as a
 * proxy sends them once its first upstream connection is full.
 *
 * The page loads over one HTTP/2 connection, the stream runs over its own (curl), and the actions go over a third
 * connection that OpenSwoole hands to another worker.
 *
 * argv[1] = worker count. Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http2\Client;
use OpenSwoole\Http2\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$workers = (int) ($argv[1] ?? 2);
$port = FixturePort::pick(5700, 100);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withWorkerNum($workers)->withH2c());

$app->page('/p', static function (Context $c): void {
    $n = $c->signal(0, 'n');
    $s = $c->signal(0, 's', Scope::SESSION);
    $r = $c->signal(0, 'r', Scope::ROUTE);
    $c->action(static function (Context $c) use ($n): void {
        $n->setValue($n->int() + 1);
        $c->sync();
    }, 'bump');
    $c->action(static fn () => $s->increment(), 'sess');
    $c->action(static fn () => $r->increment(), 'route');
    $c->action(static fn (Context $c) => $c->execScript('window.__forwarded = 1'), 'script');
    $c->action(static fn (Context $c) => $c->setCookie('flavor', 'mint', secure: false), 'cookie');
    $c->view(static fn (): string => '<p id="v">CTX:' . $c->getId() . ':N:' . $n->int() . ':S:' . $s->int() . ':R:' . $r->int()
        . ':PID:' . getmypid() . ':END</p>');
});

$app->route('GET', '/whoami', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Psr7Response(200, [], 'PID:' . getmypid() . ':END');
    }
});

function h2Connect(int $port): Client {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 10]);
    $client->connect();

    return $client;
}

/**
 * @param array<string, string> $headers
 *
 * @return array{0: int, 1: string, 2: list<string>} status, body and Set-Cookie headers
 */
function h2Request(Client $client, string $method, string $path, array $headers = [], string $body = ''): array {
    $request = new Request();
    $request->method = $method;
    $request->path = $path;
    $request->headers = $headers;
    if ($body !== '') {
        $request->data = $body;
    }
    $client->send($request);
    $response = $client->recv(10);
    if ($response === false) {
        return [0, '', []];
    }

    return [(int) $response->statusCode, (string) $response->data, array_values((array) ($response->set_cookie_headers ?? []))];
}

function h2Pid(Client $client): int {
    [, $body] = h2Request($client, 'GET', '/whoami');

    return preg_match('/PID:(\d+):END/', $body, $m) === 1 ? (int) $m[1] : 0;
}

/** @return array{0: int, 1: list<string>} status and Set-Cookie headers */
function h2Action(Client $client, int $port, string $action, string $contextId, string $cookie): array {
    [$status, , $cookies] = h2Request($client, 'POST', "/_action/{$action}", [
        'origin' => "http://127.0.0.1:{$port}",
        'cookie' => "via_session_id={$cookie}",
        'content-type' => 'application/json',
    ], (string) json_encode(['via_ctx' => $contextId]));

    return [$status, $cookies];
}

/** What the stream wrote within $seconds. @param resource $out */
function h2Drain(mixed $out, float $seconds): string {
    $read = '';
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $chunk = fread($out, 65536);
        if ($chunk === false || $chunk === '') {
            Coroutine::usleep(20_000);

            continue;
        }
        $read .= $chunk;
    }

    return $read;
}

function h2Last(string $stream, string $field): string {
    preg_match_all('/:' . $field . ':([^:]*):/', $stream, $m);

    return $m[1] === [] ? '' : (string) end($m[1]);
}

$app->setInterval(static function () use ($app, $port): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port): void {
        $curl = null;

        try {
            $pageConnection = h2Connect($port);
            [$status, $page, $setCookies] = h2Request($pageConnection, 'GET', '/p');
            preg_match('/CTX:(.+?):N:/', html_entity_decode($page), $m);
            $contextId = $m[1] ?? '';
            $cookie = '';
            foreach ($setCookies as $line) {
                if (preg_match('/^via_session_id=([0-9a-f]+)/', $line, $c) === 1) {
                    $cookie = $c[1];
                }
            }
            echo 'page=', $status, ':', (int) ($contextId !== '' && $cookie !== ''), "\n";

            $url = "http://127.0.0.1:{$port}/_sse?datastar=" . rawurlencode((string) json_encode(['via_ctx' => $contextId]));
            $curl = proc_open(['curl', '-sN', '--http2-prior-knowledge', '-H', 'Accept: text/event-stream', '-H', "Cookie: via_session_id={$cookie}", $url], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($curl)) {
                throw new RuntimeException('curl did not start');
            }
            stream_set_blocking($pipes[1], false);
            $seen = h2Drain($pipes[1], 0.8);
            $streamPid = (int) h2Last($seen, 'PID');
            echo 'stream_open=', (int) ($streamPid > 0), "\n";

            // A connection on another worker than the stream's; rejected ones stay open, as dispatch goes by fd.
            $rejected = [];
            $actions = null;
            for ($i = 0; $i < 40 && $actions === null; ++$i) {
                $candidate = h2Connect($port);
                if (h2Pid($candidate) !== $streamPid) {
                    $actions = $candidate;
                } else {
                    $rejected[] = $candidate;
                }
            }
            if ($actions === null) {
                throw new RuntimeException('no connection reached another worker');
            }

            echo 'bump=', h2Action($actions, $port, 'bump', $contextId, $cookie)[0], "\n";
            h2Action($actions, $port, 'bump', $contextId, $cookie);
            h2Action($actions, $port, 'script', $contextId, $cookie);
            h2Action($actions, $port, 'sess', $contextId, $cookie);
            h2Action($actions, $port, 'route', $contextId, $cookie);
            [$cookieStatus, $cookies] = h2Action($actions, $port, 'cookie', $contextId, $cookie);
            $seen .= h2Drain($pipes[1], 0.6);
            echo 'stream_n=', h2Last($seen, 'N'), "\n";
            echo 'stream_script=', (int) str_contains($seen, 'window.__forwarded = 1'), "\n";
            echo 'stream_s=', h2Last($seen, 'S'), "\n";
            echo 'stream_r=', h2Last($seen, 'R'), "\n";
            echo 'cookie=', $cookieStatus, ':', (int) (preg_grep('/^flavor=mint/', $cookies) !== []), "\n";
            echo 'stream_worker_kept=', (int) (h2Last($seen, 'PID') === (string) $streamPid), "\n";
        } catch (Throwable $e) {
            echo 'error=', str_replace("\n", ' ', $e->getMessage()), "\n";
        } finally {
            if (is_resource($curl)) {
                proc_terminate($curl);
                proc_close($curl);
            }
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
