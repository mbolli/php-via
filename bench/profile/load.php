<?php

declare(strict_types=1);

/*
 * Load for the profiling scenarios (see README.md). It drives the website the way bench/capacity
 * does (keep-alive page views; tabs that hold a Brotli SSE stream and read it), resets the worker's
 * profiler when the load is steady, and dumps it at the end of the window.
 *
 *   php load.php pages  port=4711 ctl=DIR name=NAME pids=1,2,3 secs=30 conc=16 routes=/docs/api,/
 *   php load.php tabs   port=4711 ctl=DIR name=NAME pids=1,2,3 secs=30 route=/examples/leaderboard n=1000 \
 *                       act=none|leaderboard|chat|spreadsheet|counter rate=5 [editors=4]
 *
 * ctl is the profiler's control directory (VIA_PROFILE_DIR); without it the run only measures CPU.
 * Prints one JSON line with the window's server CPU and operation count, for fold.php.
 */

use OpenSwoole\Coroutine as Co;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Client as TcpClient;

$mode = $argv[1] ?? '';
$o = [];
foreach (array_slice($argv, 2) as $a) {
    [$k, $v] = explode('=', $a, 2) + [1 => ''];
    $o[$k] = $v;
}
$port = (int) ($o['port'] ?? 4711);
$pids = array_map('intval', array_filter(explode(',', $o['pids'] ?? '')));
$secs = (float) ($o['secs'] ?? 30);
$ctlDir = $o['ctl'] ?? '';
$name = $o['name'] ?? $mode;

final class Conn {
    public TcpClient $c;
    private string $buf = '';

    public function __construct(public readonly int $port) {
        $this->c = new TcpClient(SWOOLE_SOCK_TCP);
        $this->c->set(['open_tcp_nodelay' => true]);
        if (!$this->c->connect('127.0.0.1', $port, 5)) {
            throw new RuntimeException('connect: ' . $this->c->errMsg);
        }
    }

    public function send(string $d): void {
        if ($this->c->send($d) === false) {
            throw new RuntimeException('send: ' . $this->c->errMsg);
        }
    }

    public function until(string $sep, float $timeout = 10.0): ?string {
        while (($p = strpos($this->buf, $sep)) === false) {
            if (!$this->fill($timeout)) {
                return null;
            }
        }
        $out = substr($this->buf, 0, $p);
        $this->buf = substr($this->buf, $p + strlen($sep));

        return $out;
    }

