<?php

declare(strict_types=1);

/*
 * broadcast_storm: F1, synchronous broadcast fan-out under a burst of actions.
 *
 * Starts a real Via server (child process, VIA_TEST_MODE unset) with:
 *   GET /bench   the observed page. mode=tab: TAB-primary context that joins "bench:room" via
 *                addScope() and renders per context (cacheUpdates false). mode=route: ROUTE-primary
 *                context with a cacheable view.
 *   GET /actor   a TAB page whose "bump" action changes shared state and broadcasts the shared
 *                scope. state=global: incrementGlobalState() + broadcast(). state=signal: a scoped
 *                signal's increment(), which auto-broadcasts.
 *   GET /_bench/stats  per-worker counters (renders, mounts, sse, actions) from a shared Table.
 *
 * `clients` forked processes hold N SSE streams between them (page GET and /_sse on the same
 * keep-alive connection, so the context lives on the worker that streams it) and timestamp the
 * first frame carrying the final value. This process fires K actions from `concurrency` actor
 * connections and samples server CPU from /proc. Splitting the readers keeps the client from
 * becoming the bottleneck that the convergence and latency numbers would then measure.
 *
 * Options (--key=value): n, k, concurrency, workers (1, or >1 for SwooleBroker), mode (tab|route),
 * state (global|signal), clients, view-bytes, render-us (busy work per render), idle-ms,
 * converge-timeout (s), watchdog (s), setup-concurrency.
 *
 * stdout: exactly one JSON line. stderr: progress.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Client;
use OpenSwoole\Table;
use OpenSwoole\Timer;

const BENCH_NAME = 'broadcast_storm';
const SHARED_SCOPE = 'bench:room';
const BENCH_ROUTE = '/bench';
const BENCH_ETIMEDOUT = 110;
const BENCH_EAGAIN = 11;

final class SseConn {
    public string $tail = '';
    public int $maxV = -1;
    public ?float $convergedAt = null;
    public int $frames = 0;
    public int $framesAtStorm = 0;
    public int $bytes = 0;
    public string $initial = '';

    public function __construct(public Client $cl, public string $ctx) {}
}

final class Actor {
    public string $buf = '';

    public function __construct(public Client $cl, public string $ctx, public string $url, public string $cookie) {}
}

final class BenchState {
    public bool $stop = false;
    public int $final = PHP_INT_MAX;
    public int $connected = 0;
    public int $converged = 0;
    public int $live = 0;
}

/** Line-delimited JSON over a non-blocking socketpair, polled from a coroutine. */
final class Ipc {
    public bool $eof = false;
    private string $buf = '';

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    /** @param resource $sock */
    public function __construct(private $sock) {
        stream_set_blocking($sock, false);
    }

    /** @param array<string, mixed> $msg */
    public function send(array $msg): void {
        $data = json_encode($msg) . "\n";
        while ($data !== '') {
            $written = @fwrite($this->sock, $data);
            if ($written === false) {
                $this->eof = true;

                return;
            }
            $data = substr($data, $written);
            if ($data !== '') {
                Coroutine::usleep(1000);
            }
        }
    }

    /** @return null|array<string, mixed> next message of that type, null on timeout or EOF */
    public function wait(string $type, float $timeout): ?array {
        $deadline = microtime(true) + $timeout;
        while (true) {
            $this->poll();
            foreach ($this->queue as $i => $msg) {
                if (($msg['t'] ?? '') === $type) {
                    array_splice($this->queue, $i, 1);

                    return $msg;
                }
            }
            if ($this->eof || microtime(true) >= $deadline) {
                return null;
            }
            Coroutine::usleep(2000);
        }
    }

