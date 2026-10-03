<?php

declare(strict_types=1);

/*
 * Real-server fixture for PlainRouteTest: plain routes from Via::route() answered over HTTP/1.1.
 *
 * Prints key=value lines: json, stream, head, preflight, wrong_method, set_cookie.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$port = FixturePort::pick(4460, 20);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error'));

/** A body of unknown size that yields its chunks one by one, with a pause before each. */
final class PausedChunks implements StreamInterface {
    /** @param list<string> $chunks */
    public function __construct(private array $chunks) {}

    public function __toString(): string {
        return implode('', $this->chunks);
    }

    public function close(): void {}

    public function detach() {
        return null;
    }

    public function getSize(): ?int {
        return null;
    }

    public function tell(): int {
        return 0;
    }

    public function eof(): bool {
        return $this->chunks === [];
    }

    public function isSeekable(): bool {
        return false;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void {}

    public function rewind(): void {}

    public function isWritable(): bool {
        return false;
    }

    public function write(string $string): int {
        return 0;
    }

    public function isReadable(): bool {
        return true;
    }

    public function read(int $length): string {
        Coroutine::usleep(20_000);

        return (string) array_shift($this->chunks);
    }

    public function getContents(): string {
        return '';
    }

    public function getMetadata(?string $key = null): mixed {
        return null;
    }
}

$app->route('GET', '/api/ping', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        $session = $request->getAttribute('via.session');

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'pong' => true,
            'session' => is_string($session) && SessionManager::isValidSessionId($session),
        ]));
    }
});

$app->route('GET', '/api/stream', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(200, ['Content-Type' => 'text/plain'], new PausedChunks(['one|', 'two|', 'three']));
    }
});

$app->route(['POST', 'OPTIONS'], '/api/echo', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response(204, ['Access-Control-Allow-Methods' => 'POST']);
        }

        return new Response(200, [], (string) $request->getBody());
    }
});

/**
 * One request on its own connection, which closes after the response.
 *
 * @return array{status: int, headers: array<string, string>, body: string, raw: string}
 */
function exchange(int $port, string $request): array {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'raw' => ''];
    }
    [$line, $extra] = array_pad(explode("\n", $request, 2), 2, '');
    fwrite($sock, "{$line} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n" . ($extra !== '' ? str_replace("\n", "\r\n", $extra) . "\r\n" : '') . "\r\n");
    stream_set_timeout($sock, 5);
    $raw = (string) stream_get_contents($sock);
    fclose($sock);

    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    $headers = [];
    foreach (array_slice($lines, 1) as $headerLine) {
        [$name, $value] = array_pad(explode(':', $headerLine, 2), 2, '');
        $name = strtolower(trim($name));
        $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . trim($value) : trim($value);
    }
    if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
        $decoded = '';
        while (preg_match('/^([0-9a-f]+)\r\n/i', $body, $m) === 1 && ($size = hexdec($m[1])) > 0) {
            $decoded .= substr($body, strlen($m[0]), (int) $size);
            $body = substr($body, strlen($m[0]) + (int) $size + 2);
        }
        $body = $decoded;
    }

    return ['status' => (int) (explode(' ', $lines[0])[1] ?? 0), 'headers' => $headers, 'body' => $body, 'raw' => $raw];
}

$app->setInterval(static function () use ($app, $port): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port): void {
        $json = exchange($port, 'GET /api/ping');
        echo 'json=', $json['status'], ' ', $json['headers']['content-type'] ?? '', ' ', $json['body'], "\n";
        echo 'set_cookie=', $json['headers']['set-cookie'] ?? 'none', "\n";

        $stream = exchange($port, 'GET /api/stream');
        echo 'stream=', $stream['status'], ' ', $stream['body'], "\n";

        $head = exchange($port, 'HEAD /api/ping');
        echo 'head=', $head['status'], ' body=', strlen($head['body']), "\n";

        $preflight = exchange($port, "OPTIONS /api/echo\nOrigin: https://elsewhere.example\nAccess-Control-Request-Method: POST");
        echo 'preflight=', $preflight['status'], ' ', $preflight['headers']['access-control-allow-methods'] ?? '', "\n";

        $wrong = exchange($port, 'DELETE /api/ping');
        echo 'wrong_method=', $wrong['status'], ' ', $wrong['headers']['allow'] ?? '', "\n";

        $app->getServer()?->shutdown();
    });
}, 200);

$app->start();
