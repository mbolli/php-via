<?php

declare(strict_types=1);

/*
 * Contention benchmark F2: what idle SSE connections cost the server.
 *
 * For each worker count, starts a real OpenSwoole Via server, opens N idle SSE
 * connections from a coroutine client (page load and SSE on the same keep-alive
 * socket, as a browser does), lets them settle, then samples server CPU from
 * /proc/<pid>/stat over a fixed idle window with nothing broadcast. Afterwards it
 * checks liveness: one GLOBAL broadcast (time until every client has it) and a
 * SIGTERM to the master with every connection still open (time until it exits).
 *
 * worker_num > 1 runs with SwooleBroker, which turns on the shared context
 * directory and client registry heartbeats.
 *
 * Usage:
 *   php bench/contention/idle_sse.php [--n=2000] [--workers=1,4] [--idle=10]
 *       [--settle=3] [--concurrency=64] [--fire-timeout=10] [--poll-ms=<ms>] [--timeout=<s>]
 *
 * --poll-ms is optional and only for plausibility checks. It sets
 * Config::withSsePollIntervalMs(), which paced page streams before they became
 * event driven and now paces only the Dev Bar stream.
 * --timeout overrides the per-run watchdog (default: derived from the other options).
 *
 * Prints one JSON line on stdout. Logs go to stderr.
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
use OpenSwoole\Coroutine\Http\Client as HttpClient;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Timer;

const BENCH = 'idle_sse';
const SEQ_MARKER = 424242;
const CLK_TCK = 100;

/**
 * @param list<string> $argv
 *
 * @return array<string, string>
 */
function parseOpts(array $argv): array {
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = explode('=', substr($arg, 2), 2);
        $opts[$pair[0]] = $pair[1] ?? '1';
    }

    return $opts;
}

function logLine(string $msg): void {
    fwrite(STDERR, '[' . BENCH . ' ' . getmypid() . '] ' . $msg . "\n");
}

/** @return array<string, string> environment for children, without the test-mode switch */
function childEnv(): array {
    $env = getenv();
    unset($env['VIA_TEST_MODE']);

    return $env;
}

function clkTck(): int {
    $v = (int) trim((string) @shell_exec('getconf CLK_TCK 2>/dev/null'));

    return $v > 0 ? $v : CLK_TCK;
}

/** utime + stime in clock ticks, summed over all threads; null when the process is gone. */
function procCpuTicks(int $pid): ?int {
    $s = @file_get_contents("/proc/{$pid}/stat");
    if ($s === false || $s === '') {
        return null;
    }
    $f = explode(' ', substr($s, strrpos($s, ')') + 2));

    return (int) $f[11] + (int) $f[12];
}

/** Whether a pid is still running (a zombie counts as exited). */
function procAlive(int $pid): bool {
    $s = @file_get_contents("/proc/{$pid}/stat");
    if ($s === false || $s === '') {
        return false;
    }
    $state = substr($s, strrpos($s, ')') + 2, 1);

    return $state !== 'Z' && $state !== 'X';
}

/** Voluntary context switches over all threads: each one is the process going to sleep and waking again. */
function procWakeups(int $pid): int {
    $total = 0;
    foreach (glob("/proc/{$pid}/task/*/status") ?: [] as $file) {
        $s = (string) @file_get_contents($file);
        if (preg_match('/^voluntary_ctxt_switches:\s+(\d+)/m', $s, $m)) {
            $total += (int) $m[1];
        }
    }

    return $total;
}

function procRssMb(int $pid): float {
    $s = (string) @file_get_contents("/proc/{$pid}/status");

    return preg_match('/^VmRSS:\s+(\d+)\s+kB/m', $s, $m) ? round((int) $m[1] / 1024, 1) : 0.0;
}

/** @param list<float> $sorted */
function pct(array $sorted, float $p): ?float {
    if ($sorted === []) {
        return null;
    }
    $idx = (int) min(count($sorted) - 1, max(0, (int) ceil($p / 100 * count($sorted)) - 1));

    return round($sorted[$idx], 2);
}

function portFree(int $port): bool {
    $sock = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $err);
    if ($sock === false) {
        return false;
    }
    fclose($sock);

    return true;
}

// Server role ------------------------------------------------------------------