    public function has(string $type): bool {
        $this->poll();
        foreach ($this->queue as $msg) {
            if (($msg['t'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    private function poll(): void {
        while (true) {
            $d = @fread($this->sock, 65536);
            if ($d === false || $d === '') {
                if (feof($this->sock)) {
                    $this->eof = true;
                }

                break;
            }
            $this->buf .= $d;
        }
        while (($p = strpos($this->buf, "\n")) !== false) {
            $msg = json_decode(substr($this->buf, 0, $p), true);
            $this->buf = substr($this->buf, $p + 1);
            if (is_array($msg)) {
                $this->queue[] = $msg;
            }
        }
    }
}

function logErr(string $msg): void {
    fwrite(STDERR, '[' . BENCH_NAME . '] ' . $msg . "\n");
}

/** @return array<string, int|string> */
function parseOptions(array $argv): array {
    $o = [
        'role' => 'client',
        'n' => 1000,
        'k' => 200,
        'concurrency' => 50,
        'workers' => 1,
        'mode' => 'tab',
        'state' => 'global',
        'view-bytes' => 512,
        'render-us' => 0,
        'idle-ms' => 2000,
        'converge-timeout' => 20,
        'watchdog' => 75,
        'setup-concurrency' => 64,
        'clients' => 4,
        'port' => 0,
        'parent-pid' => 0,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) !== 1 || !array_key_exists($m[1], $o)) {
            throw new InvalidArgumentException("unknown argument: {$arg}");
        }
        $o[$m[1]] = is_int($o[$m[1]]) ? (int) ($m[2] ?? 1) : (string) ($m[2] ?? '');
    }

    if (!in_array($o['mode'], ['tab', 'route'], true)) {
        throw new InvalidArgumentException('--mode must be tab or route');
    }
    if (!in_array($o['state'], ['global', 'signal'], true)) {
        throw new InvalidArgumentException('--state must be global or signal');
    }
    foreach (['n', 'k', 'concurrency', 'workers', 'setup-concurrency', 'watchdog', 'converge-timeout', 'clients'] as $key) {
        if ($o[$key] < 1) {
            throw new InvalidArgumentException("--{$key} must be >= 1");
        }
    }

    return $o;
}

// ---------------------------------------------------------------------------------------------
// Server role
// ---------------------------------------------------------------------------------------------

/** @param array<string, int|string> $o */
function runServer(array $o): void {
    // Own process group, so the client and the watchdogs can kill master, manager and workers at once.
    $killTarget = posix_setsid() !== -1 ? 0 : getmypid();

    $workers = (int) $o['workers'];
    $mode = (string) $o['mode'];
    $state = (string) $o['state'];
    $renderUs = (int) $o['render-us'];
    $parentPid = (int) $o['parent-pid'];
    $watchdogMs = (int) $o['watchdog'] * 1000 + 5000;
    $pad = substr(str_repeat('lorem ipsum dolor sit amet ', intdiv((int) $o['view-bytes'], 27) + 1), 0, (int) $o['view-bytes']);
    $broadcastScope = $mode === 'route' ? Scope::routeScope(BENCH_ROUTE) : SHARED_SCOPE;

    $stats = new Table(64);
    foreach (['pid', 'renders', 'updates', 'mounts', 'sse', 'actions'] as $col) {
        $stats->column($col, Table::TYPE_INT);
    }
    $stats->create();

    // reuse_port off: a port collision must fail the bind rather than share another server's traffic.
    $config = (new Config())
        ->withHost('127.0.0.1')
        ->withPort((int) $o['port'])
        ->withLogLevel('error')
        ->withSwooleSettings(['enable_reuse_port' => false])
    ;
    $maxConn = (int) $o['n'] + (int) $o['concurrency'] + 256;
    if ($maxConn > 10000) {
        $config->withSwooleSettings(['enable_reuse_port' => false, 'max_conn' => $maxConn]);
    }
    if ($workers > 1) {
        $config->withWorkerNum($workers)->withBroker(new SwooleBroker());
        $rows = 2 * ((int) $o['n'] + (int) $o['concurrency']) + 256;
        if ($rows > 4096) {
            $config->withContextDirectorySize($rows);
        }
    }

    $app = new Via($config);
    $wid = '0';

    $app->onStart(static function () use ($app, $stats, &$wid, $parentPid, $watchdogMs, $killTarget): void {
        $wid = (string) $app->getServer()->worker_id;
        $stats->set($wid, ['pid' => getmypid(), 'renders' => 0, 'updates' => 0, 'mounts' => 0, 'sse' => 0, 'actions' => 0]);

        Timer::tick(1000, static function () use ($parentPid, $killTarget): void {
            if ($parentPid > 0 && !posix_kill($parentPid, 0)) {
                posix_kill($killTarget, SIGKILL);
            }
        });
        Timer::after($watchdogMs, static fn () => posix_kill($killTarget, SIGKILL));
    });

    $app->onClientConnect(static function () use ($stats, &$wid): void {
        $stats->incr($wid, 'sse');
    });
    $app->onClientDisconnect(static function () use ($stats, &$wid): void {
        $stats->decr($wid, 'sse');
    });

    $app->page(BENCH_ROUTE, static function (Context $c) use ($app, $stats, &$wid, $mode, $state, $renderUs, $pad, $broadcastScope): void {
        $stats->incr($wid, 'mounts');

        if ($mode === 'route') {
            $c->scope(Scope::ROUTE);
        } else {
            $c->addScope(SHARED_SCOPE);
        }

        $sig = $state === 'signal' ? $c->signal(0, 'v', $broadcastScope) : null;
        $label = $mode === 'route' ? 'shared' : htmlspecialchars($c->getId());

        $c->view(static function (bool $isUpdate = false) use ($app, $stats, &$wid, $sig, $label, $renderUs, $pad): string {
            $stats->incr($wid, 'renders');
            if ($isUpdate) {
                $stats->incr($wid, 'updates');
            }
            if ($renderUs > 0) {
                $end = hrtime(true) + $renderUs * 1000;
                while (hrtime(true) < $end) {
                }
            }
            $v = $sig !== null ? $sig->int() : (int) $app->globalState('bench_v', 0);

            return '<div id="bench"><p>' . $label . '</p><p>v:' . $v . ':v</p><p>' . $pad . '</p></div>';
        }, cacheUpdates: $mode === 'route');
    });

    $app->page('/actor', static function (Context $c) use ($app, $stats, &$wid, $state, $broadcastScope): void {
        $sig = $state === 'signal' ? $c->signal(0, 'v', $broadcastScope) : null;

        $action = $c->action(static function () use ($app, $stats, &$wid, $sig, $broadcastScope): void {
            $stats->incr($wid, 'actions');
            if ($sig !== null) {
                $sig->increment();

                return;
            }
            $app->incrementGlobalState('bench_v');
            $app->broadcast($broadcastScope);
        }, 'bump');

        $url = $action->url();
        $c->view(static fn (): string => '<div id="actor"><button data-on:click="@post(\'' . $url . '\')">bump</button></div>');
    });

    $signalId = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', $broadcastScope . ':v');
    $app->notFound(static function ($req, $res) use ($app, $stats, $state, $broadcastScope, $signalId): void {
        if (($req->server['request_uri'] ?? '') !== '/_bench/stats') {
            $res->status(404);
            $res->end('Not Found');

            return;
        }

        $rows = [];
        foreach ($stats as $key => $row) {
            $rows[(string) $key] = $row;
        }
        $value = $state === 'signal'
            ? $app->getScopedSignal($broadcastScope, $signalId)?->int()
            : (int) $app->globalState('bench_v', 0);

        $res->header('Content-Type', 'application/json');
        $res->end((string) json_encode(['workers' => $rows, 'value' => $value]));
    });

    $app->start();
}

// ---------------------------------------------------------------------------------------------
// Client role: process control
// ---------------------------------------------------------------------------------------------

function clockTicks(): int {
    return function_exists('posix_sysconf') && defined('POSIX_SC_CLK_TCK') ? max(1, (int) posix_sysconf(POSIX_SC_CLK_TCK)) : 100;
}

/** @return null|array{cpu: float, ppid: int, pgrp: int} */
function procStat(int $pid): ?array {
    $raw = @file_get_contents("/proc/{$pid}/stat");
    if ($raw === false || $raw === '') {
        return null;
    }
    // Fields after "(comm)": state ppid pgrp ... utime is field 14, stime field 15.
    $f = explode(' ', substr($raw, strrpos($raw, ')') + 2));

    return ['cpu' => ((int) $f[11] + (int) $f[12]) / clockTicks(), 'ppid' => (int) $f[1], 'pgrp' => (int) $f[2]];
}

function portFree(int $port): bool {
    $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 0.2);
    if ($fp !== false) {
        fclose($fp);

        return false;
    }

    return true;
}

/**
 * @param array<string, int|string> $o
 *
 * @return array{0: resource, 1: int}
 */
function spawnServer(array $o, int $port): array {
    $env = getenv();
    unset($env['VIA_TEST_MODE']);

    $cmd = [PHP_BINARY, '-d', 'memory_limit=-1'];
    foreach (['opcache.enable_cli', 'opcache.jit', 'opcache.jit_buffer_size'] as $ini) {
        $val = ini_get($ini);
        if ($val !== false && $val !== '') {
            $cmd[] = '-d';
            $cmd[] = "{$ini}={$val}";
        }
    }
    $cmd[] = __FILE__;
    $cmd[] = '--role=server';
    $cmd[] = "--port={$port}";
    $cmd[] = '--parent-pid=' . getmypid();
    foreach (['n', 'concurrency', 'workers', 'mode', 'state', 'view-bytes', 'render-us', 'watchdog'] as $key) {
        $cmd[] = "--{$key}={$o[$key]}";
    }

    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes, dirname(__DIR__, 2), $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('proc_open failed');
    }

    return [$proc, (int) proc_get_status($proc)['pid']];
}

/** @return null|array{workers: array<string, array<string, int>>, value: mixed} */
function fetchStatsBlocking(int $port): ?array {
    $ctx = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true, 'header' => "Connection: close\r\n"]]);
    $body = @file_get_contents("http://127.0.0.1:{$port}/_bench/stats", false, $ctx);
    $data = is_string($body) ? json_decode($body, true) : null;

    return is_array($data) && isset($data['workers']) ? $data : null;
}

