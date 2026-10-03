<?php

declare(strict_types=1);

/*
 * Fixture for SseReconnectTest: a real server whose tabs leave and come back.
 *
 * argv[1] = h2: h2c, one worker. Two tabs stream over one HTTP/2 connection. The client resets the
 *           first tab's stream and keeps the connection, then resets the second tab's stream and
 *           reopens it in the same write, as a hidden and shown tab does, and broadcasts to its room.
 *           xworker: two workers, cleanup 300 ms, revival window 2 s, directory TTL 60 s. A tab
 *           streams from worker A, then from worker B, A destroys its copy, and after the revival
 *           window the tab's actions land on A.
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use Tests\Support\FixturePort;

$mode = (string) ($argv[1] ?? 'h2');
$marker = sys_get_temp_dir() . '/via_sse_reconnect_' . getmypid();
@unlink($marker);

$port = FixturePort::pick(4600, 150);

$config = (new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error');
if ($mode === 'h2') {
    $config = $config->withH2c();
} else {
    $config = $config->withWorkerNum(2)->withBroker(new SwooleBroker())
        ->withContextCleanupDelay(300)->withContextRevivalWindow(2000)->withContextDirectorySize(4096, 1024, 60)
    ;
}
$app = new Via($config);
$bumps = 0;

$app->page('/room', function (Context $c) use (&$bumps): void {
    $c->scope('room:lobby');
    $hit = $c->action(static function (): void {}, 'hit');
    $c->view(function () use ($c, $hit, &$bumps): string {
        return '<div id="v">CTX:' . $c->getId() . ':URL:' . $hit->url() . ':N:' . $bumps . ':END</div>';
    }, cacheUpdates: false);
});

$app->page('/bump', function (Context $c) use ($app, &$bumps): void {
    ++$bumps;
    $app->broadcast('room:lobby');
    $c->view(static fn (): string => '<div id="v">ok</div>');
});

$app->page('/count', function (Context $c) use ($app): void {
    $c->view(static fn (): string => 'CLIENTS:' . implode(',', array_column($app->getClients(), 'context_id'))
        . ':SCOPE:' . implode(',', array_map(static fn (Context $c): string => $c->getId(), $app->getContextsByScope('room:lobby')))
        . ':PID:' . getmypid() . ':END');
});

$app->onClientDisconnect(static function (Context $c) use ($marker): void {
    file_put_contents($marker, "disconnect {$c->getId()} " . microtime(true) . "\n", FILE_APPEND | LOCK_EX);
});

/** A minimal HTTP/2 client over cleartext with prior knowledge: requests, stream resets and DATA only. */
final class H2c {
    /** @var array<int, string> DATA received, by stream */
    public array $data = [];

    /** @var resource */
    private $sock;
    private string $buffer = '';
    private int $nextStream = 1;

    public function __construct(private int $port) {
        $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        if ($sock === false) {
            throw new RuntimeException("connect: {$error}");
        }
        $this->sock = $sock;
        fwrite($sock, "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n" . self::frame(0x4, 0, 0, ''));
    }

    /** The HEADERS frame of a new GET, and its stream ID. @return array{0: string, 1: int} */
    public function get(string $path, string $cookie): array {
        $id = $this->nextStream;
        $this->nextStream += 2;
        // HPACK: :method GET and :scheme http from the static table, then literals with indexed names.
        $block = "\x82\x86" . self::literal(4, $path) . self::literal(1, "127.0.0.1:{$this->port}")
            . self::literal(19, 'text/event-stream') . self::literal(32, $cookie);

        return [self::frame(0x1, 0x5, $id, $block), $id];
    }

    public function reset(int $stream): string {
        return self::frame(0x3, 0, $stream, pack('N', 0x8));
    }

    public function send(string $frames): void {
        fwrite($this->sock, $frames);
    }