/** @param array<string, string> $o */
function runServer(array $o): void {
    putenv('VIA_TEST_MODE=');
    posix_setsid();

    $port = (int) $o['port'];
    $workers = max(1, (int) $o['workers']);
    $n = (int) ($o['n'] ?? 2000);
    $parentPid = (int) $o['parent'];
    $deadline = microtime(true) + (float) $o['lifetime'];
    $masterPid = getmypid();

    // Guard: kills the whole server group if the driver dies or the lifetime runs out.
    $guard = pcntl_fork();
    if ($guard === 0) {
        while (true) {
            usleep(200_000);
            if (posix_getppid() !== $masterPid) {
                exit(0);
            }
            if (!posix_kill($parentPid, 0) || microtime(true) > $deadline) {
                fwrite(STDERR, '[' . BENCH . " guard] killing server group {$masterPid}\n");
                posix_kill(-$masterPid, SIGKILL);

                exit(0);
            }
        }
    }

    $config = (new Config())
        ->withHost('127.0.0.1')->withPort($port)->withLogLevel('error')
        ->withWorkerNum($workers)
    ;
    if ($workers > 1) {
        $config = $config->withBroker(new SwooleBroker());
    }
    if (isset($o['poll-ms']) && $o['poll-ms'] !== '') {
        if (method_exists($config, 'withSsePollIntervalMs')) {
            $config = $config->withSsePollIntervalMs((int) $o['poll-ms']);
        } else {
            logLine('--poll-ms ignored: Config::withSsePollIntervalMs() does not exist in this revision');
        }
    }
    if ($n * 2 > $config->getContextDirectoryRows()) {
        $config = $config->withContextDirectorySize($n * 2);
    }
    if ($n + 1024 > 10000) {
        $config = $config->withSwooleSettings(['max_conn' => $n + 1024]);
    }

    $app = new Via($config);

    $app->page('/probe', function (Context $c) use ($app): void {
        $c->view(fn (): string => '<div id="probe">CTX:' . $c->getId() . ':END SEQ:' . (int) $app->globalState('seq', 0) . ':END</div>');
    });

    $app->notFound(function (Request $req, Response $res) use ($app): void {
        $uri = (string) ($req->server['request_uri'] ?? '');
        if ($uri === '/_bench/fire') {
            $app->setGlobalState('seq', (int) ($req->get['seq'] ?? 1));
            $t = hrtime(true);
            $app->broadcast(Scope::GLOBAL);
            $res->end((string) json_encode([
                'worker' => $app->getServer()?->worker_id,
                'local_fanout_ms' => round((hrtime(true) - $t) / 1e6, 2),
                'local_contexts' => count($app->contexts),
            ]));

            return;
        }
        if ($uri === '/_bench/ping') {
            $res->end($app->getServer()?->master_pid . ' ' . $app->getServer()?->manager_pid);

            return;
        }
        $res->status(404);
        $res->end('Not Found');
    });

    $app->start();
}

// Run role: one server, one client, one worker count ------------------------------