/**
 * Start the server on a pid-derived port and wait until every worker has registered.
 *
 * @param array<string, int|string> $o
 *
 * @return array{proc: resource, pid: int, port: int, stats: array<string, mixed>}
 */
function startServer(array $o): array {
    $port = 20000 + (getmypid() * 7) % 12000;

    for ($attempt = 0; $attempt < 5; ++$attempt) {
        while (!portFree($port)) {
            ++$port;
        }

        [$proc, $pid] = spawnServer($o, $port);
        $deadline = microtime(true) + 20.0;

        while (microtime(true) < $deadline) {
            if (!proc_get_status($proc)['running']) {
                break;
            }
            $stats = fetchStatsBlocking($port);
            if ($stats !== null && count($stats['workers']) === (int) $o['workers']) {
                foreach ($stats['workers'] as $row) {
                    $ppid = procStat((int) $row['pid'])['ppid'] ?? 0;
                    if ($ppid !== $pid && (procStat($ppid)['ppid'] ?? 0) !== $pid) {
                        stopServer($proc, $pid);

                        throw new RuntimeException("port {$port} answered with workers that are not ours");
                    }
                }

                return ['proc' => $proc, 'pid' => $pid, 'port' => $port, 'stats' => $stats];
            }
            usleep(100_000);
        }

        logErr("server on port {$port} did not become ready, retrying on another port");
        stopServer($proc, $pid);
        $port += 1 + $attempt * 13;
    }

    throw new RuntimeException('server failed to start');
}

/** @param resource $proc */
function stopServer($proc, int $pid): void {
    @posix_kill($pid, SIGTERM);
    $deadline = microtime(true) + 6.0;
    while (microtime(true) < $deadline && proc_get_status($proc)['running']) {
        usleep(50_000);
    }
    // The server is its own process group leader: this reaches the manager and workers too.
    @posix_kill(-$pid, SIGKILL);
    @posix_kill($pid, SIGKILL);
    proc_close($proc);
}

/**
 * Forked guard: on timeout (or if this process dies) kill the server group and this process.
 *
 * @param array<string, int|string> $params
 */
function armWatchdog(int $seconds, int $serverPid, array $params): int {
    $parent = getmypid();
    $pid = pcntl_fork();
    if ($pid !== 0) {
        return $pid;
    }

    $deadline = time() + $seconds;
    $timedOut = true;
    while (time() < $deadline) {
        sleep(1);
        if (posix_getppid() !== $parent) {
            $timedOut = false;

            break;
        }
    }
    if ($timedOut) {
        fwrite(STDOUT, json_encode(['bench' => BENCH_NAME, 'ok' => false, 'error' => "watchdog fired after {$seconds}s", 'params' => $params]) . "\n");
        logErr("watchdog fired after {$seconds}s, killing server and client");
    }
    @posix_kill(-$serverPid, SIGKILL);
    @posix_kill($parent, SIGKILL);
    posix_kill(getmypid(), SIGKILL);

    exit(1);
}

// ---------------------------------------------------------------------------------------------
// Client role: HTTP over coroutine sockets
// ---------------------------------------------------------------------------------------------

function newClient(int $port, float $timeout): ?Client {
    $cl = new Client(SWOOLE_SOCK_TCP);
    $cl->set(['open_tcp_nodelay' => true]);

    return $cl->connect('127.0.0.1', $port, $timeout) ? $cl : null;
}

