<?php

declare(strict_types=1);

/*
 * Fixture for TestAppParityTest: one page, driven through Testing\TestApp and through a real server
 * over HTTP, and the patches each sends after the connect and after each action.
 *
 * argv[1] = serve <port>: the real server, started by this script itself.
 * Without arguments it prints "harness <step> <patch>" and "server <step> <patch>" lines, and key=value
 * lines for problems.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE');
unset($_ENV['VIA_TEST_MODE'], $_SERVER['VIA_TEST_MODE']);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\PatchMode;
use Mbolli\PhpVia\Support\SignalId;
use Mbolli\PhpVia\Testing\SseReader;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use Tests\Support\FixturePort;

function parityRoutes(Via $via): void {
    $via->page('/parity', static function (Context $c): void {
        $count = $c->signal(0, 'count');
        $title = $c->signal('', 'title');
        $c->action(static function () use ($c, $count): void {
            $count->setValue($count->int() + 1);
            $c->sync();
        }, 'increment');
        $c->action(static function () use ($c, $title): void {
            $c->patchElements('<li>' . htmlspecialchars($title->string()) . '</li>', '#log', PatchMode::Append);
            $c->execScript('console.log("added")');
            $title->setValue('');
        }, 'add');
        $c->view(static fn (): string => '<main id="parity"><p>Count: ' . $count->int() . '</p><ul id="log"></ul></main>');
    });
}

/** The steps both sides run: an action name and the signals the browser changed first, null for the connect. */
const PARITY_STEPS = [
    ['connect', null, []],
    ['increment', 'increment', []],
    ['add', 'add', ['title' => 'tea']],
    ['increment2', 'increment', []],
];

/**
 * @param array{type: string, html?: string, selector?: ?string, mode?: PatchMode, signals?: array<string, mixed>} $patch
 */
function parityLine(array $patch): string {
    if ($patch['type'] === 'elements') {
        return 'elements ' . $patch['mode']->value . ' ' . ($patch['selector'] ?? '-') . ' ' . str_replace("\n", '\n', $patch['html']);
    }
    $signals = $patch['signals'];
    ksort($signals);

    return 'signals ' . json_encode($signals);
}

if (($argv[1] ?? '') === 'serve') {
    $app = new Via((new Config())->withHost('127.0.0.1')->withPort((int) $argv[2])->withLogLevel('error'));
    parityRoutes($app);
    $app->start();

    exit(0);
}

// The harness.
$harness = new TestApp((new Config())->withLogLevel('error'), parityRoutes(...));
$tab = null;
foreach (PARITY_STEPS as [$step, $action, $signals]) {
    if ($action === null) {
        $tab = $harness->open('/parity');
    } else {
        $tab->action($action, signals: $signals);
    }
    foreach ($tab->patches() as $patch) {
        echo 'harness ', $step, ' ', parityLine($patch), "\n";
    }
}
$harness->shutdown();

// The real server.
$port = FixturePort::pick(4700, 100);
$server = proc_open(
    ['timeout', '30', PHP_BINARY, __FILE__, 'serve', (string) $port],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);
if ($server === false) {
    echo "fixture_error=cannot start the server\n";

    exit(1);
}

/**
 * One request on a connection of its own.
 *
 * @return array{status: int, headers: string, body: string}
 */
