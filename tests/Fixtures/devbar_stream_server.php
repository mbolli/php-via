<?php

declare(strict_types=1);

/*
 * Fixture for DevBarHookFlagsTest: a real server under the given hook_flags with one Dev Bar
 * stream open, probed by a forked client process that keeps working when the worker freezes.
 *
 * argv[1] = hook_flags
 * argv[2] = br: run h2c with withBrotli() and ask for the asset with Accept-Encoding: br
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Tests\Support\FixturePort;

$hookFlags = (int) ($argv[1] ?? Via::noFileIoHookFlags());
$brotli = ($argv[2] ?? '') === 'br';

try {
    $port = FixturePort::pick(4300, 30);
} catch (RuntimeException $e) {
    echo "fixture_error=no_free_port\n";

    exit(1);
}

/**
 * One request on a fresh connection, read until the server closes it or $timeout passes.
 *
 * @param array<string, string> $headers
 *
 * @return array{status: int, headers: array<string, string>, body: string, ms: int}
 */
function probeRequest(int $port, string $path, array $headers = [], float $timeout = 1.5): array {
    $start = microtime(true);
    $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, $timeout);
    if ($sock === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'ms' => (int) ((microtime(true) - $start) * 1000)];
    }

    $extra = '';
    foreach ($headers as $name => $value) {
        $extra .= "{$name}: {$value}\r\n";
    }
    fwrite($sock, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\n{$extra}Connection: close\r\n\r\n");

    $raw = '';
    stream_set_timeout($sock, 0, 50_000);
    while (microtime(true) - $start < $timeout && !feof($sock)) {
        $raw .= (string) fread($sock, 65536);
    }
    fclose($sock);

    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    $status = (int) (explode(' ', $lines[0])[1] ?? 0);
    $parsed = [];
    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $parsed[strtolower(trim($name))] = trim($value);
    }

    return ['status' => $status, 'headers' => $parsed, 'body' => $body, 'ms' => (int) ((microtime(true) - $start) * 1000)];
}

function report(string $key, int|string $value): void {
    fwrite(STDOUT, "{$key}={$value}\n");
}

$master = getmypid();
$pid = pcntl_fork();
if ($pid === -1) {
    echo "fixture_error=fork_failed\n";

    exit(1);
}

if ($pid === 0) {
    // Client. It runs outside the server, so a frozen worker cannot stop it from reporting.
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && ($s = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 0.2)) === false) {
        usleep(50_000);
    }
    if (isset($s) && $s !== false) {
        fclose($s);
    }

    $stream = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 2);
    if ($stream !== false) {
        fwrite($stream, "GET /_via/stream HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\n\r\n");
    }
    // Several poll intervals, so the stream sits in its idle loop before the probes start.
    usleep(300_000);

    $okCount = 0;
    $maxMs = 0;
    for ($i = 0; $i < 3; ++$i) {
        $r = probeRequest($port, '/_health');
        $okCount += $r['status'] === 200 ? 1 : 0;
        $maxMs = max($maxMs, $r['ms']);
    }
    report('health_ok', $okCount);
    report('health_max_ms', $maxMs);

    report('page_status', probeRequest($port, '/')['status']);

    // The page request above opened a trace, which the stream must deliver.
    $traced = 0;
    if ($stream !== false) {
        $raw = '';
        $until = microtime(true) + 2;
        stream_set_timeout($stream, 0, 50_000);
        while (microtime(true) < $until && !str_contains($raw, 'event: trace')) {
            $raw .= (string) fread($stream, 65536);
        }
        $traced = str_contains($raw, 'event: trace') ? 1 : 0;
        fclose($stream);
    }
    report('stream_traced', $traced);

    $asset = probeRequest($port, '/_via/devbar.js', $brotli ? ['Accept-Encoding' => 'br'] : []);
    $body = ($asset['headers']['content-encoding'] ?? '') === 'br' ? (string) brotli_uncompress($asset['body']) : $asset['body'];
    report('asset_status', $asset['status']);
    report('asset_etag', $asset['headers']['etag'] ?? '');
    report('asset_encoding', $asset['headers']['content-encoding'] ?? 'identity');
    report('asset_match', (int) ($body === file_get_contents(dirname(__DIR__, 2) . '/public/devbar.js')));

    $revalidated = probeRequest($port, '/_via/devbar.js', ['If-None-Match' => $asset['headers']['etag'] ?? '""']);
    report('revalidate_status', $revalidated['status']);
    report('revalidate_bytes', strlen($revalidated['body']));

    report('client_done', 1);
    // A frozen worker ignores SIGTERM until the manager kills it after max_wait_time.
    posix_kill($master, SIGTERM);

    exit(0);
}

$config = (new Config())
    ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')
    ->withDevBar(true)->withDevBarOptions(pollMs: 20)
    ->withSwooleSettings(['hook_flags' => $hookFlags])
;
if ($brotli) {
    $config = $config->withBrotli()->withH2c();
}

$app = new Via($config);
$app->page('/', function (Context $c): void {
    $c->view(static fn (): string => '<div id="v">ok</div>');
});

$app->start();
pcntl_waitpid($pid, $status);