/** @param array<string, string> $o */
function runOne(array $o): void {
    $workers = max(1, (int) ($o['workers'] ?? 1));
    $n = max(1, (int) ($o['n'] ?? 2000));
    $idle = max(1.0, (float) ($o['idle'] ?? 10));
    $settle = max(0.0, (float) ($o['settle'] ?? 3));
    $concurrency = max(1, (int) ($o['concurrency'] ?? 64));
    $fireTimeout = max(1.0, (float) ($o['fire-timeout'] ?? 10));
    $pollMs = $o['poll-ms'] ?? '';
    $setupTimeout = 20 + $n / 250;
    $shutdownTimeout = 10.0;
    $budget = isset($o['timeout']) ? max(1.0, (float) $o['timeout'])
        : 10 + $setupTimeout + $settle + $idle + $fireTimeout + $shutdownTimeout + 5;

    $result = ['workers' => $workers, 'n' => $n, 'ok' => false];

    // Each connection costs one fd here and one in the server; leave headroom for both.
    $lim = posix_getrlimit();
    $soft = (int) ($lim['soft openfiles'] ?? 1024);
    $hard = $lim['hard openfiles'] ?? 'unlimited';
    $hardInt = $hard === 'unlimited' ? 1_048_576 : (int) $hard;
    if ($soft < $n + 512 && $hardInt > $soft) {
        $soft = min($hardInt, max($n + 512, $soft));
        @posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hardInt);
    }
    if ($n + 512 > $soft) {
        $n = max(1, $soft - 512);
        $result['n'] = $n;
        logLine("RLIMIT_NOFILE {$soft} too low, capped n to {$n}");
    }

    $port = 21000 + (getmypid() % 11000);
    for ($i = 0; $i < 50 && !portFree($port); ++$i) {
        $port = 21000 + (($port - 21000 + 97) % 11000);
    }
    $result['port'] = $port;

    $cmd = [PHP_BINARY, __FILE__, '--role=server', "--port={$port}", "--workers={$workers}", "--n={$n}",
        '--parent=' . getmypid(), '--lifetime=' . (int) ceil($budget + 10)];
    if ($pollMs !== '') {
        $cmd[] = "--poll-ms={$pollMs}";
    }
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR], $pipes, null, childEnv());
    if (!is_resource($proc)) {
        $result['error'] = 'proc_open failed';
        echo json_encode($result), "\n";

        return;
    }
    $masterPid = (int) proc_get_status($proc)['pid'];
    $result['master_pid'] = $masterPid;

    $killServer = static function () use ($masterPid): void {
        @posix_kill(-$masterPid, SIGKILL);
        @posix_kill($masterPid, SIGKILL);
    };

    // Readiness: the port answers as this master and the manager has forked every worker.
    $workerPids = [];
    $managerPid = 0;
    $readyBy = microtime(true) + 15;
    while (microtime(true) < $readyBy && procAlive($masterPid)) {
        usleep(50_000);
        $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $err, 1);
        if ($fp === false) {
            continue;
        }
        fwrite($fp, "GET /_bench/ping HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        stream_set_timeout($fp, 2);
        $resp = (string) stream_get_contents($fp);
        fclose($fp);
        $body = explode(' ', trim(substr($resp, (int) strpos($resp, "\r\n\r\n") + 4)));
        if ($body[0] !== (string) $masterPid) {
            logLine("port {$port} answered as master {$body[0]}, not {$masterPid}");

            continue;
        }
        $managerPid = (int) ($body[1] ?? 0);
        $children = childPids($managerPid);
        if (count($children) >= $workers) {
            $workerPids = $children;
            // Workers exist before their workerStart has finished.
            usleep(300_000);

            break;
        }
    }
    if ($workerPids === []) {
        $killServer();
        proc_close($proc);
        $result['error'] = 'server did not become ready';
        echo json_encode($result), "\n";

        return;
    }
    $result['worker_pids'] = array_values($workerPids);
    logLine("server up: port {$port}, master {$masterPid}, manager {$managerPid}, workers " . implode(',', $workerPids));

    $tck = clkTck();

    Coroutine::run(function () use (
        &$result,
        $n,
        $port,
        $idle,
        $settle,
        $concurrency,
        $fireTimeout,
        $setupTimeout,
        $shutdownTimeout,
        $budget,
        $masterPid,
        $managerPid,
        $workerPids,
        $killServer,
        $tck
    ): void {
        $watchdog = Timer::after((int) ($budget * 1000), static function () use (&$result, $killServer): void {
            logLine('watchdog fired, killing server and client');
            $killServer();
            $result['error'] = 'run watchdog fired';
            echo json_encode($result), "\n";
            posix_kill(getmypid(), SIGKILL);
        });

        $marker = 'SEQ:' . SEQ_MARKER . ':END';
        $sem = new Channel($concurrency);
        $setupDone = new Channel($n);
        $received = new Channel($n);
        $streamEnd = [];
        $eof = [];
        $failReasons = [];

        $setupStart = microtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $sem->push(true);
            Coroutine::create(static function () use ($i, $port, $marker, $sem, $setupDone, $received, &$streamEnd, &$eof, &$failReasons): void {
                $cli = new Client(SWOOLE_SOCK_TCP);
                $leftover = null;

                try {
                    $leftover = openSse($cli, $port);
                } catch (Throwable $e) {
                    $failReasons[$e->getMessage()] = ($failReasons[$e->getMessage()] ?? 0) + 1;
                } finally {
                    $sem->pop();
                    $setupDone->push($leftover !== null ? 1 : 0);
                }
                if ($leftover === null) {
                    $cli->close();

                    return;
                }

                $tail = $leftover;
                $got = str_contains($tail, $marker);
                while (true) {
                    $data = $cli->recv(60.0);
                    if ($data === false && in_array($cli->errCode, [110, 11], true)) {
                        continue;
                    }
                    if ($data === false || $data === '') {
                        $eof[$i] = microtime(true);

                        break;
                    }
                    $buf = $tail . $data;
                    if (!$got && str_contains($buf, $marker)) {
                        $got = true;
                        $received->push(microtime(true));
                    }
                    if (!isset($streamEnd[$i]) && str_contains($buf, "\r\n0\r\n\r\n")) {
                        $streamEnd[$i] = microtime(true);
                    }
                    $tail = substr($buf, -64);
                }
                $cli->close();
            });
        }

        $connected = 0;
        $failed = 0;
        $setupDeadline = $setupStart + $setupTimeout;
        while ($connected + $failed < $n) {
            $left = $setupDeadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $ok = $setupDone->pop($left);
            if ($ok === 1) {
                ++$connected;
            } elseif ($ok === 0) {
                ++$failed;
            } else {
                break;
            }
        }
        $result['connected'] = $connected;
        $result['failed'] = $n - $connected;
        $result['setup_s'] = round(microtime(true) - $setupStart, 2);
        if ($failReasons !== []) {
            $result['fail_reasons'] = $failReasons;
        }
        logLine("connected {$connected}/{$n} in {$result['setup_s']} s, settling {$settle} s");

        if ($settle > 0) {
            Coroutine::usleep((int) ($settle * 1e6));
        }

        // Idle window.
        $pids = ['master' => $masterPid, 'manager' => $managerPid, 'client' => getmypid()];
        $before = [];
        $wakeBefore = [];
        foreach ($workerPids as $id => $pid) {
            $before["w{$id}"] = procCpuTicks($pid);
            $wakeBefore["w{$id}"] = procWakeups($pid);
        }
        foreach ($pids as $k => $pid) {
            $before[$k] = procCpuTicks($pid);
        }
        $t0 = hrtime(true);
        Coroutine::usleep((int) ($idle * 1e6));
        $window = (hrtime(true) - $t0) / 1e9;
        $cpuPct = [];
        $wakeRate = [];
        $rss = [];
        foreach ($workerPids as $id => $pid) {
            $ticks = procCpuTicks($pid);
            $cpuPct[] = ($ticks === null || $before["w{$id}"] === null) ? null
                : round(($ticks - $before["w{$id}"]) / $tck / $window * 100, 2);
            $wakeRate[] = round((procWakeups($pid) - $wakeBefore["w{$id}"]) / $window, 1);
            $rss[] = procRssMb($pid);
        }
        $other = [];
        foreach ($pids as $k => $pid) {
            $ticks = procCpuTicks($pid);
            $other[$k] = ($ticks === null || $before[$k] === null) ? null
                : round(($ticks - $before[$k]) / $tck / $window * 100, 2);
        }
        $valid = array_values(array_filter($cpuPct, static fn ($v) => $v !== null));
        $result['idle_window_s'] = round($window, 3);
        $result['worker_cpu_pct'] = $cpuPct;
        $result['worker_cpu_pct_avg'] = $valid === [] ? null : round(array_sum($valid) / count($valid), 2);
        $result['worker_cpu_pct_max'] = $valid === [] ? null : max($valid);
        $result['worker_cpu_pct_total'] = round(array_sum($valid), 2);
        $result['worker_cpu_pct_per_1k_conns'] = $connected > 0 ? round(array_sum($valid) / $connected * 1000, 2) : null;
        $result['master_cpu_pct'] = $other['master'];
        $result['manager_cpu_pct'] = $other['manager'];
        $result['client_cpu_pct'] = $other['client'];
        $result['worker_wakeups_per_s'] = $wakeRate;
        $result['worker_wakeups_per_s_total'] = round(array_sum($wakeRate), 1);
        $result['worker_rss_mb'] = $rss;
        logLine(sprintf('idle %.2f s: worker cpu %% %s, wakeups/s %s', $window, json_encode($cpuPct), json_encode($wakeRate)));

        // Liveness 1: one broadcast, time until every connected client has it.
        $http = new HttpClient('127.0.0.1', $port);
        $http->set(['timeout' => $fireTimeout]);
        $tFire = microtime(true);
        $http->get('/_bench/fire?seq=' . SEQ_MARKER);
        $fireHttpMs = round((microtime(true) - $tFire) * 1000, 2);
        $fireBody = json_decode((string) $http->body, true);
        $http->close();
        $lat = [];
        $fireDeadline = $tFire + $fireTimeout;
        while (count($lat) < $connected) {
            $left = $fireDeadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $t = $received->pop($left);
            if ($t === false) {
                break;
            }
            $lat[] = ($t - $tFire) * 1000;
        }
        sort($lat);
        $result['broadcast'] = [
            'fire_http_ms' => $fireHttpMs,
            'fire_response' => is_array($fireBody) ? $fireBody : null,
            'delivered' => count($lat),
            'missing' => $connected - count($lat),
            'p50_ms' => pct($lat, 50),
            'p90_ms' => pct($lat, 90),
            'p99_ms' => pct($lat, 99),
            'all_ms' => $lat === [] ? null : round(end($lat), 2),
        ];
        logLine('broadcast: ' . json_encode($result['broadcast']));

        // Liveness 2: SIGTERM to the master with every connection open, time until it exits.
        $tTerm = microtime(true);
        posix_kill($masterPid, SIGTERM);
        $timedOut = false;
        while (procAlive($masterPid)) {
            if (microtime(true) - $tTerm > $shutdownTimeout) {
                $timedOut = true;
                logLine('server did not stop within ' . $shutdownTimeout . ' s, killing it');
                $killServer();

                break;
            }
            Coroutine::usleep(1000);
        }
        $exitMs = round((microtime(true) - $tTerm) * 1000, 2);

        // Give the clients a moment to see their sockets close.
        $eofDeadline = microtime(true) + 3;
        while (count($eof) < $connected && microtime(true) < $eofDeadline) {
            Coroutine::usleep(5000);
        }
        $ends = array_map(static fn (float $t): float => ($t - $tTerm) * 1000, $streamEnd);
        $eofs = array_map(static fn (float $t): float => ($t - $tTerm) * 1000, $eof);
        $result['shutdown'] = [
            'master_exit_ms' => $exitMs,
            'timed_out' => $timedOut,
            'stream_end_count' => count($ends),
            'stream_end_ms_max' => $ends === [] ? null : round(max($ends), 2),
            'eof_count' => count($eofs),
            'eof_ms_max' => $eofs === [] ? null : round(max($eofs), 2),
        ];
        logLine('shutdown: ' . json_encode($result['shutdown']));

        if (count($eof) < $connected) {
            // Server is gone, so these can only be stuck; the kill closes their sockets.
            $killServer();
        }

        Timer::clear($watchdog);
        $result['ok'] = !$timedOut && $connected === $n && count($lat) === $connected;
    });

    $killServer();
    proc_close($proc);
    echo json_encode($result), "\n";
}

