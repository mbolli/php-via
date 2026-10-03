<?php

declare(strict_types=1);

/*
 * Fixture for DevBarStatsTest: a real server with the Dev Bar on outside dev mode, asked for /_via/stats before and
 * after a page load, and with a POST.
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use Tests\Support\FixturePort;

$port = FixturePort::pick(5800, 100);
$app = new Via((new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withDevMode(false)->withDevBar(true));

$app->page('/p', static function (Context $c): void {
    $c->view(static fn (): string => '<p id="v">hi</p>');
});

/** @return array{0: int, 1: string, 2: string} status, head and body of one request on its own connection */
function devBarStatsRequest(int $port, string $method, string $path): array {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
    fwrite($sock, "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($sock)) {
        $raw .= (string) fread($sock, 65536);
    }
    fclose($sock);
    [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
    preg_match('/^HTTP\/1\.1 (\d+)/', $head, $status);
    if (stripos($head, 'transfer-encoding: chunked') !== false) {
        $decoded = '';
        while (preg_match('/^([0-9a-f]+)\r\n/i', $body, $m) === 1 && ($size = hexdec($m[1])) > 0) {
            $decoded .= substr($body, strlen($m[0]), (int) $size);
            $body = substr($body, strlen($m[0]) + (int) $size + 2);
        }
        $body = $decoded;
    }

    return [(int) ($status[1] ?? 0), $head, $body];
}

function devBarStatsDrive(int $port): void {
    [$status, $head, $body] = devBarStatsRequest($port, 'GET', '/_via/stats');
    $before = json_decode($body, true);
    echo 'status=', $status, "\n";
    echo 'json=', (int) (stripos($head, 'content-type: application/json') !== false), "\n";
    echo 'no_store=', (int) (stripos($head, 'cache-control: no-store') !== false), "\n";
    echo 'keys=', implode(',', array_keys(is_array($before) ? $before : [])), "\n";
    echo 'stats_keys=', implode(',', array_keys($before['stats'] ?? [])), "\n";
    echo 'runtime_keys=', implode(',', array_keys($before['runtime'] ?? [])), "\n";
    echo 'hook_flags=', (int) ($before['runtime']['hook_flags'] ?? 0) === Via::defaultHookFlags() ? 1 : 0, "\n";
    echo 'hook_names=', implode(',', $before['hook_flag_names'] ?? []), "\n";
    echo 'tick_ms=', $before['broadcast_tick_ms'] ?? -1, "\n";

    devBarStatsRequest($port, 'GET', '/p');
    [, , $body] = devBarStatsRequest($port, 'GET', '/_via/stats');
    $after = json_decode($body, true);
    echo 'requests_grew=', ($after['stats']['requests'] ?? 0) - ($before['stats']['requests'] ?? 0), "\n";
    echo 'contexts=', $after['stats']['active_contexts'] ?? -1, "\n";
    echo 'post=', devBarStatsRequest($port, 'POST', '/_via/stats')[0], "\n";
}

$app->setInterval(static function () use ($app, $port): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port): void {
        try {
            devBarStatsDrive($port);
        } catch (Throwable $e) {
            echo 'error=', str_replace("\n", ' ', $e->getMessage()), "\n";
        } finally {
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