function parityHttp(int $port, string $method, string $path, string $cookie = '', string $body = ''): array {
    $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
    if ($sock === false) {
        throw new RuntimeException("connect: {$error}");
    }
    fwrite($sock, "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\nConnection: close\r\n"
        . ($cookie !== '' ? "Cookie: {$cookie}\r\n" : '') . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    stream_set_timeout($sock, 5);
    $raw = (string) stream_get_contents($sock);
    fclose($sock);
    [$head, $payload] = explode("\r\n\r\n", $raw, 2) + [1 => ''];
    if (stripos($head, 'transfer-encoding: chunked') !== false) {
        $payload = parityDechunk($payload)[0];
    }

    return ['status' => (int) substr($head, 9, 3), 'headers' => $head, 'body' => $payload];
}

/**
 * The data of the complete chunks in $raw, and what is left.
 *
 * @return array{0: string, 1: string}
 */
function parityDechunk(string $raw): array {
    $data = '';
    while (($eol = strpos($raw, "\r\n")) !== false) {
        $size = hexdec(substr($raw, 0, $eol));
        if (strlen($raw) < $eol + 2 + $size + 2) {
            break;
        }
        $data .= substr($raw, $eol + 2, (int) $size);
        $raw = substr($raw, $eol + 2 + (int) $size + 2);
    }

    return [$data, $raw];
}

/** What the stream sends until it is quiet for 300 ms. */
function parityReadStream(mixed $sock, string &$pending): string {
    [$data, $pending] = parityDechunk($pending);
    $deadline = microtime(true) + 5;
    $quietSince = microtime(true);
    while (microtime(true) < $deadline && microtime(true) - $quietSince < 0.3) {
        $read = [$sock];
        $write = $except = null;
        if (stream_select($read, $write, $except, 0, 50_000) > 0) {
            $chunk = (string) fread($sock, 65536);
            if ($chunk === '') {
                break;
            }
            [$more, $pending] = parityDechunk($pending . $chunk);
            $data .= $more;
            $quietSince = microtime(true);
        }
    }

    return $data;
}

try {
    $up = false;
    for ($i = 0; $i < 100 && !$up; ++$i) {
        usleep(50_000);
        $probe = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 1);
        if ($probe !== false) {
            fclose($probe);
            $up = true;
        }
    }
    if (!$up) {
        throw new RuntimeException("the server did not listen on {$port}");
    }

    $page = parityHttp($port, 'GET', '/parity');
    preg_match('/Set-Cookie: (via_session_id=[0-9a-f]+)/i', $page['headers'], $cookie);
    preg_match('/"via_ctx":"([^"]+)"/', html_entity_decode($page['body']), $ctx);
    preg_match('/data-signals__ifmissing="([^"]*)"/', $page['body'], $seed);
    $contextId = $ctx[1] ?? throw new RuntimeException('no via_ctx in the page');
    $cookie = $cookie[1] ?? throw new RuntimeException('no session cookie');
    $names = [SignalId::tab(null, 'count', $contextId) => 'count', SignalId::tab(null, 'title', $contextId) => 'title'];
    $ids = array_flip($names);
    $held = ['via_ctx' => $contextId] + (array) json_decode(html_entity_decode($seed[1] ?? '{}'), true);

    $stream = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
    fwrite($stream, 'GET /_sse?datastar=' . rawurlencode((string) json_encode($held)) . " HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nCookie: {$cookie}\r\nAccept: text/event-stream\r\n\r\n");
    stream_set_blocking($stream, false);
    $pending = '';
    $headSeen = false;
    $reader = new SseReader();

    foreach (PARITY_STEPS as [$step, $action, $signals]) {
        if ($action !== null) {
            foreach ($signals as $name => $value) {
                $held[$ids[$name]] = $value;
            }
            $posted = parityHttp($port, 'POST', '/_action/' . $action, $cookie, (string) json_encode(array_filter($held, static fn (string $k): bool => !str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY)));
            if ($posted['status'] !== 200) {
                throw new RuntimeException("{$action} answered {$posted['status']}");
            }
        }

        if (!$headSeen) {
            // The response head comes unchunked, before the first chunk.
            $deadline = microtime(true) + 5;
            while (!str_contains($pending, "\r\n\r\n") && microtime(true) < $deadline) {
                $pending .= (string) fread($stream, 65536);
                usleep(10_000);
            }
            $pending = explode("\r\n\r\n", $pending, 2)[1] ?? '';
            $headSeen = true;
        }

        foreach ($reader->read(parityReadStream($stream, $pending)) as $patch) {
            if ($patch['type'] === 'signals') {
                $held = array_merge($held, $patch['signals']);
                $byName = [];
                foreach ($patch['signals'] as $id => $value) {
                    $byName[$names[$id] ?? $id] = $value;
                }
                $patch = ['type' => 'signals', 'signals' => $byName];
            }
            echo 'server ', $step, ' ', parityLine($patch), "\n";
        }
    }
    fclose($stream);
} catch (Throwable $e) {
    echo 'fixture_error=', $e->getMessage(), "\n";
} finally {
    $status = proc_get_status($server);
    // timeout(1) passes SIGINT to the server's master, which stops its workers.
    proc_terminate($server, SIGINT);
    for ($i = 0; $i < 100 && proc_get_status($server)['running']; ++$i) {
        usleep(50_000);
    }
    if (proc_get_status($server)['running']) {
        proc_terminate($server, SIGKILL);
        echo 'fixture_error=the server did not stop on SIGINT (pid ', $status['pid'], ")\n";
    }
    proc_close($server);
}