    /** Read frames for $seconds, acknowledging SETTINGS and PING and returning flow-control credit. */
    public function pump(float $seconds): void {
        $deadline = microtime(true) + $seconds;
        stream_set_timeout($this->sock, 0, 20_000);
        while (microtime(true) < $deadline && !feof($this->sock)) {
            $this->buffer .= (string) fread($this->sock, 65536);
            while (strlen($this->buffer) >= 9) {
                $length = unpack('N', "\0" . substr($this->buffer, 0, 3))[1];
                if (strlen($this->buffer) < 9 + $length) {
                    break;
                }
                $type = ord($this->buffer[3]);
                $flags = ord($this->buffer[4]);
                $stream = unpack('N', substr($this->buffer, 5, 4))[1] & 0x7FFFFFFF;
                $payload = substr($this->buffer, 9, $length);
                $this->buffer = substr($this->buffer, 9 + $length);

                if ($type === 0x0) {
                    $padding = ($flags & 0x8) !== 0 ? ord($payload[0]) : 0;
                    $this->data[$stream] = ($this->data[$stream] ?? '') . substr($payload, $padding > 0 ? 1 : 0, strlen($payload) - $padding - ($padding > 0 ? 1 : 0));
                    if ($length > 0) {
                        fwrite($this->sock, self::frame(0x8, 0, 0, pack('N', $length)) . self::frame(0x8, 0, $stream, pack('N', $length)));
                    }
                } elseif ($type === 0x4 && ($flags & 0x1) === 0) {
                    fwrite($this->sock, self::frame(0x4, 0x1, 0, ''));
                } elseif ($type === 0x6 && ($flags & 0x1) === 0) {
                    fwrite($this->sock, self::frame(0x6, 0x1, 0, $payload));
                }
            }
        }
    }

    public function close(): void {
        fclose($this->sock);
    }

    private static function frame(int $type, int $flags, int $stream, string $payload): string {
        return substr(pack('N', strlen($payload)), 1) . chr($type) . chr($flags) . pack('N', $stream) . $payload;
    }

    /** Literal header field without indexing, name from the static table. */
    private static function literal(int $nameIndex, string $value): string {
        return self::integer($nameIndex, 4) . self::integer(strlen($value), 7) . $value;
    }

    private static function integer(int $value, int $prefixBits): string {
        $max = (1 << $prefixBits) - 1;
        if ($value < $max) {
            return chr($value);
        }
        $out = chr($max);
        for ($value -= $max; $value >= 128; $value >>= 7) {
            $out .= chr(($value & 0x7F) | 0x80);
        }

        return $out . chr($value);
    }
}

/** @return array{0: string, 1: string, 2: string} context ID, session cookie and action URL of a fresh page load */
function loadRoom(int $port): array {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 5]);
    $client->get('/room');
    preg_match('/CTX:(.+?):URL:(.+?):N:/', (string) $client->body, $m);
    $cookie = '';
    foreach ((array) ($client->set_cookie_headers ?? []) as $raw) {
        $cookie .= explode(';', (string) $raw)[0] . '; ';
    }
    $client->close();

    return [$m[1] ?? '', $cookie, html_entity_decode($m[2] ?? '')];
}

/** @return array{clients: string, scope: string} the context IDs in getClients() and in the room, as one worker sees them */
function countOn(int $port): array {
    $client = new Client('127.0.0.1', $port);
    $client->set(['timeout' => 5]);
    $client->get('/count');
    preg_match('/CLIENTS:(.*?):SCOPE:(.*?):PID:/', html_entity_decode((string) $client->body), $m);
    $client->close();

    return ['clients' => $m[1] ?? '?', 'scope' => $m[2] ?? '?'];
}

function ssePath(string $contextId): string {
    return '/_sse?datastar=' . rawurlencode((string) json_encode(['via_ctx' => $contextId]));
}

/** One request on a keep-alive socket. @return array{0: int, 1: string} status and body */
function request(mixed $sock, string $head, string $body = ''): array {
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

    return [(int) ($status[1] ?? 0), $content];
}

