<?php

declare(strict_types=1);

/*
 * Fixture for ForwardingTest: requests of a tab that reach a worker other than the one holding its stream.
 *
 * argv[1] = worker count, argv[2] = mode:
 * - layouts: the browser layout over HTTP/1.1 (page and actions on one connection, the stream on another worker)
 *   and the curl layout (page and stream on one worker, actions on another), with a TAB counter, SESSION and ROUTE
 *   signals, a script, a cookie, a login that rotates the session, an upload and a download
 * - timeout: an action that outlasts withContextTimeouts(forwardMs: 1000), whose cookie goes out with the next response
 * - crash: the worker holding the tab dies during an action
 * - handover: the stream moves to another worker after an action ran on the old one, while one runs there, and after
 *   the old one sent a server-owned value
 * - norevival: withContextTimeouts(revivalWindowMs: 0), actions from another worker before and after the stream
 *   opened on the page's worker
 *
 * Prints key=value lines.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=');

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response as Psr7Response;
use OpenSwoole\Coroutine;
use OpenSwoole\Process;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FixturePort;

$workers = (int) ($argv[1] ?? 2);
$mode = (string) ($argv[2] ?? 'layouts');

$port = FixturePort::pick(5600, 100);
$config = (new Config())->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')->withWorkerNum($workers);
if ($mode === 'timeout') {
    $config->withContextTimeouts(forwardMs: 1000);
}
if ($mode === 'norevival') {
    $config->withContextTimeouts(revivalWindowMs: 0);
}
$app = new Via($config);

$app->page('/p', static function (Context $c) use ($app): void {
    $n = $c->signal(0, 'n');
    $own = $c->signal(0, 'own', clientWritable: false);
    $s = $c->signal(0, 's', Scope::SESSION);
    $r = $c->signal(0, 'r', Scope::ROUTE);
    $up = $c->signal('', 'up');
    $c->action(static function (Context $c) use ($n): void {
        $n->setValue($n->int() + 1);
        $c->sync();
    }, 'bump');
    $c->action(static fn () => $s->increment(), 'sess');
    $c->action(static fn () => $r->increment(), 'route');
    $c->action(static fn (Context $c) => $c->execScript('window.__forwarded = 1'), 'script');
    $c->action(static function (Context $c): void {
        $c->spawn(static function () use ($c): void {
            Coroutine::usleep(150_000);
            $c->execScript('window.__spawned = ' . getmypid());
        });
    }, 'spawn');
    $c->action(static fn (Context $c) => $c->setCookie('flavor', 'mint', secure: false), 'cookie');
    $c->action(static function (Context $c): void {
        $c->regenerateSession();
        $c->setSessionData('user', 'ada');
    }, 'login');
    $c->action(static function (Context $c) use ($up): void {
        $file = $c->file('doc');
        $dest = sys_get_temp_dir() . '/via-forwarded-' . bin2hex(random_bytes(6));
        $moved = $file !== null && move_uploaded_file($file['tmp_name'], $dest);
        $up->setValue($moved ? 'UP-' . filesize($dest) . '-' . md5_file($dest) : 'UP-failed');
        if ($moved) {
            unlink($dest);
        }
    }, 'upload');
    $c->action(static function (Context $c) use ($n): void {
        $c->setCookie('late', 'yes', secure: false);
        Coroutine::usleep(2_000_000);
        $n->setValue($n->int() + 100);
        $c->sync();
    }, 'slow');
    $c->action(static function (Context $c) use ($n, $own): void {
        $n->setValue(42);
        $own->setValue(7);
        $c->execScript('window.__init = 1');
    }, 'init');
    $c->action(static function (Context $c) use ($n): void {
        Coroutine::usleep(1_000_000);
        $n->setValue($n->int() + 100);
        $c->execScript('window.__moved = 1');
        $c->sync();
    }, 'slowmove');
    $c->action(static function () use ($app): void {
        $app->incrementGlobalState('died');
        Coroutine::usleep(300_000);
        Process::kill(getmypid(), SIGKILL);
    }, 'die');
    $download = $c->download(static fn (): string => 'DL-' . getmypid(), 'f.txt', 'text/plain');
    // Counts the handler runs of the tab on every worker, through the shared tab state.
    $c->setTabState('builds', (int) $c->tabState('builds', 0) + 1);
    $builds = (int) $c->tabState('builds');
    $query = (string) $c->input('q', '');
    $c->view(static fn (): string => '<p id="v">CTX:' . $c->getId() . ':N:' . $n->int() . ':OWN:' . $own->int() . ':S:' . $s->int() . ':R:' . $r->int()
        . ':PID:' . getmypid() . ':T:' . $builds . ':Q:' . $query . ':DL:' . $download . ':END</p>');
});

$app->route('GET', '/whoami', new class($app) implements RequestHandlerInterface {
    public function __construct(private Via $app) {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $held = (string) ($request->getQueryParams()['held'] ?? '');

        return new Psr7Response(200, [], 'PID:' . getmypid() . ':HELD:' . (int) isset($this->app->contexts[$held])
            . ':DIED:' . (int) $this->app->globalState('died', 0) . ':END');
    }
});

/**
 * One request on a keep-alive socket.
 *
 * @return array{0: int, 1: string, 2: string} status, head and body
 */
