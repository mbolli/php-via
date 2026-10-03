<?php

declare(strict_types=1);

/*
 * Real-server fixture for static Brotli, probed by a forked client process that keeps measuring while a worker
 * would block.
 *
 * argv[1] = mode:
 *   boot     without withBrotli(): the files in the static dir at start, and /datastar.js, are sent at level 11
 *            from the first request, with nothing compressed in the request
 *   later    with withBrotli(): files written after start are answered at once (level 4 when small, uncompressed
 *            when big, not cacheable) while a helper compresses them at level 11, and /_health keeps answering
 *            meanwhile
 *   workers  as later, with two workers
 *   sidecar  a fresh .br sidecar is sent as it is and a stale one is ignored
 *   head     HEAD on static files, the framework's bundles and the Dev Bar assets answers as GET does, with no body
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Tests\Support\FixturePort;

$mode = (string) ($argv[1] ?? 'boot');

try {
    $port = FixturePort::pick(4330, 20);
} catch (RuntimeException $e) {
    echo "fixture_error=no_free_port\n";

    exit(1);
}

$dir = sys_get_temp_dir() . '/via-static-br-' . getmypid();
@mkdir($dir);

/** JavaScript that compresses like a real bundle: about 1.8 ms per KB at level 11. */
function generatedJs(int $bytes, int $seed): string {
    $words = ['signal', 'action', 'scope', 'render', 'patch', 'stream', 'worker', 'context', 'session', 'broker', 'route', 'view', 'cache', 'brotli', 'static', 'module'];
    $out = '';
    for ($i = 0; strlen($out) < $bytes; ++$i) {
        $hash = crc32("{$seed}:{$i}");
        $out .= sprintf(
            "export const %s%d = {name: \"%s-%x\", size: %d, tags: [\"%s\", \"%s\"]};\n",
            $words[$hash & 15],
            $i,
            $words[($hash >> 4) & 15],
            crc32("{$i}:{$seed}"),
            ($hash >> 8) % 100000,
            $words[($hash >> 20) & 15],
            $words[($hash >> 24) & 15],
        );
    }

    return substr($out, 0, $bytes);
}

/**
 * One request on a fresh connection.
 *
 * @param array<string, string> $headers
 *
 * @return array{status: int, headers: array<string, string>, body: string, ms: float}
 */
function probeRequest(int $port, string $path, array $headers = [], float $timeout = 10.0): array {
    $start = microtime(true);
    $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, $timeout);
    if ($sock === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'ms' => (microtime(true) - $start) * 1000];
    }
    $extra = '';
    foreach ($headers as $name => $value) {
        $extra .= "{$name}: {$value}\r\n";
    }
    fwrite($sock, "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1\r\n{$extra}Connection: close\r\n\r\n");
    stream_set_timeout($sock, (int) $timeout, (int) (fmod($timeout, 1.0) * 1_000_000));
    $raw = (string) stream_get_contents($sock);
    fclose($sock);

    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    $parsed = [];
    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $parsed[strtolower(trim($name))] = trim($value);
    }

    return ['status' => (int) (explode(' ', $lines[0])[1] ?? 0), 'headers' => $parsed, 'body' => $body, 'ms' => (microtime(true) - $start) * 1000];
}

/**
 * Requests written at once on one connection, and everything the server sends back until it closes.
 *
 * @param list<string> $requests "METHOD /path" with optional extra header lines after a newline
 */
function rawExchange(int $port, array $requests): string {
    $sock = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
    if ($sock === false) {
        return '';
    }
    $last = count($requests) - 1;
    $out = '';
    foreach ($requests as $i => $request) {
        [$line, $extra] = array_pad(explode("\n", $request, 2), 2, '');
        $out .= "{$line} HTTP/1.1\r\nHost: 127.0.0.1\r\n" . ($extra !== '' ? str_replace("\n", "\r\n", $extra) . "\r\n" : '')
            . ($i === $last ? "Connection: close\r\n" : '') . "\r\n";
    }
    fwrite($sock, $out);
    stream_set_timeout($sock, 5);
    $raw = (string) stream_get_contents($sock);
    fclose($sock);

    return $raw;
}

/**
 * One response on a connection that closes after it: status, headers, and every byte after them.
 *
 * @return array{status: int, headers: array<string, string>, rest: string}
 */
function parseResponse(string $raw): array {
    [$head, $rest] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    $lines = explode("\r\n", $head);
    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($name))] = trim($value);
    }

    return ['status' => (int) (explode(' ', $lines[0])[1] ?? 0), 'headers' => $headers, 'rest' => $rest];
}

