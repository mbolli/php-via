<?php

declare(strict_types=1);

/*
 * Real-server fixture for PlainRouteTest: plain routes from Via::route() answered over HTTP/1.1.
 *
 * Prints key=value lines: json, stream, head, preflight, wrong_method, set_cookie, head_as_get, any_method,
 * upload, body_early, body_midway, reports.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$port = FixturePort::pick(4460, 20);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error'));
$reports = [];
$app->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $route) use (&$reports): void {
    $reports[] = "{$phase->value} {$route} {$e->getMessage()}";
});

/** A body of unknown size that yields its chunks one by one, with a pause before each. */
final class PausedChunks implements StreamInterface {
    /** @param list<string|Throwable> $chunks a throwable is thrown when its turn comes */
    public function __construct(private array $chunks) {}

    public function __toString(): string {
        return '';
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
        $chunk = array_shift($this->chunks);
        if ($chunk instanceof Throwable) {
            throw $chunk;
        }

        return (string) $chunk;
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

$app->route('GET', '/api/method', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return $request->getMethod() === 'GET' ? new Response(200, [], 'seen as GET') : new Response(405, ['Allow' => 'GET']);
    }
});

$app->route('*', '/api/any', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(404, [], 'off for ' . $request->getMethod());
    }
});

$app->route('POST', '/api/upload', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        $files = $request->getUploadedFiles();
        $report = $files['report'] ?? null;
        $docs = $files['docs'] ?? [];

        return new Response(200, [], implode(' ', [
            $report instanceof UploadedFileInterface ? $report->getClientFilename() . ':' . $report->getSize() . ':' . $report->getStream() : 'none',
            'docs=' . (is_array($docs) ? count($docs) : 0),
            'name=' . (((array) $request->getParsedBody())['name'] ?? ''),
        ]));
    }
});

$app->route('GET', '/api/early', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(200, ['Content-Type' => 'text/event-stream'], new PausedChunks([new RuntimeException('upstream down')]));
    }
});

$app->route('GET', '/api/midway', new class implements RequestHandlerInterface {
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return new Response(200, ['Content-Type' => 'text/event-stream'], new PausedChunks(["data: 1\n\n", new RuntimeException('upstream failed mid-stream')]));
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
function exchange(int $port, string $request, string $body = ''): array {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'raw' => ''];
    }
    [$line, $extra] = array_pad(explode("\n", $request, 2), 2, '');
    $length = $body !== '' ? 'Content-Length: ' . strlen($body) . "\r\n" : '';
    fwrite($sock, "{$line} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n{$length}" . ($extra !== '' ? str_replace("\n", "\r\n", $extra) . "\r\n" : '') . "\r\n" . $body);
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

$app->setInterval(static function () use ($app, $port, &$reports): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, &$reports): void {
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

        $method = exchange($port, 'HEAD /api/method');
        echo 'head_as_get=', $method['status'], ' body=', strlen($method['body']), "\n";

        $any = exchange($port, 'PUT /api/any');
        echo 'any_method=', $any['status'], ' ', $any['body'], "\n";

        $boundary = 'via-boundary';
        $multipart = "--{$boundary}\r\nContent-Disposition: form-data; name=\"name\"\r\n\r\nflows\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"report\"; filename=\"up.csv\"\r\nContent-Type: text/csv\r\n\r\nid,name\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"docs[]\"; filename=\"a.txt\"\r\n\r\na\r\n"
            . "--{$boundary}\r\nContent-Disposition: form-data; name=\"docs[]\"; filename=\"b.txt\"\r\n\r\nb\r\n"
            . "--{$boundary}--\r\n";
        $upload = exchange($port, "POST /api/upload\nContent-Type: multipart/form-data; boundary={$boundary}", $multipart);
        echo 'upload=', $upload['status'], ' ', $upload['body'], "\n";

        $early = exchange($port, 'GET /api/early');
        echo 'body_early=', $early['status'], ' ', $early['headers']['content-type'] ?? 'no content type', ' ', $early['body'], "\n";

        $midway = exchange($port, 'GET /api/midway');
        echo 'body_midway=', $midway['status'], ' ', json_encode($midway['body']), ' ended=', str_ends_with($midway['raw'], "0\r\n\r\n") ? 'yes' : 'no', "\n";

        echo 'reports=', implode(' | ', $reports), "\n";

        $app->getServer()?->shutdown();
    });
}, 200);

$app->start();