function fwdRequest(mixed $sock, string $head, string $body = ''): array {
    fwrite($sock, $head . "\r\n" . $body);

    return fwdResponse($sock);
}

/**
 * The next response on a keep-alive socket.
 *
 * @return array{0: int, 1: string, 2: string} status, head and body
 */
function fwdResponse(mixed $sock): array {
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

    return [(int) ($status[1] ?? 0), $raw, $content];
}

/** @return array{0: int, 1: bool, 2: int} the pid /whoami names, whether that worker holds $contextId, and how often 'die' ran */
function fwdWhoami(mixed $sock, string $contextId = ''): array {
    [, , $body] = fwdRequest($sock, 'GET /whoami?held=' . rawurlencode($contextId) . " HTTP/1.1\r\nHost: 127.0.0.1\r\n");
    preg_match('/PID:(\d+):HELD:(\d):DIED:(\d+):END/', $body, $m);

    return [(int) ($m[1] ?? 0), ($m[2] ?? '0') === '1', (int) ($m[3] ?? -1)];
}

/**
 * A keep-alive socket served by the worker with pid $pid, or by any other when $other, and the pid that serves it.
 *
 * @return array{0: resource, 1: int}
 */
function fwdSocketOn(int $port, int $pid, bool $other): array {
    // Rejected sockets stay open until the end: a closed one frees its fd, and dispatch goes by fd.
    static $rejected = [];
    for ($i = 0; $i < 40; ++$i) {
        $sock = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 5);
        [$served] = fwdWhoami($sock);
        if ($pid === 0 || ($served === $pid) !== $other) {
            return [$sock, $served];
        }
        $rejected[] = $sock;
    }

    throw new RuntimeException('no connection reached the wanted worker');
}

/** @return array{0: int, 1: string} status and the session cookie it sets ('' for none) */
function fwdAction(mixed $sock, int $port, string $action, string $contextId, string $cookie): array {
    $body = (string) json_encode(['via_ctx' => $contextId]);
    [$status, $head] = fwdRequest($sock, "POST /_action/{$action} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\n"
        . "Cookie: via_session_id={$cookie}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n", $body);
    preg_match('/set-cookie: via_session_id=([0-9a-f]+)/i', $head, $m);

    return [$status, $m[1] ?? '', $head];
}

/** Open a stream on $sock for the tab; returns what it sends at first. */
function fwdStream(mixed $sock, string $contextId, string $cookie): string {
    fwrite($sock, 'GET /_sse?datastar=' . rawurlencode((string) json_encode(['via_ctx' => $contextId]))
        . " HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nCookie: via_session_id={$cookie}\r\n\r\n");

    return fwdDrain($sock, 0.5);
}

/** What the stream sends within $seconds. */
function fwdDrain(mixed $sock, float $seconds): string {
    $out = '';
    $deadline = microtime(true) + $seconds;
    stream_set_timeout($sock, 0, 50_000);
    while (microtime(true) < $deadline && !feof($sock)) {
        $out .= (string) fread($sock, 65536);
    }

    return $out;
}

/**
 * Load the page on $sock.
 *
 * @return array{0: string, 1: string} context id and session cookie
 */