/**
 * Load /probe and open /_sse on the same keep-alive socket, as a browser reuses its connection.
 *
 * @return string bytes already read past the SSE response headers
 */
function openSse(Client $cli, int $port): string {
    if (!$cli->connect('127.0.0.1', $port, 5.0)) {
        throw new RuntimeException('connect errno ' . $cli->errCode);
    }
    $host = "127.0.0.1:{$port}";
    $cli->send("GET /probe HTTP/1.1\r\nHost: {$host}\r\nConnection: keep-alive\r\n\r\n");
    [$status, $headers, $body] = readResponse($cli);
    if ($status !== 200) {
        throw new RuntimeException("page status {$status}");
    }
    if (!preg_match('/CTX:(.+?):END/', $body, $m)) {
        throw new RuntimeException('no context id in page');
    }
    $cookie = '';
    foreach ($headers['set-cookie'] ?? [] as $raw) {
        $cookie .= explode(';', $raw)[0] . '; ';
    }
    $query = rawurlencode((string) json_encode(['via_ctx' => $m[1]]));
    $cli->send("GET /_sse?datastar={$query} HTTP/1.1\r\nHost: {$host}\r\nAccept: text/event-stream\r\n"
        . "Cookie: {$cookie}\r\nConnection: keep-alive\r\n\r\n");

    $buf = '';
    while (($pos = strpos($buf, "\r\n\r\n")) === false) {
        $chunk = $cli->recv(10.0);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('sse headers errno ' . $cli->errCode);
        }
        $buf .= $chunk;
    }
    if (!preg_match('#^HTTP/1\.[01] 200#', $buf)) {
        throw new RuntimeException('sse status ' . strtok($buf, "\r\n"));
    }

    return substr($buf, $pos + 4);
}