/** A keep-alive socket served by the worker with pid $pid, or by any other when $other is true, and the pid that served it. @return array{0: resource, 1: int} */
function socketOn(int $port, int $pid, bool $other): array {
    // Rejected sockets stay open until the end: a closed one frees its fd, and dispatch goes by fd.
    $rejected = [];

    try {
        for ($i = 0; $i < 20; ++$i) {
            $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
            [, $body] = request($sock, "GET /count HTTP/1.1\r\nHost: 127.0.0.1\r\n");
            preg_match('/PID:(\d+):END/', $body, $m);
            $served = (int) ($m[1] ?? 0);
            if ($pid === 0 || ($served === $pid) !== $other) {
                return [$sock, $served];
            }
            $rejected[] = $sock;
        }
    } finally {
        array_map(fclose(...), $rejected);
    }

    throw new RuntimeException('no connection reached the wanted worker');
}

/** Milliseconds from $since until $contextId disconnected, or -1 after 3 s. */
function disconnectedAfter(string $marker, string $contextId, float $since, H2c $h2): int {
    for ($i = 0; $i < 60; ++$i) {
        if (preg_match('/disconnect ' . preg_quote($contextId, '/') . ' (\S+)/', (string) @file_get_contents($marker), $m)) {
            return (int) round(((float) $m[1] - $since) * 1000);
        }
        $h2->pump(0.05);
    }

    return -1;
}

$app->setInterval(static function () use ($app, $port, $mode, $marker): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port, $mode, $marker): void {
        try {
            if ($mode === 'h2') {
                [$first, $firstCookie] = loadRoom($port);
                [$second, $secondCookie] = loadRoom($port);
                $h2 = new H2c($port);
                [$frames, $firstStream] = $h2->get(ssePath($first), $firstCookie);
                [$more, $secondStream] = $h2->get(ssePath($second), $secondCookie);
                $h2->send($frames . $more);
                $h2->pump(0.4);

                $resetAt = microtime(true);
                $h2->send($h2->reset($firstStream));
                echo 'reset_disconnect_ms=', disconnectedAfter($marker, $first, $resetAt, $h2), "\n";

                // Reset and reopen in one write, so the server takes both before any timer runs.
                [$reopen, $reopened] = $h2->get(ssePath($second), $secondCookie);
                $h2->send($h2->reset($secondStream) . $reopen);
                $h2->pump(0.3);

                $count = countOn($port);
                echo 'clients=', $count['clients'] === $second ? 'reopened' : $count['clients'], "\n";
                echo 'scope=', $count['scope'] === $second ? 'reopened' : $count['scope'], "\n";

                $bump = new Client('127.0.0.1', $port);
                $bump->get('/bump');
                $bump->close();
                $h2->pump(0.3);
                echo 'reopened_got_broadcast=', (int) str_contains($h2->data[$reopened] ?? '', ':N:1:END'), "\n";
                $h2->close();

                return;
            }

            [$id, $cookie, $url] = loadRoom($port);
            $sse = 'GET ' . ssePath($id) . " HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: {$cookie}\r\n";

            [$onA, $pidA] = socketOn($port, 0, false);
            fwrite($onA, $sse . "\r\n");
            Coroutine::usleep(300_000);
            fclose($onA);
            Coroutine::usleep(100_000);

            [$onB, $pidB] = socketOn($port, $pidA, true);
            fwrite($onB, $sse . "\r\n");

            // A destroys its copy 300 ms after the close and cuts the record to the 2 s revival window.
            Coroutine::usleep(3_000_000);

            $statuses = [];
            for ($i = 0; $i < 3; ++$i) {
                [$sock] = socketOn($port, $pidA, false);
                $body = (string) json_encode(['via_ctx' => $id]);
                [$statuses[]] = request($sock, "POST {$url} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\n"
                    . "Cookie: {$cookie}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n", $body);
                fclose($sock);
            }
            echo 'workers=', (int) ($pidA !== $pidB), "\n";
            echo 'actions_on_a=', implode(',', $statuses), "\n";
            fclose($onB);
        } catch (Throwable $e) {
            echo 'error=', str_replace("\n", ' ', $e->getMessage()), "\n";
        } finally {
            @unlink($marker);
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