function fwdPage(mixed $sock): array {
    [, $head, $page] = fwdRequest($sock, "GET /p?q=hello HTTP/1.1\r\nHost: 127.0.0.1\r\n");
    preg_match('/CTX:(.+?):N:/', html_entity_decode($page), $m);
    preg_match('/set-cookie: via_session_id=([0-9a-f]+)/i', $head, $c);

    return [$m[1] ?? '', $c[1] ?? ''];
}

/** The last value of $field the stream's view frames show. */
function fwdLast(string $stream, string $field): string {
    preg_match_all('/:' . $field . ':([^:]*):/', $stream, $m);

    return $m[1] === [] ? '' : (string) end($m[1]);
}

function fwdLayouts(int $port): void {
    // The browser layout: page and actions on one connection, the stream on another worker.
    [$onA, $pidA] = fwdSocketOn($port, 0, false);
    [$contextId, $cookie] = fwdPage($onA);
    [$stream, $pidB] = fwdSocketOn($port, $pidA, true);
    $seen = fwdStream($stream, $contextId, $cookie);
    echo 'stream_open=', (int) str_starts_with($seen, 'HTTP/1.1 200'), "\n";
    echo 'stream_worker_other=', (int) ($pidB !== $pidA && fwdLast($seen, 'PID') === (string) $pidB), "\n";
    // The stream's worker rebuilt the tab with the page's query and the tab state the page's worker wrote.
    echo 'rebuilt=', fwdLast($seen, 'T'), ':', fwdLast($seen, 'Q'), "\n";

    echo 'bump1=', fwdAction($onA, $port, 'bump', $contextId, $cookie)[0], "\n";
    echo 'bump2=', fwdAction($onA, $port, 'bump', $contextId, $cookie)[0], "\n";
    $seen .= fwdDrain($stream, 0.4);
    echo 'stream_n=', fwdLast($seen, 'N'), "\n";

    fwdAction($onA, $port, 'script', $contextId, $cookie);
    fwdAction($onA, $port, 'sess', $contextId, $cookie);
    fwdAction($onA, $port, 'route', $contextId, $cookie);
    fwdAction($onA, $port, 'spawn', $contextId, $cookie);
    $seen .= fwdDrain($stream, 0.5);
    echo 'stream_script=', (int) str_contains($seen, 'window.__forwarded = 1'), "\n";
    echo 'stream_spawned=', (int) str_contains($seen, 'window.__spawned = ' . $pidB), "\n";
    echo 'stream_s=', fwdLast($seen, 'S'), "\n";
    echo 'stream_r=', fwdLast($seen, 'R'), "\n";

    [$cookieStatus, , $cookieHead] = fwdAction($onA, $port, 'cookie', $contextId, $cookie);
    echo 'cookie=', $cookieStatus, ':', (int) (preg_match('/set-cookie: flavor=mint/i', $cookieHead) === 1), "\n";

    [, $heldOnA] = fwdWhoami($onA, $contextId);
    [$onB] = fwdSocketOn($port, $pidB, false);
    [, $heldOnB] = fwdWhoami($onB, $contextId);
    echo 'held_page_worker=', (int) $heldOnA, "\n";
    echo 'held_stream_worker=', (int) $heldOnB, "\n";

    $content = str_repeat('forwarded upload ', 1000);
    $boundary = 'via' . bin2hex(random_bytes(6));
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"via_ctx\"\r\n\r\n{$contextId}\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"doc.txt\"\r\nContent-Type: text/plain\r\n\r\n{$content}\r\n--{$boundary}--\r\n";
    [$uploadStatus] = fwdRequest($onA, "POST /_action/upload HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\n"
        . "Cookie: via_session_id={$cookie}\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\nContent-Length: " . strlen($body) . "\r\n", $body);
    $seen .= fwdDrain($stream, 0.4);
    echo 'upload=', $uploadStatus, ':', (int) str_contains($seen, 'UP-' . strlen($content) . '-' . md5($content)), "\n";

    $download = fwdLast($seen, 'DL');
    [$downloadStatus, , $downloadBody] = fwdRequest($onA, "GET {$download} HTTP/1.1\r\nHost: 127.0.0.1\r\nCookie: via_session_id={$cookie}\r\n");
    echo 'download=', $downloadStatus, ':', (int) ($downloadBody === 'DL-' . $pidB), "\n";

    [$loginStatus, $rotated] = fwdAction($onA, $port, 'login', $contextId, $cookie);
    echo 'login=', $loginStatus, ':', (int) ($rotated !== '' && $rotated !== $cookie), "\n";
    echo 'bump_new_cookie=', fwdAction($onA, $port, 'bump', $contextId, $rotated)[0], "\n";
    $seen .= fwdDrain($stream, 0.4);
    echo 'stream_n_after_login=', fwdLast($seen, 'N'), "\n";
    fclose($stream);

    // The curl layout: page and stream on one worker, the actions on another.
    [$onC, $pidC] = fwdSocketOn($port, 0, false);
    [$contextId, $cookie] = fwdPage($onC);
    [$stream] = fwdSocketOn($port, $pidC, false);
    $seen = fwdStream($stream, $contextId, $cookie);
    [$onD] = fwdSocketOn($port, $pidC, true);
    echo 'curl_bump=', fwdAction($onD, $port, 'bump', $contextId, $cookie)[0], "\n";
    fwdAction($onD, $port, 'script', $contextId, $cookie);
    $seen .= fwdDrain($stream, 0.4);
    echo 'curl_stream_n=', fwdLast($seen, 'N'), "\n";
    echo 'curl_stream_script=', (int) str_contains($seen, 'window.__forwarded = 1'), "\n";
    fclose($stream);
}