/**
 * Read one HTTP/1.1 response with a Content-Length or chunked body.
 *
 * @return array{int, array<string, list<string>>, string}
 */
function readResponse(Client $cli): array {
    $buf = '';
    while (($pos = strpos($buf, "\r\n\r\n")) === false) {
        $chunk = $cli->recv(10.0);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('page headers errno ' . $cli->errCode);
        }
        $buf .= $chunk;
    }
    $lines = explode("\r\n", substr($buf, 0, $pos));
    $status = (int) (explode(' ', (string) array_shift($lines))[1] ?? 0);
    $headers = [];
    foreach ($lines as $line) {
        $kv = explode(':', $line, 2);
        $headers[strtolower(trim($kv[0]))][] = trim($kv[1] ?? '');
    }
    $body = substr($buf, $pos + 4);

    if (isset($headers['content-length'])) {
        $len = (int) $headers['content-length'][0];
        while (strlen($body) < $len) {
            $chunk = $cli->recv(10.0);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('page body errno ' . $cli->errCode);
            }
            $body .= $chunk;
        }

        return [$status, $headers, $body];
    }

    while (!str_contains($body, "0\r\n\r\n")) {
        $chunk = $cli->recv(10.0);
        if ($chunk === false || $chunk === '') {
            throw new RuntimeException('page chunked body errno ' . $cli->errCode);
        }
        $body .= $chunk;
    }
    $decoded = '';
    while (($nl = strpos($body, "\r\n")) !== false) {
        $size = hexdec(trim(substr($body, 0, $nl)));
        if ($size === 0) {
            break;
        }
        $decoded .= substr($body, $nl + 2, (int) $size);
        $body = substr($body, $nl + 2 + (int) $size + 2);
    }

    return [$status, $headers, $decoded];
}