/** Read until the header terminator; leaves any body bytes in $buf. */
function readHead(Client $cl, string &$buf, float $timeout): ?array {
    $deadline = microtime(true) + $timeout;
    while (($p = strpos($buf, "\r\n\r\n")) === false) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return null;
        }
        $d = $cl->recv(min($left, 1.0));
        if ($d === false) {
            if ($cl->errCode === BENCH_ETIMEDOUT || $cl->errCode === BENCH_EAGAIN) {
                continue;
            }

            return null;
        }
        if ($d === '') {
            return null;
        }
        $buf .= $d;
    }

    $lines = explode("\r\n", substr($buf, 0, $p));
    $buf = substr($buf, $p + 4);
    $status = (int) (explode(' ', (string) array_shift($lines))[1] ?? 0);
    $headers = [];
    foreach ($lines as $line) {
        $c = strpos($line, ':');
        if ($c !== false) {
            $headers[strtolower(trim(substr($line, 0, $c)))] = trim(substr($line, $c + 1));
        }
    }

    return [$status, $headers];
}

function fill(Client $cl, string &$buf, int $need, float $deadline): bool {
    while (strlen($buf) < $need) {
        $left = $deadline - microtime(true);
        if ($left <= 0) {
            return false;
        }
        $d = $cl->recv(min($left, 1.0));
        if ($d === false) {
            if ($cl->errCode === BENCH_ETIMEDOUT || $cl->errCode === BENCH_EAGAIN) {
                continue;
            }

            return false;
        }
        if ($d === '') {
            return false;
        }
        $buf .= $d;
    }

    return true;
}

/** @return null|array{0: int, 1: array<string, string>, 2: string} */
function readResponse(Client $cl, string &$buf, float $timeout): ?array {
    $deadline = microtime(true) + $timeout;
    $head = readHead($cl, $buf, $timeout);
    if ($head === null) {
        return null;
    }
    [$status, $headers] = $head;

    if (isset($headers['content-length'])) {
        $len = (int) $headers['content-length'];
        if (!fill($cl, $buf, $len, $deadline)) {
            return null;
        }
        $body = substr($buf, 0, $len);
        $buf = substr($buf, $len);

        return [$status, $headers, $body];
    }

    if (str_contains(strtolower($headers['transfer-encoding'] ?? ''), 'chunked')) {
        $body = '';
        while (true) {
            while (($p = strpos($buf, "\r\n")) === false) {
                if (!fill($cl, $buf, strlen($buf) + 1, $deadline)) {
                    return null;
                }
            }
            $size = hexdec(trim(explode(';', substr($buf, 0, $p))[0]));
            if (!fill($cl, $buf, $p + 2 + (int) $size + 2, $deadline)) {
                return null;
            }
            $body .= substr($buf, $p + 2, (int) $size);
            $buf = substr($buf, $p + 2 + (int) $size + 2);
            if ($size === 0) {
                return [$status, $headers, $body];
            }
        }
    }

    return [$status, $headers, ''];
}

function httpGet(Client $cl, string &$buf, int $port, string $path, string $cookie, float $timeout): ?array {
    $req = "GET {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nCookie: {$cookie}\r\nConnection: keep-alive\r\n\r\n";
    if (!$cl->send($req)) {
        return null;
    }

    return readResponse($cl, $buf, $timeout);
}

function extractCtx(string $html): ?string {
    $html = html_entity_decode($html, ENT_QUOTES);
    if (preg_match('/"via_ctx"\s*:\s*"((?:[^"\\\]|\\\.)+)"/', $html, $m) !== 1) {
        return null;
    }
    $ctx = json_decode('"' . $m[1] . '"');

    return is_string($ctx) ? $ctx : null;
}

/** @return null|array<string, mixed> */
function fetchStats(int $port): ?array {
    $cl = newClient($port, 2.0);
    if ($cl === null) {
        return null;
    }
    $buf = '';
    $req = "GET /_bench/stats HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nConnection: close\r\n\r\n";
    $resp = $cl->send($req) ? readResponse($cl, $buf, 3.0) : null;
    $cl->close();
    $data = $resp !== null ? json_decode($resp[2], true) : null;

    return is_array($data) ? $data : null;
}

function scanFrames(SseConn $c, string $data, BenchState $s): void {
    $c->bytes += strlen($data);
    $joined = $c->tail . $data;
    $c->frames += substr_count($joined, 'datastar-patch-elements') - substr_count($c->tail, 'datastar-patch-elements');

    if (preg_match_all('/v:(\d+):v/', $joined, $m) > 0) {
        $max = max(array_map('intval', $m[1]));
        if ($max > $c->maxV) {
            $c->maxV = $max;
            if ($max >= $s->final && $c->convergedAt === null) {
                $c->convergedAt = microtime(true);
                ++$s->converged;
            }
        }
    }
    $c->tail = substr($joined, -40);
}

function openSse(int $i, int $port): ?SseConn {
    $cl = newClient($port, 5.0);
    if ($cl === null) {
        return null;
    }
    $cookie = 'via_session_id=' . md5('bench-sse-' . $i);
    $buf = '';
    $page = httpGet($cl, $buf, $port, BENCH_ROUTE, $cookie, 10.0);
    $ctx = $page !== null && $page[0] === 200 ? extractCtx($page[2]) : null;
    if ($ctx === null) {
        $cl->close();

        return null;
    }

    $query = rawurlencode((string) json_encode(['via_ctx' => $ctx]));
    $req = "GET /_sse?datastar={$query} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nCookie: {$cookie}\r\nAccept: text/event-stream\r\nConnection: keep-alive\r\n\r\n";
    if (!$cl->send($req)) {
        $cl->close();

        return null;
    }
    $head = readHead($cl, $buf, 10.0);
    if ($head === null || $head[0] !== 200) {
        $cl->close();

        return null;
    }

    $conn = new SseConn($cl, $ctx);
    $conn->initial = $buf;

    return $conn;
}

function sseLoop(SseConn $c, BenchState $s): void {
    ++$s->live;

    try {
        if ($c->initial !== '') {
            scanFrames($c, $c->initial, $s);
            $c->initial = '';
        }
        while (!$s->stop) {
            $d = $c->cl->recv(0.5);
            if ($d === false) {
                if ($c->cl->errCode === BENCH_ETIMEDOUT || $c->cl->errCode === BENCH_EAGAIN) {
                    continue;
                }

                break;
            }
            if ($d === '') {
                break;
            }
            scanFrames($c, $d, $s);
        }
    } finally {
        $c->cl->close();
        --$s->live;
    }
}