function fwdTimeout(int $port): void {
    [$onA, $pidA] = fwdSocketOn($port, 0, false);
    [$contextId, $cookie] = fwdPage($onA);
    [$stream] = fwdSocketOn($port, $pidA, false);
    $seen = fwdStream($stream, $contextId, $cookie);
    [$onB] = fwdSocketOn($port, $pidA, true);

    $start = microtime(true);
    [$slowStatus, , $slowHead] = fwdAction($onB, $port, 'slow', $contextId, $cookie);
    echo 'slow=', $slowStatus, ':', (int) (microtime(true) - $start < 1.9), ':', (int) (preg_match('/set-cookie: late=/i', $slowHead) === 1), "\n";
    $seen .= fwdDrain($stream, 1.6);
    echo 'slow_finished=', fwdLast($seen, 'N'), "\n";
    [$bumpStatus, , $bumpHead] = fwdAction($onB, $port, 'bump', $contextId, $cookie);
    echo 'next=', $bumpStatus, ':', (int) (preg_match('/set-cookie: late=yes/i', $bumpHead) === 1), "\n";
    fclose($stream);
}

function fwdCrash(int $port): void {
    // The tab goes to a worker other than this driver's, which must outlive the crash.
    [$onA, $pidA] = fwdSocketOn($port, getmypid(), true);
    [$contextId, $cookie] = fwdPage($onA);
    [$stream] = fwdSocketOn($port, $pidA, false);
    fwdStream($stream, $contextId, $cookie);
    [$onB, $pidB] = fwdSocketOn($port, $pidA, true);

    $start = microtime(true);
    [$dieStatus] = fwdAction($onB, $port, 'die', $contextId, $cookie);
    echo 'die=', $dieStatus, ':', (int) (microtime(true) - $start < 5), "\n";
    // The home's replacement starts, and the tab, without a live home, goes to the worker its next action reaches.
    Coroutine::usleep(500_000);
    echo 'after=', fwdAction($onB, $port, 'bump', $contextId, $cookie)[0], "\n";
    [, $heldOnB, $died] = fwdWhoami($onB, $contextId);
    echo 'held_receiver=', (int) $heldOnB, "\n";
    echo 'died_once=', (int) ($died === 1), "\n";
    echo 'receiver_alive=', (int) Process::kill($pidB, 0), "\n";
}