function report(string $key, float|int|string $value): void {
    fwrite(STDOUT, "{$key}={$value}\n");
}

/** @var array<string, array<int, string>> brotli_compress() output by contents hash and level */
$compressed = [];

/**
 * Which form a response carries: identity, l4 or l11 (the bytes brotli_compress() gives at that level), or br.
 *
 * @param array{headers: array<string, string>, body: string} $response
 */
function form(array $response, string $contents): string {
    global $compressed;
    if (($response['headers']['content-encoding'] ?? '') !== 'br') {
        return $response['body'] === $contents ? 'identity' : 'wrong';
    }
    if (brotli_uncompress($response['body']) !== $contents) {
        return 'wrong';
    }
    $key = md5($contents);
    $compressed[$key][11] ??= (string) brotli_compress($contents, 11, BROTLI_TEXT);
    $compressed[$key][4] ??= (string) brotli_compress($contents, 4, BROTLI_TEXT);

    return match ($response['body']) {
        $compressed[$key][11] => 'l11',
        $compressed[$key][4] => 'l4',
        default => 'br',
    };
}

$boot = generatedJs(60 << 10, 1);
$small = generatedJs(40 << 10, 2);
$big = generatedJs(700 << 10, 3);
file_put_contents("{$dir}/boot.js", $boot);

if ($mode === 'head') {
    // Over 2 MiB, so GET sends both with sendfile(): the image as it is, the stylesheet's sidecar to Brotli clients.
    file_put_contents("{$dir}/big.png", random_bytes(2_200_000));
    file_put_contents("{$dir}/big.css", generatedJs(300 << 10, 4));
    file_put_contents("{$dir}/big.css.br", random_bytes(2_200_000));
}

if ($mode === 'sidecar') {
    file_put_contents("{$dir}/fresh.js", $big);
    file_put_contents("{$dir}/fresh.js.br", brotli_compress($big, 5, BROTLI_TEXT));
    file_put_contents("{$dir}/stale.js", $small);
    file_put_contents("{$dir}/stale.js.br", brotli_compress('old contents', 5, BROTLI_TEXT));
    touch("{$dir}/stale.js.br", time() - 60);
}

$master = getmypid();
$pid = pcntl_fork();
if ($pid === -1) {
    echo "fixture_error=fork_failed\n";

    exit(1);
}