function selfCpu(): float {
    $u = getrusage();

    return $u['ru_utime.tv_sec'] + $u['ru_utime.tv_usec'] / 1e6 + $u['ru_stime.tv_sec'] + $u['ru_stime.tv_usec'] / 1e6;
}

/**
 * Forked reader process: holds streams [$from, $to) and reports to the parent over $sock.
 *
 * @param array<string, int|string> $o
 * @param resource                  $sock
 */
function streamChild(array $o, int $port, int $from, int $to, $sock): void {
    Coroutine::run(static function () use ($o, $port, $from, $to, $sock): void {
        $ipc = new Ipc($sock);
        $s = new BenchState();
        $count = $to - $from;
        $selfDestruct = Timer::after(((int) $o['watchdog'] + 10) * 1000, static fn () => posix_kill(getmypid(), SIGKILL));

        $sem = new Channel(max(4, intdiv((int) $o['setup-concurrency'], (int) $o['clients'])));
        $opened = new Channel(max(1, $count));
        /** @var list<SseConn> $conns */
        $conns = [];
        for ($i = $from; $i < $to; ++$i) {
            Coroutine::create(static function () use ($i, $port, $sem, $opened, $s, &$conns): void {
                $sem->push(true);
                $conn = openSse($i, $port);
                $sem->pop();
                if ($conn !== null) {
                    $conns[] = $conn;
                    ++$s->connected;
                }
                $opened->push($conn !== null ? 1 : 0);
                if ($conn !== null) {
                    sseLoop($conn, $s);
                }
            });
        }

        $deadline = microtime(true) + max(15.0, $count / 50);
        $failed = 0;
        for ($i = 0; $i < $count; ++$i) {
            $ok = $opened->pop(max(0.01, $deadline - microtime(true)));
            if ($ok === false) {
                $failed += $count - $i;

                break;
            }
            $failed += 1 - $ok;
        }

        $primeDeadline = microtime(true) + 10.0;
        do {
            $unprimed = 0;
            foreach ($conns as $c) {
                $unprimed += $c->maxV < 0 ? 1 : 0;
            }
            if ($unprimed === 0) {
                break;
            }
            Coroutine::usleep(20_000);
        } while (microtime(true) < $primeDeadline);

        $baseline = -1;
        foreach ($conns as $c) {
            $baseline = max($baseline, $c->maxV);
        }
        $ipc->send(['t' => 'ready', 'connected' => $s->connected, 'failures' => $failed, 'baseline' => $baseline, 'unprimed' => $unprimed]);

        $goDeadline = microtime(true) + (float) $o['watchdog'];
        while (!$ipc->has('go') && !$ipc->has('stop') && !$ipc->eof && microtime(true) < $goDeadline) {
            Coroutine::usleep(2000);
        }
        $go = $ipc->has('go') ? $ipc->wait('go', 0.01) : null;
        $cpu0 = selfCpu();
        if ($go !== null) {
            $s->final = (int) $go['final'];
            foreach ($conns as $c) {
                $c->framesAtStorm = $c->frames;
                if ($c->maxV >= $s->final && $c->convergedAt === null) {
                    $c->convergedAt = microtime(true);
                    ++$s->converged;
                }
            }
            $ipc->send(['t' => 'armed']);

            // Report as soon as every stream has the final value, or when the parent gives up.
            while ($s->converged < $s->connected && !$ipc->has('report') && !$ipc->eof) {
                Coroutine::usleep(1000);
            }
            $conv = [];
            $minMax = PHP_INT_MAX;
            foreach ($conns as $c) {
                if ($c->convergedAt !== null) {
                    $conv[] = $c->convergedAt;
                }
                $minMax = min($minMax, $c->maxV);
            }
            $ipc->send(['t' => 'done', 'converged' => $s->converged, 'connected' => $s->connected, 'conv' => $conv, 'min_max_v' => $minMax]);
            $ipc->wait('stop', (float) $o['watchdog']);
        }

        $s->stop = true;
        $closeDeadline = microtime(true) + 3.0;
        while ($s->live > 0 && microtime(true) < $closeDeadline) {
            Coroutine::usleep(20_000);
        }
        $frames = 0;
        $bytes = 0;
        foreach ($conns as $c) {
            $frames += $c->frames - $c->framesAtStorm;
            $bytes += $c->bytes;
        }
        $ipc->send(['t' => 'final', 'frames' => $frames, 'bytes' => $bytes, 'cpu' => selfCpu() - $cpu0]);
        Timer::clear($selfDestruct);
    });
}

function openActor(int $j, int $port): ?Actor {
    $cl = newClient($port, 5.0);
    if ($cl === null) {
        return null;
    }
    $cookie = 'via_session_id=' . md5('bench-actor-' . $j);
    $buf = '';
    $page = httpGet($cl, $buf, $port, '/actor', $cookie, 10.0);
    $ctx = $page !== null && $page[0] === 200 ? extractCtx($page[2]) : null;
    if ($ctx === null) {
        $cl->close();

        return null;
    }
    $url = preg_match("/@post\\('([^']+)'\\)/", html_entity_decode($page[2], ENT_QUOTES), $m) === 1 ? $m[1] : '/_action/bump';
    $actor = new Actor($cl, $ctx, $url, $cookie);
    $actor->buf = $buf;

    return $actor;
}

/** @return array{0: int, 1: float, 2: float} status (0 on transport failure), send time, done time */
function sendAction(Actor $a, int $port, float $timeout): array {
    $body = (string) json_encode(['via_ctx' => $a->ctx]);
    $req = "POST {$a->url} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nOrigin: http://127.0.0.1:{$port}\r\n"
        . "Cookie: {$a->cookie}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body)
        . "\r\nConnection: keep-alive\r\n\r\n" . $body;

    $t0 = microtime(true);
    $resp = $a->cl->send($req) ? readResponse($a->cl, $a->buf, $timeout) : null;

    return [$resp[0] ?? 0, $t0, microtime(true)];
}

