<?php

declare(strict_types=1);

/*
 * Real-server fixture for DownloadTest: Context::download() fetched over HTTP/1.1.
 *
 * The page asks for three downloads: a generator of 2000 CSV rows, a file, and one fetched from another session.
 * Prints key=value lines: stream, file, again, stranger.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use Tests\Support\FixturePort;

$port = FixturePort::pick(4440, 20);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error'));
$file = sys_get_temp_dir() . '/via-download-fixture-' . getmypid() . '.txt';
file_put_contents($file, 'file content');

function csvRows(): Generator {
    for ($i = 1; $i <= 2000; ++$i) {
        yield "{$i},row-{$i}\n";
    }
}

$app->page('/export', function (Context $c) use ($file): void {
    $stream = $c->download(static function (): Generator {
        foreach (csvRows() as $row) {
            if (str_starts_with($row, '1000,')) {
                Coroutine::usleep(10_000);
            }

            yield $row;
        }
    }, 'rows.csv', 'text/csv');
    $sent = $c->download($file, 'file.txt', 'text/plain');
    $other = $c->download(static fn (): string => 'secret', 'other.txt', 'text/plain');
    $c->view(static fn (): string => "URLS:{$stream}|{$sent}|{$other}:END");
});

/**
 * One request on its own connection, which closes after the response.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function fetch(int $port, string $path, string $cookie = ''): array {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return ['status' => 0, 'headers' => [], 'body' => ''];
    }
    fwrite($sock, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n" . ($cookie !== '' ? "Cookie: {$cookie}\r\n" : '') . "\r\n");
    stream_set_timeout($sock, 5);
    $raw = (string) stream_get_contents($sock);
    fclose($sock);

    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))] = trim($value);
    }
    if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
        $decoded = '';
        while (preg_match('/^([0-9a-f]+)\r\n/i', $body, $m) === 1 && ($size = (int) hexdec($m[1])) > 0) {
            $decoded .= substr($body, strlen($m[0]), $size);
            $body = substr($body, strlen($m[0]) + $size + 2);
        }
        $body = $decoded;
    }

    return ['status' => (int) (explode(' ', $lines[0])[1] ?? 0), 'headers' => $headers, 'body' => $body];
}

$app->setInterval(static function () use ($app, $port, $file): void {
    Timer::clearAll();

    Coroutine::create(static function () use ($app, $port, $file): void {
        $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
        fwrite($sock, "GET /export HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $page = (string) stream_get_contents($sock);
        fclose($sock);
        preg_match('/Set-Cookie: (via_session_id=[0-9a-f]+)/i', $page, $cookie);
        preg_match('/URLS:(\S+?)\|(\S+?)\|(\S+?):END/', $page, $urls);
        $cookie = $cookie[1] ?? '';

        $stream = fetch($port, $urls[1] ?? '/', $cookie);
        $expected = implode('', iterator_to_array(csvRows(), false));
        echo 'stream=', $stream['status'], ' ', isset($stream['headers']['transfer-encoding']) ? 'chunked' : 'whole', ' ',
        $stream['headers']['content-type'] ?? '', ' rows=', substr_count($stream['body'], "\n"), ' intact=', $stream['body'] === $expected ? 'yes' : 'no', "\n";

        $sent = fetch($port, $urls[2] ?? '/', $cookie);
        echo 'file=', $sent['status'], ' length=', $sent['headers']['content-length'] ?? '', ' ', $sent['body'], "\n";

        echo 'again=', fetch($port, $urls[1] ?? '/', $cookie)['status'], "\n";
        echo 'stranger=', fetch($port, $urls[3] ?? '/', 'via_session_id=ffffffffffffffffffffffffffffffff')['status'], "\n";

        @unlink($file);
        $app->getServer()?->shutdown();
    });
}, 200);

$app->start();
