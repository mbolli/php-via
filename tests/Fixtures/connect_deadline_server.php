<?php

declare(strict_types=1);

/*
 * Real-server fixture for Config::withContextConnectTimeout(): a context with no SSE stream on its
 * worker is destroyed after the timeout. Cases: page (one worker), off (timeout 0), xworker (two
 * workers, a copy an action rebuilt on the worker the tab does not stream from).
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

$mode = (string) ($argv[1] ?? 'page');
$marker = sys_get_temp_dir() . '/via_connect_deadline_' . getmypid();
@unlink($marker);

// enable_reuse_port would let a second server share a busy port without an error.
for ($i = 0; $i < 150; ++$i) {
    $port = 4800 + ((getmypid() + $i) % 150);
    $probe = @stream_socket_server("tcp://127.0.0.1:{$port}");
    if ($probe !== false) {
        fclose($probe);

        break;
    }
}

$config = (new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')
    ->withContextConnectTimeout($mode === 'off' ? 0 : ($mode === 'xworker' ? 400 : 300))
;
if ($mode === 'xworker') {
    $config = $config->withWorkerNum(2)->withBroker(new SwooleBroker());
}
$app = new Via($config);

$app->page('/room', function (Context $c) use ($marker): void {
    $hit = $c->action(static function (): void {}, 'hit');
    $c->onDisconnect(static function (Context $c) use ($marker): void {
        file_put_contents($marker, "cleanup {$c->getId()}\n", FILE_APPEND | LOCK_EX);
    });
    $c->view(fn (): string => '<div id="v">CTX:' . $c->getId() . ':URL:' . $hit->url() . ':END</div>', cacheUpdates: false);
});

// The /room contexts the serving worker holds, and its pid.
$app->page('/ctx', function (Context $c) use ($app): void {
    $c->view(static function () use ($app): string {
        $ids = [];
        foreach ($app->contexts as $id => $context) {
            if ($context->getRoute() === '/room') {
                $ids[] = $id;
            }
        }

        return 'IDS:' . implode(',', $ids) . ':PID:' . getmypid() . ':END';
    });
});

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
            [, $body] = request($sock, "GET /ctx HTTP/1.1\r\nHost: 127.0.0.1\r\n");
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

/** @return list<string> the /room context ids the worker with pid $pid holds */
function idsOn(int $port, int $pid): array {
    [$sock] = socketOn($port, $pid, false);
    [, $body] = request($sock, "GET /ctx HTTP/1.1\r\nHost: 127.0.0.1\r\n");
    fclose($sock);
    preg_match('/IDS:(.*?):PID:/', $body, $m);

    return array_values(array_filter(explode(',', $m[1] ?? '')));
}

/** @return array{0: string, 1: string, 2: string} context id, session cookie and action URL of a page load on $sock */
function loadRoom(mixed $sock): array {
    fwrite($sock, "GET /room HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n") && !feof($sock)) {
        $raw .= (string) fread($sock, 1);
    }
    $cookie = '';
    if (preg_match_all('/^set-cookie:\s*([^;\r\n]+)/im', $raw, $c)) {
        $cookie = implode('; ', $c[1]);
    }
    $content = '';
    if (preg_match('/content-length:\s*(\d+)/i', $raw, $m)) {
        while (strlen($content) < (int) $m[1] && !feof($sock)) {
            $content .= (string) fread($sock, (int) $m[1] - strlen($content));
        }
    }
    preg_match('/CTX:(.+?):URL:(.+?):END/', $content, $m);

    return [$m[1] ?? '', $cookie, html_entity_decode($m[2] ?? '')];
}

function ssePath(string $contextId): string {
    return '/_sse?datastar=' . rawurlencode((string) json_encode(['via_ctx' => $contextId]));
}

function postAction(mixed $sock, int $port, string $url, string $cookie, string $id): int {
    $body = (string) json_encode(['via_ctx' => $id]);
    [$status] = request($sock, "POST {$url} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\n"
        . "Cookie: {$cookie}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n", $body);

    return $status;
}

$app->setInterval(static function () use ($app, $port, $mode, $marker): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    Coroutine::create(static function () use ($app, $port, $mode, $marker): void {
        try {
            if ($mode === 'page' || $mode === 'off') {
                [$sock, $pid] = socketOn($port, 0, false);
                [$idle] = loadRoom($sock);
                [$connected, $cookie] = loadRoom($sock);
                $sse = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
                fwrite($sse, 'GET ' . ssePath($connected) . " HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: {$cookie}\r\n\r\n");
                Coroutine::usleep(700_000);

                $ids = idsOn($port, $pid);
                echo 'idle_kept=', (int) in_array($idle, $ids, true), "\n";
                echo 'connected_kept=', (int) in_array($connected, $ids, true), "\n";
                echo 'idle_cleanup_ran=', (int) str_contains((string) @file_get_contents($marker), "cleanup {$idle}"), "\n";
                fclose($sse);
                fclose($sock);

                return;
            }

            // xworker: the tab streams from worker A and acts through worker B.
            [$page, $pidPage] = socketOn($port, 0, false);
            [$id, $cookie, $url] = loadRoom($page);
            [$onA, $pidA] = socketOn($port, $pidPage, false);
            fwrite($onA, 'GET ' . ssePath($id) . " HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: {$cookie}\r\n\r\n");
            Coroutine::usleep(200_000);

            [$onB, $pidB] = socketOn($port, $pidA, true);
            $statuses = [];
            for ($i = 0; $i < 3; ++$i) {
                $statuses[] = postAction($onB, $port, $url, $cookie, $id);
                Coroutine::usleep(100_000);
            }
            echo 'workers=', (int) ($pidA !== $pidB), "\n";
            echo 'actions=', implode(',', $statuses), "\n";
            echo 'copy_after_action=', (int) in_array($id, idsOn($port, $pidB), true), "\n";

            // Actions every 200 ms keep the copy past several 400 ms timeouts.
            for ($i = 0; $i < 5; ++$i) {
                $statuses[] = postAction($onB, $port, $url, $cookie, $id);
                Coroutine::usleep(200_000);
            }
            echo 'copy_kept_while_active=', (int) in_array($id, idsOn($port, $pidB), true), "\n";

            Coroutine::usleep(900_000);
            echo 'copy_freed=', (int) !in_array($id, idsOn($port, $pidB), true), "\n";
            echo 'stream_kept=', (int) in_array($id, idsOn($port, $pidA), true), "\n";
            echo 'action_after_free=', postAction($onB, $port, $url, $cookie, $id), "\n";
            fclose($onB);
            fclose($onA);
            fclose($page);
        } catch (Throwable $e) {
            echo 'error=', str_replace("\n", ' ', $e->getMessage()), "\n";
        } finally {
            @unlink($marker);
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