/** @param list<float> $sorted */
function pct(array $sorted, float $q): ?float {
    $n = count($sorted);
    if ($n === 0) {
        return null;
    }

    return round($sorted[min($n - 1, max(0, (int) ceil($q * $n) - 1))], 3);
}

/**
 * @param array<string, int> $pids
 *
 * @return array<string, float>
 */
function sampleCpu(array $pids): array {
    $out = [];
    foreach ($pids as $name => $pid) {
        $out[$name] = procStat($pid)['cpu'] ?? 0.0;
    }

    return $out;
}

/**
 * @param array<string, float> $a
 * @param array<string, float> $b
 */
function cpuDelta(array $a, array $b, string $prefix): float {
    $sum = 0.0;
    foreach ($b as $name => $v) {
        if (str_starts_with($name, $prefix)) {
            $sum += $v - ($a[$name] ?? 0.0);
        }
    }

    return round($sum, 3);
}

/** @param array<string, mixed> $stats */
function statSum(array $stats, string $col): int {
    $sum = 0;
    foreach ($stats['workers'] ?? [] as $row) {
        $sum += (int) ($row[$col] ?? 0);
    }

    return $sum;
}

/**
 * @param array<string, int|string> $o
 * @param array<string, mixed>      $readyStats
 * @param list<resource>            $socks
 *
 * @return array<string, mixed>
 */
