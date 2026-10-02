<?php

declare(strict_types=1);

/*
 * Capacity harness for a running php-via app (written against the website), see README.md.
 * Run it on cores the server does not use; pids and worker are the server's processes, read
 * from /proc for CPU and memory, so the server must run on the same machine.
 *
 *   php capacity.php pages port=3999 pids=1,2,3 worker=3 route=/docs/api conc=16 secs=10
 *   php capacity.php tabs  port=3999 pids=1,2,3 worker=3 route=/ n=1000 observe=100 idle=20 \
 *                          churn=10 tabrates=100,300 bcrates=5,20 phase=10
 *
 * pages: keep-alive GETs at a fixed concurrency (no SSE, like a crawler).
 * tabs:  open n tabs (page + SSE stream), then measure idle cost, visitor churn, private
 *        actions and shared clicks at fixed open-loop rates. Only `observe` tabs decode their
 *        stream; the rest read and discard it, so the harness stays off the critical path.
 *
 * Every request carries Origin and Accept-Encoding: br (br=0 turns Brotli off), as a browser does.
 */

use OpenSwoole\Coroutine as Co;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Client as TcpClient;

$mode = $argv[1] ?? 'tabs';
$o = [];
foreach (array_slice($argv, 2) as $a) {
    [$k, $v] = explode('=', $a, 2) + [1 => ''];
    $o[$k] = $v;
}
$port = (int) ($o['port'] ?? 3999);
$pids = array_map('intval', array_filter(explode(',', $o['pids'] ?? '')));
$worker = (int) ($o['worker'] ?? 0);
$origin = "http://127.0.0.1:{$port}";

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

    public function until(string $sep, float $timeout = 10.0, ?Closure $stop = null): ?string {
        while (($p = strpos($this->buf, $sep)) === false) {
            if (!$this->fill($timeout)) {
                if ($stop !== null && $stop()) {
                    return null;
                }
                if ($stop === null) {
                    throw new RuntimeException('timeout');
                }
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
        $lines = explode("\r\n", (string) $this->until("\r\n\r\n", $timeout));
        preg_match('#^HTTP/1\.[01] (\d+)#', $lines[0], $m);
        $h = ['_cookies' => []];
        foreach (array_slice($lines, 1) as $l) {
            [$k, $v] = array_map('trim', explode(':', $l, 2)) + [1 => ''];
            $k = strtolower($k);
            if ($k === 'set-cookie') {
                $h['_cookies'][] = explode(';', $v)[0];
            } else {
                $h[$k] = $v;
            }
        }

        return [(int) ($m[1] ?? 0), $h];
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    public function request(string $method, string $path, array $headers, string $body = '', float $timeout = 10.0): array {
        $h = "{$method} {$path} HTTP/1.1\r\nHost: 127.0.0.1:{$this->port}\r\n";
        foreach ($headers as $k => $v) {
            $h .= "{$k}: {$v}\r\n";
        }
        if ($method === 'POST') {
            $h .= 'Content-Length: ' . strlen($body) . "\r\n";
        }
        $this->send($h . "\r\n" . $body);
        [$status, $hdrs] = $this->head($timeout);
        $raw = '';
        if (isset($hdrs['content-length'])) {
            $raw = $this->take((int) $hdrs['content-length'], $timeout);
        } elseif (str_contains((string) ($hdrs['transfer-encoding'] ?? ''), 'chunked')) {
            while (($n = hexdec(trim((string) $this->until("\r\n", $timeout)))) > 0) {
                $raw .= $this->take((int) $n, $timeout);
                $this->until("\r\n", $timeout);
            }
            $this->until("\r\n", $timeout);
        }
        if (($hdrs['content-encoding'] ?? '') === 'br') {
            $raw = (string) brotli_uncompress($raw);
        }

        return [$status, $hdrs, $raw];
    }

    /** Read a chunked, possibly brotli-encoded body until $stop() and hand decoded text to $onText. */
    public function stream(bool $br, Closure $onText, Closure $stop, bool $observe = true, ?Closure $onRaw = null): void {
        $dec = $br && $observe ? brotli_uncompress_init() : null;
        while (!$stop()) {
            $line = $this->until("\r\n", 0.5, $stop);
            if ($line === null) {
                return;
            }
            $n = (int) hexdec(trim($line));
            if ($n === 0) {
                return;
            }
            $data = $this->take($n, 30);
            $this->until("\r\n", 30);
            if ($onRaw !== null) {
                $onRaw(strlen($data));
            }
            if (!$observe) {
                continue;
            }
            $text = $dec !== null ? brotli_uncompress_add($dec, $data, BROTLI_PROCESS) : $data;
            if (is_string($text) && $text !== '') {
                $onText($text);
            }
        }
    }

    public function close(): void {
        $this->c->close();
    }

    /** @return bool false on timeout */
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

/** @param list<int> $pids @return int CPU ticks (1/100 s) used by these processes, threads included */
function cpuTicks(array $pids): int {
    $t = 0;
    foreach ($pids as $pid) {
        $stat = @file_get_contents("/proc/{$pid}/stat");
        if ($stat === false) {
            continue;
        }
        $f = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        $t += (int) $f[11] + (int) $f[12];
    }

    return $t;
}

/** @return array{rss: float, pss: float} MB */
function mem(int $pid): array {
    $s = (string) @file_get_contents("/proc/{$pid}/smaps_rollup");
    preg_match('/^Rss:\s+(\d+)/m', $s, $r);
    preg_match('/^Pss:\s+(\d+)/m', $s, $p);

    return ['rss' => ((int) ($r[1] ?? 0)) / 1024, 'pss' => ((int) ($p[1] ?? 0)) / 1024];
}

function pct(array $xs, float $p): float {
    if ($xs === []) {
        return NAN;
    }
    sort($xs);

    return $xs[min(count($xs) - 1, (int) floor($p * count($xs)))];
}

function ms(float $s): string {
    return is_nan($s) ? '-' : number_format($s * 1000, 1);
}

function selfCpu(): float {
    $r = getrusage();

    return $r['ru_utime.tv_sec'] + $r['ru_utime.tv_usec'] / 1e6 + $r['ru_stime.tv_sec'] + $r['ru_stime.tv_usec'] / 1e6;
}

$browser = (($o['br'] ?? '1') === '1' ? ['Accept-Encoding' => 'br'] : []) + ['User-Agent' => 'capacity-bench'];

if ($mode === 'pages') {
    Co::run(function () use ($o, $port, $pids, $worker, $browser): void {
        $route = $o['route'] ?? '/docs/api';
        $conc = (int) ($o['conc'] ?? 20);
        $secs = (float) ($o['secs'] ?? 15);
        $lat = [];
        $errors = 0;
        $bytes = 0;
        $m0 = $worker ? mem($worker) : null;
        $t0 = microtime(true);
        $c0 = cpuTicks($pids);
        $s0 = selfCpu();
        $wg = new Channel($conc);
        for ($i = 0; $i < $conc; ++$i) {
            Co::create(function () use ($port, $route, $secs, $t0, $browser, &$lat, &$errors, &$bytes, $wg): void {
                $conn = new Conn($port);
                while (microtime(true) - $t0 < $secs) {
                    $s = microtime(true);

                    try {
                        [$st, , $body] = $conn->request('GET', $route, $browser + ['Accept' => 'text/html']);
                        $st === 200 ? $lat[] = microtime(true) - $s : ++$errors;
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
        $cpu = (cpuTicks($pids) - $c0) / 100;
        $n = count($lat);
        printf(
            "pages %s conc=%d: %d ok, %d errors in %.1fs = %.0f req/s; latency p50 %s ms p99 %s ms; server CPU %.2fs = %.2f ms/req (%.0f%% of one core); harness CPU %.0f%%\n",
            $route,
            $conc,
            $n,
            $errors,
            $dt,
            $n / $dt,
            ms(pct($lat, 0.5)),
            ms(pct($lat, 0.99)),
            $cpu,
            $n ? $cpu * 1000 / $n : 0,
            $cpu / $dt * 100,
            (selfCpu() - $s0) / $dt * 100
        );
        if ($worker) {
            $m1 = mem($worker);
            printf("  worker memory %.1f -> %.1f MB RSS (%.1f -> %.1f MB PSS), page contexts wait for the connect deadline\n", $m0['rss'], $m1['rss'], $m0['pss'], $m1['pss']);
        }
    });

    exit(0);
}

// ── tabs: open N tabs, idle, private actions, shared broadcasts ─────────────
Co::run(function () use ($o, $port, $pids, $worker, $origin, $browser): void {
    $route = $o['route'] ?? '/';
    $n = (int) ($o['n'] ?? 500);
    $idle = (float) ($o['idle'] ?? 20);
    $phase = (float) ($o['phase'] ?? 15);
    $tabRates = array_filter(array_map('floatval', explode(',', $o['tabrates'] ?? '')));
    $bcRates = array_filter(array_map('floatval', explode(',', $o['bcrates'] ?? '')));
    $rampConc = (int) ($o['ramp'] ?? 50);
    $br = ($o['br'] ?? '1') === '1';
    $observeN = (int) ($o['observe'] ?? 200);
    $every = max(1, intdiv($n, max(1, $observeN)));
    $sharedSig = $o['shared'] ?? 'home_counter_shared_counter_counter____ckdn';
    $tabSig = $o['tabsig'] ?? 'session_counter_count____dn';
    $tabAction = $o['tabaction'] ?? '/_action/session-counter-increment';
    $bcAction = $o['bcaction'] ?? '/_action/increment';
    $stop = false;
    $stopFn = static function () use (&$stop): bool { return $stop; };

    $baseMem = $worker ? mem($worker) : null;
    $baseCpu = cpuTicks($pids);
    $t0 = microtime(true);

    /** @var list<array<string, mixed>> $tabs */
    $tabs = [];
    $failed = 0;
    $ready = new Channel($n);
    $sem = new Channel($rampConc);
    for ($i = 0; $i < $rampConc; ++$i) {
        $sem->push(1);
    }
    for ($i = 0; $i < $n; ++$i) {
        $sem->pop();
        Co::create(function () use ($i, $every, $port, $route, $br, $browser, $sharedSig, $tabSig, $stopFn, $sem, $ready, &$tabs, &$failed): void {
            $announced = false;

            try {
                $c = new Conn($port);
                [$st, $h, $html] = $c->request('GET', $route, $browser + ['Accept' => 'text/html']);
                $c->close();
                if ($st !== 200 || !preg_match("/data-signals='([^']+)'/", $html, $m)) {
                    throw new RuntimeException("page {$st}");
                }
                $ctx = (string) (json_decode(html_entity_decode($m[1]), true)['via_ctx'] ?? '');
                $cookie = implode('; ', $h['_cookies']);
                $tab = ['ctx' => $ctx, 'cookie' => $cookie, 'sharedT' => [], 'sharedV' => [], 'shared' => -1, 'tab' => -1, 'tabSeen' => [], 'frames' => 0, 'bytes' => 0, 'wire' => 0, 'obs' => $i % $every === 0];
                $tabs[$i] = &$tab;
                $sse = new Conn($port);
                $q = rawurlencode((string) json_encode(['via_ctx' => $ctx]));
                $sse->send("GET /_sse?datastar={$q} HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: text/event-stream\r\n"
                    . ($br ? "Accept-Encoding: br\r\n" : '') . "Cookie: {$cookie}\r\n\r\n");

                // A stream with nothing to send on connect flushes no headers until its first
                // keep-alive, so count the tab as open once the request is in.
                try {
                    [$sst, $sh] = $sse->head(0.5);
                } catch (RuntimeException $e) {
                    if ($e->getMessage() !== 'timeout') {
                        throw $e;
                    }
                    $sem->push(1);
                    $ready->push(true);
                    $announced = true;
                    [$sst, $sh] = $sse->head(120);
                }
                if ($sst !== 200) {
                    throw new RuntimeException("sse {$sst}");
                }
                if (!$announced) {
                    $sem->push(1);
                    $ready->push(true);
                    $announced = true;
                }
                $pending = '';
                $sse->stream(($sh['content-encoding'] ?? '') === 'br', function (string $text) use (&$tab, &$pending, $sharedSig, $tabSig): void {
                    $tab['bytes'] += strlen($text);
                    $pending .= $text;
                    while (($p = strpos($pending, "\n\n")) !== false) {
                        $ev = substr($pending, 0, $p);
                        $pending = substr($pending, $p + 2);
                        ++$tab['frames'];
                        $now = microtime(true);
                        if (str_contains($ev, $sharedSig) && preg_match('/"' . $sharedSig . '":(\d+)/', $ev, $m) && (int) $m[1] > $tab['shared']) {
                            $tab['shared'] = (int) $m[1];
                            $tab['sharedT'][] = $now;
                            $tab['sharedV'][] = (int) $m[1];
                        }
                        if (str_contains($ev, $tabSig) && preg_match('/"' . $tabSig . '":(\d+)/', $ev, $m) && (int) $m[1] > $tab['tab']) {
                            $tab['tab'] = (int) $m[1];
                            $tab['tabSeen'][(int) $m[1]] = $now;
                        }
                    }
                }, $stopFn, $tab['obs'], function (int $len) use (&$tab): void { $tab['wire'] += $len; });
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
    $rampSecs = microtime(true) - $t0;
    $rampCpu = (cpuTicks($pids) - $baseCpu) / 100;
    Co::usleep(1_500_000); // let the presence broadcast settle
    $m1 = $worker ? mem($worker) : null;
    printf(
        "tabs route=%s n=%d: %d open, %d failed, ramp %.1fs (%.0f tabs/s), server CPU %.2fs = %.2f ms per tab opened\n",
        $route,
        $n,
        $ok,
        $failed,
        $rampSecs,
        $ok / $rampSecs,
        $rampCpu,
        $ok ? $rampCpu * 1000 / $ok : 0
    );
    if ($worker) {
        printf(
            "  worker memory %.1f -> %.1f MB RSS (+%.1f), PSS %.1f -> %.1f MB (+%.1f): %.1f KB RSS per open tab\n",
            $baseMem['rss'],
            $m1['rss'],
            $m1['rss'] - $baseMem['rss'],
            $baseMem['pss'],
            $m1['pss'],
            $m1['pss'] - $baseMem['pss'],
            ($m1['rss'] - $baseMem['rss']) * 1024 / max(1, $ok)
        );
    }

    if ($idle > 0) {
        $c0 = cpuTicks($pids);
        Co::usleep((int) ($idle * 1e6));
        $cpu = (cpuTicks($pids) - $c0) / 100;
        printf("  idle %.0fs with %d tabs: server CPU %.2fs (%.1f%% of one core)\n", $idle, $ok, $cpu, $cpu / $idle * 100);
    }

    $churnN = (int) ($o['churn'] ?? 0);
    if ($churnN > 0) {
        $churnRoute = $o['churnroute'] ?? '/docs/faq';
        $wire0 = array_sum(array_map(static fn ($t) => is_array($t) ? $t['wire'] : 0, $tabs));
        $c0 = cpuTicks($pids);
        for ($k = 0; $k < $churnN; ++$k) {
            $c = new Conn($port);
            [, $h, $html] = $c->request('GET', $churnRoute, $browser + ['Accept' => 'text/html']);
            $c->close();
            preg_match("/data-signals='([^']+)'/", $html, $m);
            $ctx = (string) (json_decode(html_entity_decode($m[1] ?? '{}'), true)['via_ctx'] ?? '');
            $sse = new Conn($port);
            $sse->send('GET /_sse?datastar=' . rawurlencode((string) json_encode(['via_ctx' => $ctx])) . " HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nAccept: text/event-stream\r\nAccept-Encoding: br\r\nCookie: " . implode('; ', $h['_cookies']) . "\r\n\r\n");
            // A static page sends nothing on connect, so its headers may never arrive; don't wait.
            Co::usleep(500_000);
            $sse->close();
            Co::usleep(700_000);
        }
        $cpu = (cpuTicks($pids) - $c0) / 100;
        $wire = array_sum(array_map(static fn ($t) => is_array($t) ? $t['wire'] : 0, $tabs)) - $wire0;
        printf(
            "  churn: %d visitors came and went on %s while %d tabs were open: server CPU %.2fs = %.1f ms per visitor; the open tabs received %.1f KB on the wire per visitor in total\n",
            $churnN,
            $churnRoute,
            $ok,
            $cpu,
            $cpu * 1000 / $churnN,
            $wire / 1024 / $churnN
        );
    }
    $all = array_keys(array_filter($tabs, static fn ($t) => is_array($t)));
    $live = array_values(array_filter($all, static fn ($i) => $tabs[$i]['obs']));
    printf("  observing %d of %d tabs\n", count($live), count($all));

    // Open-loop load at a fixed rate; latency counts from the scheduled send time.
    $drive = function (float $rate, string $action, Closure $onSend) use ($port, $origin, $phase, &$tabs, $live): array {
        $poolSize = 64;
        $jobs = new Channel(100000);
        $done = new Channel($poolSize);
        $resp = [];
        $errors = 0;
        $codes = [];
        for ($w = 0; $w < $poolSize; ++$w) {
            Co::create(function () use ($port, $origin, $action, $jobs, $done, &$tabs, &$resp, &$errors, &$codes): void {
                $c = new Conn($port);
                while (($job = $jobs->pop()) !== false && $job !== null) {
                    [$i, $sched] = $job;

                    try {
                        [$st] = $c->request('POST', $action, ['Origin' => $origin, 'Content-Type' => 'application/json', 'Cookie' => $tabs[$i]['cookie']], (string) json_encode(['via_ctx' => $tabs[$i]['ctx']]));
                        $codes[$st] = ($codes[$st] ?? 0) + 1;
                        $st === 200 ? $resp[] = microtime(true) - $sched : ++$errors;
                    } catch (Throwable) {
                        ++$errors;
                        $c = new Conn($port);
                    }
                }
                $c->close();
                $done->push(1);
            });
        }
        $start = microtime(true);
        $sent = 0;
        while (($t = microtime(true) - $start) < $phase) {
            $due = (int) floor($t * $rate) + 1;
            for (; $sent < $due; ++$sent) {
                $sched = $start + $sent / $rate;
                $i = $live[random_int(0, count($live) - 1)];
                $onSend($i, $sched, $sent);
                $jobs->push([$i, $sched]);
            }
            Co::usleep(1000);
        }
        $jobs->close();
        for ($w = 0; $w < $poolSize; ++$w) {
            $done->pop();
        }

        return [$sent, $resp, $errors, microtime(true) - $start, $codes];
    };

    foreach ($tabRates as $rate) {
        $pendingTab = [];
        $c0 = cpuTicks($pids);
        $s0 = selfCpu();
        [$sent, $resp, $errors, $dt, $codes] = $drive($rate, $tabAction, function (int $i, float $sched) use (&$tabs, &$pendingTab): void {
            $tabs[$i]['want'] = ($tabs[$i]['want'] ?? max(0, $tabs[$i]['tab'])) + 1;
            $pendingTab[] = [$i, $tabs[$i]['want'], $sched];
        });
        $cpu = (cpuTicks($pids) - $c0) / 100;
        Co::usleep(1_000_000);
        $patch = [];
        $missing = 0;
        foreach ($pendingTab as [$i, $want, $sched]) {
            $seen = null;
            foreach ($tabs[$i]['tabSeen'] as $v => $t) {
                if ($v >= $want) {
                    $seen = $seen === null ? $t : min($seen, $t);
                }
            }
            $seen === null ? ++$missing : $patch[] = $seen - $sched;
        }
        printf(
            "  private actions %.0f/s: %d sent, %d ok, %d errors %s in %.1fs; response p50 %s p99 %s ms; patch seen p50 %s p99 %s ms, %d never seen; server CPU %.0f%% of one core = %.2f ms/action; harness CPU %.0f%%\n",
            $rate,
            $sent,
            count($resp),
            $errors,
            json_encode($codes),
            $dt,
            ms(pct($resp, 0.5)),
            ms(pct($resp, 0.99)),
            ms(pct($patch, 0.5)),
            ms(pct($patch, 0.99)),
            $missing,
            $cpu / $dt * 100,
            count($resp) ? ($cpu * 1000 / count($resp)) : 0,
            (selfCpu() - $s0) / $dt * 100
        );
    }

    foreach ($bcRates as $rate) {
        $base = max(array_map(static fn ($i) => $tabs[$i]['shared'], $live));
        $sends = [];
        $c0 = cpuTicks($pids);
        $s0 = selfCpu();
        $frames0 = array_sum(array_map(static fn ($i) => $tabs[$i]['frames'], $live));
        $bytes0 = array_sum(array_map(static fn ($i) => $tabs[$i]['bytes'], $live));
        $wire0 = array_sum(array_map(static fn ($i) => $tabs[$i]['wire'], $all));
        [$sent, $resp, $errors, $dt, $codes] = $drive($rate, $bcAction, function (int $i, float $sched, int $k) use (&$sends): void {
            $sends[] = $sched;
        });
        $cpu = (cpuTicks($pids) - $c0) / 100;
        Co::usleep(2_000_000);
        $wire = array_sum(array_map(static fn ($i) => $tabs[$i]['wire'], $all)) - $wire0;
        $frames = array_sum(array_map(static fn ($i) => $tabs[$i]['frames'], $live)) - $frames0;
        $bytes = array_sum(array_map(static fn ($i) => $tabs[$i]['bytes'], $live)) - $bytes0;
        // Action k (in send order) is in every tab once the tab shows base + k + 1 or more.
        $lats = [];
        $final = $base + count($resp);
        $converged = 0;
        foreach ($live as $i) {
            $converged += $tabs[$i]['shared'] >= $final ? 1 : 0;
        }
        foreach ($sends as $k => $sched) {
            $want = $base + $k + 1;
            $worst = 0.0;
            foreach ($live as $i) {
                $vals = $tabs[$i]['sharedV'];
                $lo = 0;
                $hi = count($vals);
                while ($lo < $hi) {
                    $mid = ($lo + $hi) >> 1;
                    $vals[$mid] >= $want ? $hi = $mid : $lo = $mid + 1;
                }
                if ($lo === count($vals)) {
                    $worst = INF;

                    break;
                }
                $worst = max($worst, $tabs[$i]['sharedT'][$lo] - $sched);
            }
            $lats[] = $worst;
        }
        $finite = array_values(array_filter($lats, 'is_finite'));
        printf(
            "  shared clicks %.0f/s to %d tabs: %d sent, %d ok, %d errors %s; response p50 %s p99 %s ms; click visible in every observed tab p50 %s p99 %s ms (%d never); %d/%d tabs on the final value; %.1f frames, %.1f KB decoded, %.2f KB on the wire per tab per s; server CPU %.0f%% of one core; harness CPU %.0f%%\n",
            $rate,
            count($live),
            $sent,
            count($resp),
            $errors,
            json_encode($codes),
            ms(pct($resp, 0.5)),
            ms(pct($resp, 0.99)),
            ms(pct($finite, 0.5)),
            ms(pct($finite, 0.99)),
            count($lats) - count($finite),
            $converged,
            count($live),
            $frames / count($live) / ($dt + 2),
            $bytes / 1024 / count($live) / ($dt + 2),
            $wire / 1024 / count($all) / ($dt + 2),
            $cpu / $dt * 100,
            (selfCpu() - $s0) / ($dt + 2) * 100
        );
    }

    $stop = true;
    Co::usleep(1_500_000);
    if ($worker) {
        $m2 = mem($worker);
        printf("  after closing: worker %.1f MB RSS, %.1f MB PSS\n", $m2['rss'], $m2['pss']);
    }
});