function fwdHandover(int $port): void {
    // An action ran on the page's worker before the stream connected to another one.
    [$onA, $pidA] = fwdSocketOn($port, 0, false);
    [$contextId, $cookie] = fwdPage($onA);
    [$initStatus] = fwdAction($onA, $port, 'init', $contextId, $cookie);
    [$stream] = fwdSocketOn($port, $pidA, true);
    $seen = fwdStream($stream, $contextId, $cookie) . fwdDrain($stream, 0.3);
    echo 'early=', $initStatus, ':', fwdLast($seen, 'N'), ':', fwdLast($seen, 'OWN'), ':', (int) str_contains($seen, 'window.__init = 1'), "\n";
    fclose($stream);

    // The stream moves to another worker while an action runs on the old one.
    [$onA, $pidA] = fwdSocketOn($port, 0, false);
    [$contextId, $cookie] = fwdPage($onA);
    [$old] = fwdSocketOn($port, $pidA, false);
    fwdStream($old, $contextId, $cookie);
    [$slow] = fwdSocketOn($port, $pidA, false);
    $body = (string) json_encode(['via_ctx' => $contextId]);
    fwrite($slow, "POST /_action/slowmove HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\nCookie: via_session_id={$cookie}\r\n"
        . 'Content-Type: application/json' . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
    Coroutine::usleep(300_000);
    [$new] = fwdSocketOn($port, $pidA, true);
    $seen = fwdStream($new, $contextId, $cookie);
    [$slowStatus] = fwdResponse($slow);
    $seen .= fwdDrain($new, 0.5);
    $seenOld = fwdDrain($old, 0.1);
    echo 'moved=', $slowStatus, ':', fwdLast($seen, 'N'), ':', (int) str_contains($seen, 'window.__moved = 1'), ':', (int) str_contains($seenOld, 'window.__moved'), "\n";
    fclose($new);
    fclose($old);

    // A server-owned value the old worker already sent stays when the stream moves.
    [$onA, $pidA] = fwdSocketOn($port, 0, false);
    [$contextId, $cookie] = fwdPage($onA);
    [$first] = fwdSocketOn($port, $pidA, false);
    fwdStream($first, $contextId, $cookie);
    fwdAction($onA, $port, 'init', $contextId, $cookie);
    fwdDrain($first, 0.3);
    fclose($first);
    [$second] = fwdSocketOn($port, $pidA, true);
    $seen = fwdStream($second, $contextId, $cookie) . fwdDrain($second, 0.3);
    echo 'owned=', fwdLast($seen, 'OWN'), "\n";
    fclose($second);
}

function fwdNoRevival(int $port): void {
    [$onA, $pidA] = fwdSocketOn($port, 0, false);
    [$contextId, $cookie] = fwdPage($onA);
    [$onB] = fwdSocketOn($port, $pidA, true);
    echo 'norevival_early=', fwdAction($onB, $port, 'bump', $contextId, $cookie)[0], "\n";
    [$stream] = fwdSocketOn($port, $pidA, false);
    $seen = fwdStream($stream, $contextId, $cookie);
    echo 'norevival_bump=', fwdAction($onB, $port, 'bump', $contextId, $cookie)[0], "\n";
    fwdAction($onB, $port, 'script', $contextId, $cookie);
    $seen .= fwdDrain($stream, 0.4);
    echo 'norevival_stream_n=', fwdLast($seen, 'N'), "\n";
    echo 'norevival_stream_script=', (int) str_contains($seen, 'window.__forwarded = 1'), "\n";
    [, $heldOnB] = fwdWhoami($onB, $contextId);
    echo 'norevival_held_receiver=', (int) $heldOnB, "\n";
    fclose($stream);
}

$app->setInterval(static function () use ($app, $port, $mode): void {
    static $fired = false;
    // The marker keeps a restarted leader from driving the run again.
    $marker = sys_get_temp_dir() . '/via-forwarding-' . $app->getServer()?->master_pid;
    if ($fired || is_file($marker)) {
        return;
    }
    $fired = true;
    touch($marker);

    Coroutine::create(static function () use ($app, $port, $mode, $marker): void {
        try {
            match ($mode) {
                'timeout' => fwdTimeout($port),
                'crash' => fwdCrash($port),
                'handover' => fwdHandover($port),
                'norevival' => fwdNoRevival($port),
                default => fwdLayouts($port),
            };
        } catch (Throwable $e) {
            echo 'error=', str_replace("\n", ' ', $e->getMessage()), "\n";
        } finally {
            @unlink($marker);
            $app->getServer()?->shutdown();
        }
    });
}, 300);

$app->start();