function parentFlow(array $o, int $port, int $serverPid, array $readyStats, array $socks): array {
    $n = (int) $o['n'];
    $k = (int) $o['k'];
    $conc = min((int) $o['concurrency'], $k);
    $convergeTimeout = (float) $o['converge-timeout'];
    $r = ['errors' => []];
    $ipcs = array_map(static fn ($sock) => new Ipc($sock), $socks);

    $pids = ['master' => $serverPid];
    foreach ($readyStats['workers'] as $id => $row) {
        $pids['w' . $id] = (int) $row['pid'];
    }
    $managerPid = procStat($pids['w0'] ?? $serverPid)['ppid'] ?? 0;
    if ($managerPid > 0 && $managerPid !== $serverPid) {
        $pids['manager'] = $managerPid;
    }

    // Phase 1: the reader processes open their streams.
    $t = microtime(true);
    $setupDeadline = $t + max(20.0, $n / 50) + 12.0;
    $connected = 0;
    $failures = 0;
    $unprimed = 0;
    $baseline = -1;
    foreach ($ipcs as $i => $ipc) {
        $msg = $ipc->wait('ready', max(0.01, $setupDeadline - microtime(true)));
        if ($msg === null) {
            $r['errors'][] = "reader {$i} did not report ready";

            continue;
        }
        $connected += (int) $msg['connected'];
        $failures += (int) $msg['failures'];
        $unprimed += (int) $msg['unprimed'];
        $baseline = max($baseline, (int) $msg['baseline']);
    }
    $r['connected'] = $connected;
    $r['connect_failures'] = $failures;
    if ($unprimed > 0) {
        $r['errors'][] = "{$unprimed} streams never received an initial frame";
    }
    logErr(sprintf('%d SSE streams open (%d failed) in %.2fs across %d reader(s)', $connected, $failures, microtime(true) - $t, count($ipcs)));

    // Phase 2: actor connections, each its own context on a keep-alive connection.
    $actors = [];
    $actorCh = new Channel($conc);
    for ($j = 0; $j < $conc; ++$j) {
        Coroutine::create(static function () use ($j, $port, $actorCh): void {
            $actorCh->push(openActor($j, $port) ?? 0);
        });
    }
    for ($j = 0; $j < $conc; ++$j) {
        $a = $actorCh->pop(15.0);
        if ($a instanceof Actor) {
            $actors[] = $a;
        }
    }
    if ($actors === [] || $connected === 0) {
        $r['errors'][] = 'no actors or no streams, aborting';
        foreach ($ipcs as $ipc) {
            $ipc->send(['t' => 'stop']);
        }

        return $r;
    }

    // Phase 3: idle probe, the background cost of N parked streams.
    Coroutine::usleep(300_000);
    $statsIdle0 = fetchStats($port) ?? [];
    $cpuIdle0 = sampleCpu($pids);
    $tIdle0 = microtime(true);
    Coroutine::usleep((int) $o['idle-ms'] * 1000);
    $cpuIdle1 = sampleCpu($pids);
    $tIdle1 = microtime(true);
    $statsStorm0 = fetchStats($port) ?? [];
    $idleRate = cpuDelta($cpuIdle0, $cpuIdle1, 'w') / max(0.001, $tIdle1 - $tIdle0);
    $r['idle_worker_cpu_per_s'] = round($idleRate, 4);
    $r['idle_renders'] = statSum($statsStorm0, 'renders') - statSum($statsIdle0, 'renders');

    // Phase 4: the storm.
    $final = $baseline + $k;
    foreach ($ipcs as $ipc) {
        $ipc->send(['t' => 'go', 'final' => $final]);
    }
    foreach ($ipcs as $i => $ipc) {
        if ($ipc->wait('armed', 5.0) === null) {
            $r['errors'][] = "reader {$i} did not acknowledge the start";
        }
    }
    $cpuStorm0 = sampleCpu($pids);
    $clientCpu0 = selfCpu();
    $tStorm0 = microtime(true);

    $next = 0;
    $lat = [];
    $okCount = 0;
    $statusCounts = [];
    $firstSend = PHP_FLOAT_MAX;
    $lastSend = 0.0;
    $lastAck = 0.0;
    $actorsDone = new Channel(count($actors));
    foreach ($actors as $a) {
        Coroutine::create(static function () use ($a, $port, $k, $convergeTimeout, $actorsDone, &$next, &$lat, &$okCount, &$statusCounts, &$firstSend, &$lastSend, &$lastAck): void {
            while ($next < $k) {
                ++$next;
                [$status, $t0, $t1] = sendAction($a, $port, $convergeTimeout);
                $firstSend = min($firstSend, $t0);
                $lastSend = max($lastSend, $t0);
                $lastAck = max($lastAck, $t1);
                $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
                if ($status === 200) {
                    ++$okCount;
                    $lat[] = ($t1 - $t0) * 1000;
                } elseif ($status === 0) {
                    // Transport failure: reconnect once so one dropped socket does not stall the storm.
                    $a->cl->close();
                    $fresh = newClient($port, 5.0);
                    if ($fresh === null) {
                        break;
                    }
                    $a->cl = $fresh;
                    $a->buf = '';
                }
            }
            $actorsDone->push(1);
        });
    }
    $actionsDeadline = microtime(true) + $convergeTimeout + 30;
    for ($j = 0; $j < count($actors); ++$j) {
        if ($actorsDone->pop(max(0.01, $actionsDeadline - microtime(true))) === false) {
            $r['errors'][] = 'actions did not finish in time';

            break;
        }
    }
    $cpuActionsDone = sampleCpu($pids);
    if ($okCount < $k) {
        $r['errors'][] = ($k - $okCount) . ' actions failed; streams were waiting for ' . $final . ', which may never be reached';
    }

    // Phase 5: convergence reports. A reader that has not converged by the deadline is asked to report.
    $convTimes = [];
    $converged = 0;
    $minMaxV = PHP_INT_MAX;
    $convDeadline = $lastSend + $convergeTimeout;
    foreach ($ipcs as $i => $ipc) {
        $msg = $ipc->wait('done', max(0.01, $convDeadline - microtime(true)));
        if ($msg === null) {
            $ipc->send(['t' => 'report']);
            $msg = $ipc->wait('done', 5.0);
        }
        if ($msg === null) {
            $r['errors'][] = "reader {$i} sent no convergence report";

            continue;
        }
        $converged += (int) $msg['converged'];
        $minMaxV = min($minMaxV, (int) $msg['min_max_v']);
        array_push($convTimes, ...array_map('floatval', $msg['conv']));
    }
    $cpuConverged = sampleCpu($pids);
    $allConverged = $converged === $connected && $connected > 0;
    if (!$allConverged) {
        $r['errors'][] = ($connected - $converged) . " streams did not see the final value {$final} within {$convergeTimeout}s (lowest last-seen value {$minMaxV})";
    }
    $lastConverged = $convTimes === [] ? null : max($convTimes);

    // Phase 6: quiesce, so renders still queued when the last frame landed are counted too.
    $prev = -1;
    $stable = 0;
    $quiesceDeadline = microtime(true) + 5.0;
    $statsEnd = $statsStorm0;
    while (microtime(true) < $quiesceDeadline && $stable < 3) {
        Coroutine::usleep(100_000);
        $statsEnd = fetchStats($port) ?? $statsEnd;
        $cur = statSum($statsEnd, 'renders');
        $stable = $cur === $prev ? $stable + 1 : 0;
        $prev = $cur;
    }
    $cpuEnd = sampleCpu($pids);
    $actorCpu = selfCpu() - $clientCpu0;
    $tEnd = microtime(true);

    // Teardown of the client side; readers report their frame counts on the way out.
    foreach ($actors as $a) {
        $a->cl->close();
    }
    $frames = 0;
    $bytes = 0;
    $readerCpu = 0.0;
    foreach ($ipcs as $ipc) {
        $ipc->send(['t' => 'stop']);
    }
    foreach ($ipcs as $i => $ipc) {
        $msg = $ipc->wait('final', 5.0);
        if ($msg === null) {
            $r['errors'][] = "reader {$i} sent no final report";

            continue;
        }
        $frames += (int) $msg['frames'];
        $bytes += (int) $msg['bytes'];
        $readerCpu += (float) $msg['cpu'];
    }

    sort($lat);
    $clientConv = [];
    foreach ($convTimes as $ct) {
        $clientConv[] = ($ct - $lastSend) * 1000;
    }
    sort($clientConv);
    $renders = statSum($statsEnd, 'renders') - statSum($statsStorm0, 'renders');
    $workerCpu = cpuDelta($cpuStorm0, $cpuEnd, 'w');

    $perWorker = [];
    foreach ($statsEnd['workers'] ?? [] as $id => $row) {
        $name = 'w' . $id;
        $perWorker[] = [
            'id' => (int) $id,
            'pid' => (int) $row['pid'],
            'cpu_s' => round(($cpuEnd[$name] ?? 0) - ($cpuStorm0[$name] ?? 0), 3),
            'renders' => (int) $row['renders'] - (int) ($statsStorm0['workers'][$id]['renders'] ?? 0),
            'actions' => (int) $row['actions'] - (int) ($statsStorm0['workers'][$id]['actions'] ?? 0),
            'sse' => (int) $row['sse'],
            'mounts' => (int) $row['mounts'],
        ];
    }

    return $r + [
        'baseline_value' => $baseline,
        'final_value' => $final,
        'server_value' => $statsEnd['value'] ?? null,
        'actions_ok' => $okCount,
        'actions_failed' => $k - $okCount,
        'action_status' => $statusCounts,
        'converged_clients' => $converged,
        'unconverged_clients' => $connected - $converged,
        'worker_cpu_s' => $workerCpu,
        'worker_cpu_net_s' => round($workerCpu - $idleRate * ($tEnd - $tStorm0), 3),
        'worker_cpu_s_actions' => cpuDelta($cpuStorm0, $cpuActionsDone, 'w'),
        'worker_cpu_s_to_converge' => cpuDelta($cpuStorm0, $cpuConverged, 'w'),
        'master_cpu_s' => cpuDelta($cpuStorm0, $cpuEnd, 'master'),
        'server_cpu_s' => round(array_sum($cpuEnd) - array_sum($cpuStorm0), 3),
        'cpu_window_s' => round($tEnd - $tStorm0, 3),
        'reader_cpu_s' => round($readerCpu, 3),
        'actor_cpu_s' => round($actorCpu, 3),
        'renders' => $renders,
        'renders_per_action' => round($renders / $k, 2),
        'renders_per_action_per_client' => round($renders / ($k * max(1, $connected)), 4),
        'action_latency_ms' => [
            'p50' => pct($lat, 0.50),
            'p95' => pct($lat, 0.95),
            'p99' => pct($lat, 0.99),
            'max' => $lat === [] ? null : round(max($lat), 3),
            'mean' => $lat === [] ? null : round(array_sum($lat) / count($lat), 3),
        ],
        'actions_wall_ms' => round(($lastAck - $firstSend) * 1000, 3),
        'actions_per_s' => $lastAck > $firstSend ? round($okCount / ($lastAck - $firstSend), 1) : null,
        'converge_after_last_send_ms' => $allConverged && $lastConverged !== null ? round(($lastConverged - $lastSend) * 1000, 3) : null,
        'converge_after_last_ack_ms' => $allConverged && $lastConverged !== null ? round(($lastConverged - $lastAck) * 1000, 3) : null,
        'storm_to_converge_ms' => $allConverged && $lastConverged !== null ? round(($lastConverged - $firstSend) * 1000, 3) : null,
        'client_converge_after_last_send_ms' => ['p50' => pct($clientConv, 0.50), 'p99' => pct($clientConv, 0.99)],
        'frames_per_client' => round($frames / max(1, $connected), 2),
        'frames_total' => $frames,
        'rx_mb_total' => round($bytes / 1048576, 2),
        'per_worker' => $perWorker,
    ];
}