    public function take(int $n, float $timeout = 10.0): string {
        while (strlen($this->buf) < $n) {
            if (!$this->fill($timeout)) {
                throw new RuntimeException('timeout');
            }
        }
        $out = substr($this->buf, 0, $n);
        $this->buf = substr($this->buf, $n);

        return $out;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public function head(float $timeout = 10.0): array {
        $raw = $this->until("\r\n\r\n", $timeout) ?? throw new RuntimeException('timeout');
        $lines = explode("\r\n", $raw);
        preg_match('#^HTTP/1\.[01] (\d+)#', $lines[0], $m);
        $h = ['_cookies' => []];
        foreach (array_slice($lines, 1) as $l) {
            [$k, $v] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
            $k = strtolower($k);
            $k === 'set-cookie' ? $h['_cookies'][] = explode(';', $v)[0] : $h[$k] = $v;
        }

        return [(int) ($m[1] ?? 0), $h];
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    public function request(string $method, string $path, array $headers, string $body = ''): array {
        $h = "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\n";
        foreach ($headers as $k => $v) {
            $h .= "{$k}: {$v}\r\n";
        }
        if ($method === 'POST') {
            $h .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $this->send($h . "\r\n" . $body);
        [$status, $hdrs] = $this->head();
        $raw = '';
        if (isset($hdrs['content-length'])) {
            $raw = $this->take((int) $hdrs['content-length']);
        } elseif (str_contains((string) ($hdrs['transfer-encoding'] ?? ''), 'chunked')) {
            while (($n = hexdec(trim((string) $this->until("\r\n")))) > 0) {
                $raw .= $this->take((int) $n);
                $this->until("\r\n");
            }
            $this->until("\r\n");
        }
        if (($hdrs['content-encoding'] ?? '') === 'br') {
            $raw = (string) brotli_uncompress($raw);
        }

        return [$status, $hdrs, $raw];
    }

    /** Read and discard a chunked stream until $stop() or the server ends it; returns bytes read. */
    public function drain(Closure $stop): int {
        $bytes = 0;
        while (!$stop()) {
            if ($this->buf === '' && !$this->fill(0.5)) {
                continue;
            }
            $bytes += strlen($this->buf);
            if (str_ends_with($this->buf, "0\r\n\r\n")) {
                return $bytes;
            }
            $this->buf = '';
        }

        return $bytes;
    }

    public function close(): void {
        $this->c->close();
    }

    private function fill(float $timeout): bool {
        $d = $this->c->recv($timeout);
        if ($d === false && $this->c->errCode === SOCKET_ETIMEDOUT) {
            return false;
        }
        if ($d === '' || $d === false) {
            throw new RuntimeException('closed: ' . $this->c->errCode);
        }
        $this->buf .= $d;

        return true;
    }
}

/**
 * CPU ticks (1/100 s) per process, threads included; the master's figure holds its reactor threads.
 *
 * @param list<int> $pids
 *
 * @return array<int, int>
 */
function cpuTicks(array $pids): array {
    $t = [];
    foreach ($pids as $pid) {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat !== false) {
            $f = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            $t[$pid] = (int) $f[11] + (int) $f[12];
        }
    }

    return $t;
}

/**
 * @param array<int, int> $a
 * @param array<int, int> $b
 *
 * @return array{0: float, 1: array<string, float>} total seconds, seconds per role (pids are master, manager, workers...)
 */
function cpuDelta(array $a, array $b): array {
    $roles = [];
    $k = 0;
    foreach ($b as $pid => $t) {
        $role = match ($k++) {
            0 => 'master', 1 => 'manager', default => 'proc' . $pid
        };
        $roles[$role] = ($t - ($a[$pid] ?? $t)) / 100;
    }

    return [array_sum($roles), $roles];
}

/** Send a command to every worker's profiler and wait for the answers. */
function profiler(string $dir, string $cmd): void {
    if ($dir === '') {
        return;
    }
    file_put_contents("{$dir}/ctl", $cmd . "\n");
    array_map('unlink', glob("{$dir}/ack.w*") ?: []);
    $pidFiles = glob("{$dir}/pid.w*") ?: [];
    foreach ($pidFiles as $f) {
        posix_kill((int) file_get_contents($f), SIGUSR2);
    }
    for ($i = 0; $i < 600 && count(glob("{$dir}/ack.w*") ?: []) < count($pidFiles); ++$i) {
        Co::usleep(50_000);
    }
}

/**
 * Run $fire at $rate per second for $secs on a pool of connections, open loop.
 *
 * @return array{0: int, 1: int, 2: array<int, int>} sent, ok, status codes
 */
function drive(int $port, float $rate, float $secs, Closure $job): array {
    $pool = 32;
    $jobs = new Channel(100000);
    $done = new Channel($pool);
    $ok = 0;
    $codes = [];
    for ($w = 0; $w < $pool; ++$w) {
        Co::create(function () use ($port, $jobs, $done, $job, &$ok, &$codes): void {
            $c = new Conn($port);
            while (($k = $jobs->pop()) !== false) {
                try {
                    foreach ($job($k) as [$path, $headers, $body]) {
                        [$st] = $c->request('POST', $path, $headers, $body);
                        $codes[$st] = ($codes[$st] ?? 0) + 1;
                    }
                    ++$ok;
                } catch (Throwable) {
                    $codes[0] = ($codes[0] ?? 0) + 1;
                    $c = new Conn($port);
                }
            }
            $c->close();
            $done->push(1);
        });
    }
    $start = microtime(true);
    $sent = 0;
    while (($t = microtime(true) - $start) < $secs) {
        for ($due = (int) floor($t * $rate) + 1; $sent < $due; ++$sent) {
            $jobs->push($sent);
        }
        Co::usleep(2000);
    }
    $jobs->close();
    for ($w = 0; $w < $pool; ++$w) {
        $done->pop();
    }

    return [$sent, $ok, $codes];
}

$browser = ['Accept-Encoding' => 'br', 'User-Agent' => 'profile-bench'];
$origin = "http://127.0.0.1:{$port}";

if ($mode === 'pages') {
    Co::run(function () use ($o, $port, $pids, $secs, $ctlDir, $name, $browser): void {
        $routes = explode(',', $o['routes'] ?? '/docs/api');
        $conc = (int) ($o['conc'] ?? 16);
        $n = 0;
        $errors = 0;
        $bytes = 0;
        profiler($ctlDir, 'reset');
        $c0 = cpuTicks($pids);
        $t0 = microtime(true);
        $wg = new Channel($conc);
        for ($i = 0; $i < $conc; ++$i) {
            Co::create(function () use ($i, $port, $routes, $secs, $t0, $browser, &$n, &$errors, &$bytes, $wg): void {
                $conn = new Conn($port);
                $k = $i;
                while (microtime(true) - $t0 < $secs) {
                    try {
                        [$st, , $body] = $conn->request('GET', $routes[$k++ % count($routes)], $browser + ['Accept' => 'text/html']);
                        $st === 200 ? ++$n : ++$errors;
                        $bytes += strlen($body);
                    } catch (Throwable) {
                        ++$errors;
                        $conn = new Conn($port);
                    }
                }
                $conn->close();
                $wg->push(1);
            });
        }
        for ($i = 0; $i < $conc; ++$i) {
            $wg->pop();
        }
        $dt = microtime(true) - $t0;
        [$cpu, $byProc] = cpuDelta($c0, cpuTicks($pids));
        profiler($ctlDir, "dump {$name}");
        echo json_encode(['name' => $name, 'secs' => round($dt, 2), 'ops' => $n, 'opname' => 'view', 'errors' => $errors,
            'cpu_s' => $cpu, 'cpu_by_proc' => $byProc, 'cpu_ms_per_op' => round($cpu * 1000 / max(1, $n), 3), 'core_pct' => round($cpu / $dt * 100, 1),
            'html_kb_per_view' => round($bytes / max(1, $n) / 1024, 1)]) . "\n";
    });

    exit(0);
}

if ($mode !== 'tabs') {
    fwrite(STDERR, "usage: php load.php pages|tabs key=value...\n");

    exit(2);
}

Co::run(function () use ($o, $port, $pids, $secs, $ctlDir, $name, $browser, $origin): void {
    $route = $o['route'] ?? '/';
    $n = (int) ($o['n'] ?? 500);
    $act = $o['act'] ?? 'none';
    $rate = (float) ($o['rate'] ?? 5);
    $stop = false;
    $stopFn = static function () use (&$stop): bool { return $stop; };
    $tabs = [];
    $failed = 0;
    $wire = 0;
    $ready = new Channel($n);
    $sem = new Channel(50);
    for ($i = 0; $i < 50; ++$i) {
        $sem->push(1);
    }
    $t0 = microtime(true);
    for ($i = 0; $i < $n; ++$i) {
        $sem->pop();
        Co::create(function () use ($i, $port, $route, $browser, $stopFn, $sem, $ready, &$tabs, &$failed, &$wire): void {
            $announced = false;

            try {
                $c = new Conn($port);
                [$st, $h, $html] = $c->request('GET', $route, $browser + ['Accept' => 'text/html']);
                $c->close();
                if ($st !== 200 || !preg_match('/via_ctx(?:"|&quot;):(?:"|&quot;)([^"&]+)/', $html, $m)) {
                    throw new RuntimeException("page {$st}");
                }
                $ctx = stripcslashes(str_replace('\/', '/', $m[1]));
                // Per-tab signal ids, such as tr__examples_spreadsheet__85782feea18b00e7____tssus
                preg_match_all('/\b([a-zA-Z]+)(__[a-z0-9_]+?__[0-9a-f]{16}____[a-z]+)/', $html, $sm, PREG_SET_ORDER);
                $sig = [];
                foreach ($sm as [$full, $short]) {
                    $sig[$short] ??= $full;
                }
                $tabs[$i] = ['ctx' => $ctx, 'cookie' => implode('; ', $h['_cookies']), 'sig' => $sig];
                $sse = new Conn($port);
                $q = rawurlencode((string) json_encode(['via_ctx' => $ctx]));
                $sse->send("GET /_sse?datastar={$q} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: text/event-stream\r\nAccept-Encoding: br\r\nCookie: {$tabs[$i]['cookie']}\r\n\r\n");
                $sem->push(1);
                $ready->push(true);
                $announced = true;
                $wire += $sse->drain($stopFn);
                $sse->close();
            } catch (Throwable $e) {
                ++$failed;
                if (!$announced) {
                    $sem->push(1);
                    $ready->push(false);
                }
                if ($failed <= 3) {
                    fwrite(STDERR, "tab {$i}: {$e->getMessage()}\n");
                }
            }
        });
    }
    $ok = 0;
    for ($i = 0; $i < $n; ++$i) {
        $ok += $ready->pop(60) ? 1 : 0;
    }
    $ramp = microtime(true) - $t0;
    Co::usleep((int) (($o['settle'] ?? 3) * 1e6));
    $ids = array_keys($tabs);

    $post = static function (int $i, string $path, array $signals = []) use (&$tabs, $origin): array {
        $body = ['via_ctx' => $tabs[$i]['ctx']];
        foreach ($signals as $short => $v) {
            $body[$tabs[$i]['sig'][$short] ?? $short] = $v;
        }

        return [$path, ['Origin' => $origin, 'Content-Type' => 'application/json', 'Cookie' => $tabs[$i]['cookie']], (string) json_encode($body)];
    };
    $editors = array_slice($ids, 0, max(1, (int) ($o['editors'] ?? 4)));
    $job = match ($act) {
        'none' => null,
        'leaderboard' => static fn (int $k): array => [$post($ids[$k % count($ids)], '/_action/vote?id=' . (1 + $k % 8))],
        'counter' => static fn (int $k): array => [$post($ids[$k % count($ids)], '/_action/shared-counter-increment')],
        'chat' => static fn (int $k): array => [$post($ids[$k % count($ids)], '/_action/sendMessage', ['messageInput' => "load message {$k} " . str_repeat('x', 40)])],
        // One edit: move to a cell, open the editor, commit a value. Three actions, three broadcasts.
        'spreadsheet' => static function (int $k) use ($editors, $post): array {
            $i = $editors[$k % count($editors)];
            $r = random_int(0, 19);
            $c = random_int(0, 9);

            return [
                $post($i, '/_action/focusCell', ['tr' => $r, 'tc' => $c, 'shift' => false]),
                $post($i, '/_action/startEdit', ['key' => 'Enter']),
                $post($i, '/_action/commitEdit', ['editValue' => "v{$k}", 'editing' => true]),
            ];
        },
        default => throw new RuntimeException("unknown act {$act}"),
    };

    profiler($ctlDir, 'reset');
    $c0 = cpuTicks($pids);
    $w0 = microtime(true);
    $sent = $done = 0;
    $codes = [];
    if ($job === null) {
        Co::usleep((int) ($secs * 1e6));
    } else {
        [$sent, $done, $codes] = drive($port, $rate, $secs, $job);
    }
    $dt = microtime(true) - $w0;
    [$cpu, $byProc] = cpuDelta($c0, cpuTicks($pids));
    profiler($ctlDir, "dump {$name}");
    $stop = true;
    Co::usleep(1_500_000);
    echo json_encode(['name' => $name, 'route' => $route, 'tabs' => $ok, 'failed' => $failed, 'ramp_s' => round($ramp, 1),
        'act' => $act, 'rate' => $rate, 'secs' => round($dt, 2), 'ops' => $done, 'opname' => $act === 'spreadsheet' ? 'edit' : 'action',
        'codes' => $codes, 'cpu_s' => $cpu, 'cpu_by_proc' => $byProc, 'core_pct' => round($cpu / $dt * 100, 1),
        'cpu_ms_per_op' => $done ? round($cpu * 1000 / $done, 3) : null,
        'cpu_ms_per_op_per_tab' => $done ? round($cpu * 1000 / $done / max(1, $ok), 4) : null]) . "\n";
});
