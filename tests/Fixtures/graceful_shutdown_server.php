<?php

declare(strict_types=1);

/*
 * Fixture for GracefulShutdownTest: a real multi-worker server that holds an SSE stream open to
 * itself, then signals its own master the way docker stop, systemctl stop or Ctrl-C would.
 *
 * Appends "shutdown <pid>" per onShutdown call and "disconnect <pid>" per onClientDisconnect call
 * to the marker file. Prints sse=open once the stream is up and sse=eof when the server ends it.
 *
 * argv[1] = worker count
 * argv[2] = TERM, INT (to the master), INTGRP (to the process group, run it under setsid),
 *           USR1 (reload, check the new workers serve, then TERM) or IDLE (TERM with no SSE
 *           stream, no interval and no GC timer)
 * argv[3] = marker file path
 * argv[4] = options as a query string: shutdownYieldMs, disconnectYieldMs
 *
 * onShutdown and onClientDisconnect yield first, then write, so a cut-off callback leaves no line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The real SSE loop needs the Channel-backed PatchManager, not the test-mode array.
putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Timer;

$workers = (int) ($argv[1] ?? 2);
$mode = (string) ($argv[2] ?? 'TERM');
$marker = (string) ($argv[3] ?? sys_get_temp_dir() . '/via_shutdown_marker');
parse_str((string) ($argv[4] ?? ''), $options);
$shutdownYieldMs = (int) ($options['shutdownYieldMs'] ?? 0);
$disconnectYieldMs = (int) ($options['disconnectYieldMs'] ?? 0);
$reloadFlag = $marker . '.reloaded';

$config = (new Config())
    ->withHost('127.0.0.1')->withPort(4000 + (getmypid() % 150))->withLogLevel('error')
    ->withWorkerNum($workers)->withBroker(new SwooleBroker())
;
if ($mode === 'IDLE') {
    $config = $config->withGcInterval(0);
}

$port = $config->getPort();
$app = new Via($config);

$app->page('/probe', function (Context $c): void {
    $c->view(fn (): string => 'CTX:' . $c->getId() . ':END');
});

$app->onShutdown(static function () use ($marker, $shutdownYieldMs): void {
    $cid = Coroutine::getCid();
    if ($shutdownYieldMs > 0) {
        Coroutine::usleep($shutdownYieldMs * 1000);
    }
    file_put_contents($marker, 'shutdown ' . getmypid() . " cid={$cid}\n", FILE_APPEND | LOCK_EX);
});

$app->onClientDisconnect(static function () use ($marker, $disconnectYieldMs): void {
    if ($disconnectYieldMs > 0) {
        Coroutine::usleep($disconnectYieldMs * 1000);
    }
    file_put_contents($marker, 'disconnect ' . getmypid() . "\n", FILE_APPEND | LOCK_EX);
});

$masterPid = static fn (): int => (int) $app->getServer()?->master_pid;

if ($mode === 'IDLE') {
    $app->onStart(static function () use ($app, $masterPid): void {
        if ($app->getServer()?->worker_id === 0) {
            Timer::after(300, static fn () => posix_kill($masterPid(), SIGTERM));
        }
    });
    $app->start();

    exit;
}

$app->setInterval(static function () use ($port, $mode, $marker, $reloadFlag, $workers, $masterPid): void {
    static $fired = false;
    if ($fired) {
        return;
    }
    $fired = true;

    if ($mode === 'USR1' && is_file($reloadFlag)) {
        // New leader after the reload: wait for every old worker's onShutdown, then probe and stop.
        Coroutine::create(static function () use ($port, $marker, $workers, $masterPid): void {
            for ($i = 0; $i < 100 && substr_count((string) @file_get_contents($marker), 'shutdown ') < $workers; ++$i) {
                Coroutine::usleep(50_000);
            }
            $client = new Client('127.0.0.1', $port);
            $client->set(['timeout' => 5]);
            $client->get('/probe');
            echo str_contains((string) $client->body, 'CTX:') ? "served=ok\n" : "served=failed\n";
            $client->close();
            posix_kill($masterPid(), SIGTERM);
        });

        return;
    }

    Coroutine::create(static function () use ($port, $mode, $reloadFlag, $masterPid): void {
        $client = new Client('127.0.0.1', $port);
        $client->set(['timeout' => 5]);
        $client->get('/probe');
        preg_match('/CTX:(.+?):END/', (string) $client->body, $m);
        $cookie = '';
        foreach ((array) ($client->set_cookie_headers ?? []) as $raw) {
            $cookie .= explode(';', (string) $raw)[0] . '; ';
        }
        $client->close();

        $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 5);
        if ($sock === false) {
            echo "sse=connect-failed\n";

            return;
        }
        $query = rawurlencode((string) json_encode(['via_ctx' => $m[1] ?? '']));
        fwrite($sock, "GET /_sse?datastar={$query} HTTP/1.1\r\nHost: 127.0.0.1\r\n"
            . "Accept: text/event-stream\r\nCookie: {$cookie}\r\nConnection: close\r\n\r\n");
        stream_set_timeout($sock, 5);
        $status = trim((string) fgets($sock));
        echo str_contains($status, ' 200 ') ? "sse=open\n" : "sse=failed {$status}\n";

        Coroutine::usleep(300_000);

        $master = $masterPid();
        if ($mode === 'USR1') {
            touch($reloadFlag);
        }
        match ($mode) {
            'INT' => posix_kill($master, SIGINT),
            'INTGRP' => posix_kill(-(int) posix_getpgid($master), SIGINT),
            'USR1' => posix_kill($master, SIGUSR1),
            default => posix_kill($master, SIGTERM),
        };

        // Hold the stream until the server ends it, so only the shutdown can close it.
        while (!feof($sock) && fread($sock, 8192) !== false) {
            if (stream_get_meta_data($sock)['timed_out']) {
                echo "sse=timeout\n";

                return;
            }
        }
        echo "sse=eof\n";
    });
}, 300);

$app->start();