// ---------------------------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------------------------

/**
 * @param array<string, int|string> $o
 *
 * @return list<array{pid: int, sock: resource}>
 */
function forkReaders(array $o, int $port): array {
    $n = (int) $o['n'];
    $p = min((int) $o['clients'], $n);
    $readers = [];
    for ($i = 0; $i < $p; ++$i) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            throw new RuntimeException('stream_socket_pair failed');
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            fclose($pair[0]);
            // Siblings' parent ends must not stay open here, or a dead parent never reads as EOF.
            foreach ($readers as $reader) {
                fclose($reader['sock']);
            }
            streamChild($o, $port, intdiv($i * $n, $p), intdiv(($i + 1) * $n, $p), $pair[1]);
            posix_kill(getmypid(), SIGKILL);
        }
        fclose($pair[1]);
        $readers[] = ['pid' => $pid, 'sock' => $pair[0]];
    }

    return $readers;
}

/** @param list<array{pid: int, sock: resource}> $readers */
function reapReaders(array $readers): void {
    $deadline = microtime(true) + 3.0;
    foreach ($readers as $reader) {
        while (pcntl_waitpid($reader['pid'], $status, WNOHANG) === 0) {
            if (microtime(true) > $deadline) {
                posix_kill($reader['pid'], SIGKILL);
                pcntl_waitpid($reader['pid'], $status);

                break;
            }
            usleep(20_000);
        }
    }
}

function main(array $argv): int {
    $wallStart = microtime(true);

    try {
        $o = parseOptions($argv);
    } catch (InvalidArgumentException $e) {
        echo json_encode(['bench' => BENCH_NAME, 'ok' => false, 'error' => $e->getMessage()]), "\n";

        return 2;
    }

    if ($o['role'] === 'server') {
        runServer($o);

        return 0;
    }

    $params = $o;
    unset($params['role'], $params['port'], $params['parent-pid']);
    $params['clients'] = min((int) $o['clients'], (int) $o['n']);
    $params['opcache_cli'] = (string) ini_get('opcache.enable_cli');
    $params['jit'] = (string) ini_get('opcache.jit');

    try {
        $server = startServer($o);
    } catch (Throwable $e) {
        echo json_encode(['bench' => BENCH_NAME, 'ok' => false, 'error' => $e->getMessage(), 'params' => $params]), "\n";

        return 1;
    }
    $params['port'] = $server['port'];
    logErr(sprintf('server pid %d on port %d, %d worker(s), mode=%s state=%s n=%d k=%d c=%d',
        $server['pid'], $server['port'], $o['workers'], $o['mode'], $o['state'], $o['n'], $o['k'], $o['concurrency']));

    $watchdog = armWatchdog((int) $o['watchdog'], $server['pid'], $params);

    $result = [];
    $error = null;
    $readers = [];

    try {
        $readers = forkReaders($o, $server['port']);
        $socks = array_column($readers, 'sock');
        Coroutine::run(static function () use ($o, $server, $socks, &$result, &$error): void {
            try {
                $result = parentFlow($o, $server['port'], $server['pid'], $server['stats'], $socks);
            } catch (Throwable $e) {
                $error = get_class($e) . ': ' . $e->getMessage();
            }
        });
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    } finally {
        foreach ($readers as $reader) {
            @fclose($reader['sock']);
        }
        reapReaders($readers);
        stopServer($server['proc'], $server['pid']);
        posix_kill($watchdog, SIGKILL);
        pcntl_waitpid($watchdog, $status);
    }

    if ($error !== null) {
        $result['errors'][] = $error;
    }
    $ok = $error === null
        && ($result['errors'] ?? ['missing']) === []
        && ($result['unconverged_clients'] ?? 1) === 0
        && ($result['actions_failed'] ?? 1) === 0
        && ($result['connect_failures'] ?? 1) === 0;

    $out = ['bench' => BENCH_NAME, 'ok' => $ok, 'params' => $params] + $result;
    $out['wall_s'] = round(microtime(true) - $wallStart, 2);
    echo json_encode($out, JSON_UNESCAPED_SLASHES), "\n";

    return $ok ? 0 : 1;
}

exit(main($argv));