// Main role: one run per worker count, aggregated ---------------------------------------

/** @param array<string, string> $o */
function runMain(array $o): void {
    $n = max(1, (int) ($o['n'] ?? 2000));
    $workerList = array_values(array_filter(array_map('intval', explode(',', (string) ($o['workers'] ?? '1,4'))), static fn (int $w) => $w > 0));
    $idle = max(1.0, (float) ($o['idle'] ?? 10));
    $settle = max(0.0, (float) ($o['settle'] ?? 3));
    $concurrency = max(1, (int) ($o['concurrency'] ?? 64));
    $fireTimeout = max(1.0, (float) ($o['fire-timeout'] ?? 10));
    $pollMs = (string) ($o['poll-ms'] ?? '');

    $params = [
        'n' => $n, 'workers' => $workerList, 'idle_s' => $idle, 'settle_s' => $settle,
        'concurrency' => $concurrency, 'fire_timeout_s' => $fireTimeout,
        'poll_ms' => $pollMs === '' ? null : (int) $pollMs,
        'php' => PHP_VERSION, 'openswoole' => phpversion('openswoole'), 'nproc' => (int) trim((string) @shell_exec('nproc')),
        'loadavg' => sys_getloadavg(),
        'git_head' => trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' rev-parse --short HEAD 2>/dev/null')),
        'src_dirty' => trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 2)) . ' status --porcelain -- src 2>/dev/null')) !== '',
    ];

    $runs = [];
    $metrics = [];
    foreach ($workerList as $w) {
        $cmd = [PHP_BINARY, __FILE__, '--role=run', "--workers={$w}", "--n={$n}", "--idle={$idle}", "--settle={$settle}",
            "--concurrency={$concurrency}", "--fire-timeout={$fireTimeout}"];
        if ($pollMs !== '') {
            $cmd[] = "--poll-ms={$pollMs}";
        }
        if (isset($o['timeout'])) {
            $cmd[] = '--timeout=' . $o['timeout'];
        }
        $budget = (isset($o['timeout']) ? max(1.0, (float) $o['timeout']) : 10 + (20 + $n / 250) + $settle + $idle + $fireTimeout + 10 + 5) + 15;
        logLine("run workers={$w} n={$n} idle={$idle}s (watchdog {$budget}s)");

        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, null, childEnv());
        if (!is_resource($proc)) {
            $runs[] = ['workers' => $w, 'ok' => false, 'error' => 'proc_open failed'];

            continue;
        }
        $runPid = (int) proc_get_status($proc)['pid'];
        stream_set_blocking($pipes[1], false);
        $out = '';
        $deadline = microtime(true) + $budget;
        while (true) {
            $r = [$pipes[1]];
            $wr = $ex = null;
            if (@stream_select($r, $wr, $ex, 0, 200_000) > 0) {
                $chunk = fread($pipes[1], 65536);
                if ($chunk !== false) {
                    $out .= $chunk;
                }
            }
            if (feof($pipes[1])) {
                break;
            }
            if (microtime(true) > $deadline) {
                logLine("run workers={$w} exceeded {$budget}s, killing it");
                killTree($runPid);

                break;
            }
        }
        fclose($pipes[1]);
        proc_close($proc);

        $lines = array_values(array_filter(explode("\n", trim($out)), static fn (string $l) => str_starts_with($l, '{')));
        $run = $lines === [] ? null : json_decode((string) end($lines), true);
        if (!is_array($run)) {
            $run = ['workers' => $w, 'ok' => false, 'error' => 'no result from run'];
        }
        $runs[] = $run;

        $p = "w{$w}_";
        $metrics[$p . 'connected'] = $run['connected'] ?? null;
        $metrics[$p . 'worker_cpu_pct_avg'] = $run['worker_cpu_pct_avg'] ?? null;
        $metrics[$p . 'worker_cpu_pct_max'] = $run['worker_cpu_pct_max'] ?? null;
        $metrics[$p . 'worker_cpu_pct_total'] = $run['worker_cpu_pct_total'] ?? null;
        $metrics[$p . 'worker_wakeups_per_s_total'] = $run['worker_wakeups_per_s_total'] ?? null;
        $metrics[$p . 'broadcast_all_ms'] = $run['broadcast']['all_ms'] ?? null;
        $metrics[$p . 'broadcast_p50_ms'] = $run['broadcast']['p50_ms'] ?? null;
        $metrics[$p . 'broadcast_missing'] = $run['broadcast']['missing'] ?? null;
        $metrics[$p . 'shutdown_ms'] = $run['shutdown']['master_exit_ms'] ?? null;
        $metrics[$p . 'shutdown_timed_out'] = $run['shutdown']['timed_out'] ?? null;
    }

    $ok = $runs !== [] && array_reduce($runs, static fn (bool $c, array $r) => $c && ($r['ok'] ?? false), true);
    echo json_encode(['bench' => BENCH, 'ok' => $ok, 'params' => $params, 'metrics' => $metrics, 'runs' => $runs]), "\n";

    exit($ok ? 0 : 1);
}