if ($pid === 0) {
    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline && probeRequest($port, '/_health', [], 0.5)['status'] !== 200) {
        usleep(50_000);
    }

    if ($mode === 'boot') {
        $first = probeRequest($port, '/boot.js', ['Accept-Encoding' => 'br']);
        report('boot_form', form($first, $boot));
        report('boot_ms', round($first['ms'], 1));
        report('boot_vary', $first['headers']['vary'] ?? '');
        report('datastar_form', form(probeRequest($port, '/datastar.js', ['Accept-Encoding' => 'br']), (string) file_get_contents(dirname(__DIR__, 2) . '/public/datastar.js')));
        report('plain_form', form(probeRequest($port, '/boot.js'), $boot));
    } elseif ($mode === 'head') {
        $mismatches = [];
        $cases = 0;
        foreach (['/boot.js', '/datastar.js', '/datastar.js?v=1', '/via.css', '/_via/devbar.js', '/_via/devbar.css', '/big.png', '/big.css'] as $path) {
            foreach (['', "\nAccept-Encoding: br"] as $extra) {
                $get = parseResponse(rawExchange($port, ["GET {$path}{$extra}"]));
                $head = parseResponse(rawExchange($port, ["HEAD {$path}{$extra}"]));
                ++$cases;
                $label = $path . ($extra !== '' ? ' br' : '');
                if ($get['status'] !== 200 || $head['status'] !== 200) {
                    $mismatches[] = "{$label}: status GET {$get['status']} HEAD {$head['status']}";

                    continue;
                }
                foreach (['etag', 'vary', 'cache-control', 'content-encoding', 'content-type', 'last-modified'] as $name) {
                    if (($get['headers'][$name] ?? null) !== ($head['headers'][$name] ?? null)) {
                        $mismatches[] = "{$label}: {$name} GET " . ($get['headers'][$name] ?? '-') . ' HEAD ' . ($head['headers'][$name] ?? '-');
                    }
                }
                if (($head['headers']['content-length'] ?? '') !== (string) strlen($get['rest'])) {
                    $mismatches[] = "{$label}: content-length HEAD " . ($head['headers']['content-length'] ?? '-') . ' GET body ' . strlen($get['rest']);
                }
                if ($head['rest'] !== '') {
                    $mismatches[] = "{$label}: HEAD sent " . strlen($head['rest']) . ' body bytes';
                }
            }
        }
        report('head_cases', $cases);
        report('head_mismatches', $mismatches === [] ? 'none' : implode(' | ', $mismatches));

        // On a kept-alive connection, a body after HEAD would be read as the next response.
        $raw = rawExchange($port, ["HEAD /boot.js\nAccept-Encoding: br", 'GET /_health']);
        $first = parseResponse($raw);
        report('head_then_get', str_starts_with($first['rest'], 'HTTP/1.1 200') ? 'ok' : substr($first['rest'], 0, 40));

        $etag = parseResponse(rawExchange($port, ['HEAD /boot.js']))['headers']['etag'] ?? '';
        $notModified = parseResponse(rawExchange($port, ["HEAD /boot.js\nIf-None-Match: {$etag}"]));
        report('head_304', $notModified['status'] . ' ' . strlen($notModified['rest']));
        report('head_route', parseResponse(rawExchange($port, ['HEAD /page']))['status']);
        report('head_missing', parseResponse(rawExchange($port, ['HEAD /missing.js']))['status']);
    } elseif ($mode === 'sidecar') {
        $fresh = probeRequest($port, '/fresh.js', ['Accept-Encoding' => 'br']);
        report('fresh_sidecar', (int) ($fresh['body'] === file_get_contents("{$dir}/fresh.js.br")));
        report('fresh_ms', round($fresh['ms'], 1));
        report('stale_form', form(probeRequest($port, '/stale.js', ['Accept-Encoding' => 'br']), $small));
    } else {
        file_put_contents("{$dir}/small.js", $small);
        file_put_contents("{$dir}/big.js", $big);
        $first = probeRequest($port, '/big.js', ['Accept-Encoding' => 'br']);
        report('big_first', form($first, $big));
        report('big_first_ms', round($first['ms'], 1));
        report('big_first_cc', $first['headers']['cache-control'] ?? '');
        $first = probeRequest($port, '/small.js', ['Accept-Encoding' => 'br']);
        report('small_first', form($first, $small));
        report('small_first_ms', round($first['ms'], 1));
        report('small_first_cc', $first['headers']['cache-control'] ?? '');

        // Until both files come back at level 11 on several connections in a row (which reach both workers),
        // probe /_health every 20 ms.
        $healthMax = 0.0;
        $healthCount = 0;
        $streak = 0;
        $until = microtime(true) + 20;
        $readyMs = 0;
        $start = microtime(true);
        while (microtime(true) < $until && $streak < 6) {
            for ($i = 0; $i < 10; ++$i) {
                $health = probeRequest($port, '/_health', [], 5.0);
                $healthMax = max($healthMax, $health['ms']);
                ++$healthCount;
                usleep(20_000);
            }
            $bigNow = probeRequest($port, '/big.js', ['Accept-Encoding' => 'br']);
            $smallNow = probeRequest($port, '/small.js', ['Accept-Encoding' => 'br']);
            $done = form($bigNow, $big) === 'l11' && form($smallNow, $small) === 'l11';
            if ($done) {
                $finalCc = ($bigNow['headers']['cache-control'] ?? '') . ' | ' . ($smallNow['headers']['cache-control'] ?? '');
            }
            $streak = $done ? $streak + 1 : 0;
            if ($done && $readyMs === 0) {
                $readyMs = (int) ((microtime(true) - $start) * 1000);
            }
        }
        report('level11_everywhere', (int) ($streak >= 6));
        report('final_cc', $finalCc ?? '');
        report('level11_after_ms', $readyMs);
        report('health_probes', $healthCount);
        report('health_max_ms', round($healthMax, 1));
    }

    report('client_done', 1);
    posix_kill($master, SIGTERM);

    exit(0);
}

$config = (new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('info')->withStaticDir($dir);
if ($mode === 'later' || $mode === 'workers') {
    $config = $config->withBrotli()->withH2c();
}
if ($mode === 'workers') {
    $config = $config->withWorkerNum(2)->withBroker(new SwooleBroker());
}
if ($mode === 'head') {
    $config = $config->withTracing();
}

$app = new Via($config);
if ($mode === 'head') {
    $app->page('/page', static fn (Context $c) => $c->view(static fn (): string => '<div id="p">page</div>'));
}
$app->start();
pcntl_waitpid($pid, $status);
exec('rm -rf ' . escapeshellarg($dir));