/** @return array<int, list<int>> child pids by parent pid */
function processTree(): array {
    $children = [];
    foreach (glob('/proc/[0-9]*/stat') ?: [] as $file) {
        $s = (string) @file_get_contents($file);
        if ($s === '') {
            continue;
        }
        $f = explode(' ', substr($s, strrpos($s, ')') + 2));
        $children[(int) $f[1]][] = (int) basename(dirname($file));
    }

    return $children;
}

/** @return list<int> */
function childPids(int $pid): array {
    $pids = processTree()[$pid] ?? [];
    sort($pids);

    return $pids;
}

/** SIGKILL a process and every descendant, children first so nothing is reparented and lost. */
function killTree(int $pid): void {
    $children = processTree();
    $order = [];
    $stack = [$pid];
    while ($stack !== []) {
        $p = array_pop($stack);
        $order[] = $p;
        foreach ($children[$p] ?? [] as $c) {
            $stack[] = $c;
        }
    }
    foreach (array_reverse($order) as $p) {
        @posix_kill($p, SIGKILL);
    }
}

$opts = parseOpts($argv);
if (isset($opts['help'])) {
    fwrite(STDERR, (string) preg_replace('/^.*?\/\*\n|\*\/.*$/s', '', (string) file_get_contents(__FILE__, length: 2000)));

    exit(0);
}

match ($opts['role'] ?? 'main') {
    'server' => runServer($opts),
    'run' => runOne($opts),
    default => runMain($opts),
};
