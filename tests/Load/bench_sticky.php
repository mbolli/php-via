<?php

declare(strict_types=1);

/**
 * bench_sticky.php — Sticky-routing multi-worker scaling benchmark.
 *
 * Runs N isolated bench_app processes (separate PIDs, TAB scope, no broker)
 * behind a Caddy Docker container that provides:
 *   - TLS termination with the project's self-signed cert
 *   - Cookie-based sticky routing (lb_policy cookie caddy_lb)
 *
 * Each hammer process loads one page through Caddy, receives the caddy_lb
 * sticky cookie, and subsequently routes all actions to the same upstream.
 * This eliminates the "wrong worker" problem from the multi-worker-4w profile.
 *
 * Architecture
 * ────────────
 *   action_hammer × N  ──HTTPS──►  Caddy:8443  ──HTTP──►  bench_app:3101
 *                                       (cookie)        ►  bench_app:3102
 *                                                        ►  …
 *
 * Test matrix: worker count × workload
 *   Worker counts:  1, 2, 4, 8, 12, 16  (configurable)
 *   Workloads:      counter, io, cpu     (no spreadsheet — SQLite shared-file contention)
 *   Profile:        jit-tracing (fixed — matches the best single-worker profile)
 *
 * Output table per workload:
 *   | Workers | Total req/s | vs 1-worker | Efficiency | Avg HTTP OK% |
 *
 * Notes
 * ─────
 * - Docker Desktop on WSL2: containers run in a Hyper-V VM. --network host does
 *   NOT work. Caddy uses host.docker.internal (via --add-host=host.docker.internal:host-gateway)
 *   to reach bench_app processes on the WSL2 host.
 * - SSE patch delivery stats are unreliable in this setup: the SSE connection
 *   (raw TCP in openSseObserver) does not send the caddy_lb cookie, so it may
 *   land on a different upstream than the actions. Throughput (HTTP OK%) is the
 *   meaningful metric here.
 * - Spreadsheet workload is excluded: N bench_app processes would share the same
 *   SQLite file, creating write-lock contention that confounds scaling results.
 *
 * Usage
 * ─────
 *   php tests/Load/bench_sticky.php [options]
 *
 * Options
 *   --workers=LIST        Comma-separated worker counts (default: 1,2,4,8,12,16)
 *   --workload=LIST       Comma-separated workloads: counter,io,cpu (default: counter,io)
 *   --actions=N           Actions per hammer process (default: 2500)
 *   --concurrency=N       Concurrent coroutines per hammer (default: 200)
 *   --hammers=N           Hammer processes per worker (default: 1)
 *   --caddy-port=N        Caddy HTTPS port (default: 8443)
 *   --base-port=N         First bench_app port (default: 3101)
 *   --timeout=SEC         Per-request timeout for hammers (default: 5)
 *   --warmup=N            Warmup GETs per bench_app process (default: 8)
 *   --caddy-image=IMG     Docker image for Caddy (default: caddy:latest)
 *   --help                Show this help
 *
 * Prerequisites
 * ─────────────
 *   - Docker available in PATH (docker ps must succeed)
 *   - ext-openswoole in the CLI PHP binary (same binary runs bench_app + hammers)
 *   - Ports --caddy-port and --base-port … --base-port+15 must be free
 *   - bench/docker/Caddyfile.template must exist (relative to repo root)
 *   - certs/dev.crt and certs/dev.key must exist (relative to repo root)
 */
if (!extension_loaded('openswoole')) {
    fwrite(STDERR, "Error: ext-openswoole is required.\n");

    exit(1);
}

// ── Paths ─────────────────────────────────────────────────────────────────────

$repoRoot = (string) realpath(__DIR__ . '/../../');
$benchApp = __DIR__ . '/bench_app.php';
$hammerScript = __DIR__ . '/action_hammer.php';
$caddyTemplate = $repoRoot . '/bench/docker/Caddyfile.template';
$certFile = $repoRoot . '/certs/dev.crt';
$keyFile = $repoRoot . '/certs/dev.key';
$caddyWorkDir = sys_get_temp_dir() . '/via_bench_caddy_' . getmypid();
$phpBin = PHP_BINARY;

// ── CLI arguments ─────────────────────────────────────────────────────────────

$opts = getopt('', [
    'workers:', 'workload:', 'actions:', 'concurrency:', 'hammers:',
    'caddy-port:', 'base-port:', 'timeout:', 'warmup:', 'caddy-image:', 'help',
]);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__);

    exit(0);
}

/** @var list<int> $workerCounts */
$workerCounts = array_map('intval', explode(',', (string) ($opts['workers'] ?? '1,2,4,8,12,16')));
$filterLoads = array_map('trim', explode(',', (string) ($opts['workload'] ?? 'counter,io')));
$actionsPerHam = (int) ($opts['actions'] ?? 2500);
$concurrPerHam = (int) ($opts['concurrency'] ?? 200);
$hammersPerW = (int) ($opts['hammers'] ?? 1);
$caddyPort = (int) ($opts['caddy-port'] ?? 8443);
$basePort = (int) ($opts['base-port'] ?? 3101);
$timeoutSec = (int) ($opts['timeout'] ?? 5);
$warmupCount = (int) ($opts['warmup'] ?? 8);
$caddyImage = (string) ($opts['caddy-image'] ?? 'caddy:latest');
$debugServer = (bool) (getenv('VIA_BENCH_DEBUG') ?: false);

// ── Workload definitions ──────────────────────────────────────────────────────

/** @var list<array{name:string,route:string,action:string,signal:string,signals:string,label:string}> */
$allWorkloads = [
    [
        'name' => 'counter',
        'route' => '/bench/counter',
        'action' => 'increment',
        'signal' => 'count',
        'signals' => '{}',
        'label' => 'Counter (trivial increment — framework overhead baseline)',
    ],
    [
        'name' => 'io',
        'route' => '/bench/io',
        'action' => 'increment',
        'signal' => 'count',
        'signals' => '{}',
        'label' => 'IO (Co::sleep 2 ms — simulated DB latency)',
    ],
    [
        'name' => 'cpu',
        'route' => '/bench/cpu',
        'action' => 'increment',
        'signal' => 'count',
        'signals' => '{}',
        'label' => 'CPU (Mandelbrot 50×50 — pure float arithmetic)',
    ],
];

$workloads = array_filter($allWorkloads, fn ($w) => in_array($w['name'], $filterLoads, true));
$workloads = array_values($workloads);

// ── JIT-tracing INI flags (fixed profile) ────────────────────────────────────

$jitFlags = [
    'opcache.enable=1',
    'opcache.enable_cli=1',
    'opcache.memory_consumption=256',
    'opcache.interned_strings_buffer=64',
    'opcache.max_accelerated_files=100000',
    'opcache.validate_timestamps=0',
    'opcache.jit=tracing',
    'opcache.jit_buffer_size=128M',
];

// ── Pre-flight checks ─────────────────────────────────────────────────────────

foreach ([$caddyTemplate, $certFile, $keyFile] as $required) {
    if (!file_exists($required)) {
        fwrite(STDERR, "Error: required file not found: {$required}\n");

        exit(1);
    }
}

$dockerCheck = shell_exec('docker info 2>&1');
if (!str_contains((string) $dockerCheck, 'Server Version') && !str_contains((string) $dockerCheck, 'Containers')) {
    fwrite(STDERR, "Error: Docker is not running or not available.\n");
    fwrite(STDERR, "       docker info output:\n{$dockerCheck}\n");

    exit(1);
}

if (empty($workloads)) {
    fwrite(STDERR, "Error: no valid workloads selected. Choose from: counter, io, cpu\n");

    exit(1);
}

// ── Banner ────────────────────────────────────────────────────────────────────

echo "bench_sticky.php — php-via sticky-routing multi-worker scaling benchmark\n";
echo str_repeat('─', 72) . "\n";
printf("  PHP binary     : %s\n", $phpBin);
printf("  PHP version    : %s\n", PHP_VERSION);
printf("  Caddy image    : %s\n", $caddyImage);
printf("  Caddy port     : %d (HTTPS)\n", $caddyPort);
printf("  Base port      : %d … %d\n", $basePort, $basePort + max($workerCounts) - 1);
printf("  Worker counts  : %s\n", implode(', ', $workerCounts));
printf("  Workloads      : %s\n", implode(', ', array_column($workloads, 'name')));
printf("  Actions/hammer : %d\n", $actionsPerHam);
printf("  Concurrency    : %d  (%d total/worker)\n", $concurrPerHam, $concurrPerHam * $hammersPerW);
printf("  Hammers/worker : %d\n", $hammersPerW);
printf("  Profile        : jit-tracing (fixed)\n");
echo "\n";

// ── Helper functions ──────────────────────────────────────────────────────────

/**
 * Start one bench_app process on the given port.
 *
 * @param list<string> $flags "-d key=value" INI flags
 *
 * @return false|resource
 */
function startBenchApp(int $port, array $flags, string $phpBin, string $benchApp, bool $debug): mixed {
    $cmd = [$phpBin];
    foreach ($flags as $f) {
        $cmd[] = '-d';
        $cmd[] = $f;
    }
    $cmd[] = $benchApp;

    $null = '/dev/null';
    $stderrTarget = $debug ? STDERR : ['file', $null, 'w'];
    $desc = [
        0 => ['file', $null, 'r'],
        1 => ['file', $null, 'w'],
        2 => $stderrTarget,
    ];

    $env = array_merge(
        getenv() ?: [],
        [
            'VIA_PORT' => (string) $port,
            'VIA_DISABLE_HTTPS' => '1',
            'VIA_BENCH_WORKERS' => '1',
        ],
    );

    return proc_open($cmd, $desc, $pipes, dirname($benchApp), $env);
}

/**
 * Poll /_health over plain HTTP until the server is ready.
 */
function waitForBenchApp(int $port, int $maxAttempts = 50, int $sleepUs = 200_000): bool {
    $ctx = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);
    $url = "http://127.0.0.1:{$port}/_health";

    for ($i = 0; $i < $maxAttempts; ++$i) {
        if (@file_get_contents($url, false, $ctx) !== false) {
            return true;
        }
        usleep($sleepUs);
    }

    return false;
}

/**
 * Gracefully stop a server process.
 *
 * @param resource $proc
 */
function stopBenchApp(mixed $proc): void {
    if (!is_resource($proc)) {
        return;
    }

    proc_terminate($proc, SIGTERM);
    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        if (!proc_get_status($proc)['running']) {
            proc_close($proc);

            return;
        }
        usleep(100_000);
    }

    proc_terminate($proc, SIGKILL);
    proc_close($proc);
}

/**
 * Write a Caddyfile for $n upstreams starting at $basePort.
 * Returns the path written.
 */
function writeCaddyfile(
    string $template,
    string $workDir,
    int $n,
    int $basePort,
    string $certFile,
    string $keyFile
): string {
    $upstreams = [];

    for ($i = 0; $i < $n; ++$i) {
        $upstreams[] = 'host.docker.internal:' . ($basePort + $i);
    }

    $caddyfile = str_replace(
        ['%%UPSTREAMS%%', '%%CERT%%', '%%KEY%%'],
        [implode(' ', $upstreams), '/etc/caddy/certs/dev.crt', '/etc/caddy/certs/dev.key'],
        $template
    );

    if (!is_dir($workDir)) {
        mkdir($workDir, 0o700, true);
    }

    $path = $workDir . '/Caddyfile';
    file_put_contents($path, $caddyfile);

    return $path;
}

/**
 * Start Caddy in Docker. Returns the container ID or '' on failure.
 */
function startCaddy(
    string $image,
    int $caddyPort,
    string $caddyfileDir,
    string $certsDir,
    string $containerName
): string {
    $cmd = implode(' ', [
        'docker run -d --rm',
        '--name ' . escapeshellarg($containerName),
        '--add-host=host.docker.internal:host-gateway',
        '-p ' . (int) $caddyPort . ':443',
        '-v ' . escapeshellarg($caddyfileDir . '/Caddyfile') . ':/etc/caddy/Caddyfile:ro',
        '-v ' . escapeshellarg($certsDir) . ':/etc/caddy/certs:ro',
        escapeshellarg($image),
    ]);

    $output = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $output, $rc);

    if ($rc !== 0) {
        fwrite(STDERR, "Error: docker run failed:\n" . implode("\n", $output) . "\n");

        return '';
    }

    return trim(implode('', $output));
}

/**
 * Poll /_health through Caddy (HTTPS, cert verification disabled).
 */
function waitForCaddy(int $caddyPort, int $maxAttempts = 60, int $sleepUs = 500_000): bool {
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
        'http' => ['timeout' => 1.0, 'ignore_errors' => true],
    ]);
    $url = "https://127.0.0.1:{$caddyPort}/_health";

    for ($i = 0; $i < $maxAttempts; ++$i) {
        $r = @file_get_contents($url, false, $ctx);

        if ($r !== false) {
            return true;
        }

        usleep($sleepUs);
    }

    return false;
}

/**
 * Stop and remove the Caddy container by name.
 */
function stopCaddy(string $containerName): void {
    shell_exec('docker stop ' . escapeshellarg($containerName) . ' 2>/dev/null');
}

/**
 * Send warmup GETs directly to each bench_app (bypassing Caddy) to prime OPcache + JIT.
 *
 * @param list<array{route:string,...}> $workloads
 */
function warmupAll(int $basePort, int $n, int $count, array $workloads): void {
    $ctx = stream_context_create(['http' => ['timeout' => 2.0, 'ignore_errors' => true]]);

    for ($i = 0; $i < $n; ++$i) {
        $port = $basePort + $i;

        foreach ($workloads as $wl) {
            $url = "http://127.0.0.1:{$port}{$wl['route']}";

            for ($j = 0; $j < $count; ++$j) {
                @file_get_contents($url, false, $ctx);
            }
        }
    }
}

/**
 * Spawn action_hammer.php as a separate process with non-blocking stdout.
 * Returns [proc_resource, stdout_pipe].
 *
 * @return null|array{0: resource, 1: resource} null if proc_open fails
 */
function spawnHammer(
    int $caddyPort,
    string $route,
    string $action,
    string $signal,
    string $signals,
    int $actions,
    int $concurrency,
    int $timeoutSec,
    string $phpBin,
    string $hammerScript
): ?array {
    $cmd = [
        $phpBin,
        $hammerScript,
        "--url=https://127.0.0.1:{$caddyPort}",
        '--no-tls-verify',
        "--route={$route}",
        "--action={$action}",
        "--signal={$signal}",
        "--signals={$signals}",
        "--actions={$actions}",
        "--concurrency={$concurrency}",
        "--timeout={$timeoutSec}",
    ];

    $desc = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];

    $proc = proc_open($cmd, $desc, $pipes);

    if ($proc === false) {
        return null;
    }

    stream_set_blocking($pipes[1], false);

    return [$proc, $pipes[1]];
}

/**
 * Drain stdout from all hammer processes using stream_select until all finish.
 * Prints a live progress indicator while waiting.
 *
 * @param list<array{0:resource,1:resource}> $handles [proc, pipe] pairs
 *
 * @return list<string> Captured stdout per hammer (same order as $handles)
 */
function collectHammerOutputs(array $handles): array {
    $outputs = array_fill(0, count($handles), '');
    $pipes = array_column($handles, 1);

    // Index pipes by their key in $handles for output mapping.
    /** @var array<int, resource> $activePipes */
    $activePipes = $pipes;
    $dots = 0;

    while (!empty($activePipes)) {
        $read = array_values($activePipes);
        $write = $except = null;
        $changed = @stream_select($read, $write, $except, 5);

        if ($changed === false || $changed === 0) {
            echo '.';
            ++$dots;

            continue;
        }

        foreach ($read as $stream) {
            $key = array_search($stream, $activePipes, true);

            if ($key === false) {
                continue;
            }

            $chunk = fread($stream, 65536);

            if ($chunk === false || $chunk === '') {
                fclose($stream);
                unset($activePipes[$key]);
            } else {
                $outputs[$key] .= $chunk;
            }
        }
    }

    if ($dots > 0) {
        echo "\n";
    }

    // Close processes
    foreach ($handles as [$proc]) {
        proc_close($proc);
    }

    return $outputs;
}

/**
 * Parse throughput + HTTP OK% from action_hammer summary output.
 *
 * @return array{throughput:int,http_ok_pct:float}
 */
function parseHammerMetrics(string $output): array {
    $throughput = 0;
    $okPct = 0.0;

    if (preg_match('/HTTP OK\s+:\s+\d+\s+\((\d+\.?\d*)%\)/', $output, $m)) {
        $okPct = (float) $m[1];
    }

    if (preg_match('/Throughput\s+:\s+(\d+)\s+req\/s/', $output, $m)) {
        $throughput = (int) $m[1];
    }

    return ['throughput' => $throughput, 'http_ok_pct' => $okPct];
}

// ── Main benchmark loop ───────────────────────────────────────────────────────

$caddyTemplate = (string) file_get_contents($caddyTemplate);
$certsDir = dirname($certFile);
$containerBase = 'via_bench_caddy_' . getmypid();

/**
 * results[workload_name][worker_count] = ['throughput' => int, 'http_ok_pct' => float].
 *
 * @var array<string, array<int, array{throughput:int,http_ok_pct:float}>> $results
 */
$results = [];

foreach ($workloads as $wl) {
    $results[$wl['name']] = [];
}

foreach ($workerCounts as $n) {
    printf("▶ Workers: %d\n", $n);

    // ── 1. Start N bench_app processes ───────────────────────────────────────

    echo "  Starting {$n} bench_app process(es) ...";

    /** @var list<resource> $servers */
    $servers = [];
    $allReady = true;

    for ($i = 0; $i < $n; ++$i) {
        $port = $basePort + $i;
        $server = startBenchApp($port, $jitFlags, $phpBin, $benchApp, $debugServer);

        if ($server === false) {
            fwrite(STDERR, "\n  ERROR: proc_open failed for port {$port}\n");
            $allReady = false;

            break;
        }

        $servers[] = $server;

        if (!waitForBenchApp($port)) {
            fwrite(STDERR, "\n  ERROR: bench_app on port {$port} did not become ready\n");
            $allReady = false;

            break;
        }
    }

    if (!$allReady) {
        echo "\n  Skipping this worker count due to startup failure.\n\n";

        foreach ($servers as $s) {
            stopBenchApp($s);
        }

        continue;
    }

    echo " ready\n";

    // ── 2. Generate Caddyfile and start Caddy ────────────────────────────────

    writeCaddyfile($caddyTemplate, $caddyWorkDir, $n, $basePort, $certFile, $keyFile);

    $containerName = $containerBase . '_' . $n . 'w';
    echo "  Starting Caddy container ({$containerName}) ...";
    $containerId = startCaddy($caddyImage, $caddyPort, $caddyWorkDir, $certsDir, $containerName);

    if ($containerId === '') {
        echo " FAILED\n\n";

        foreach ($servers as $s) {
            stopBenchApp($s);
        }

        continue;
    }

    if (!waitForCaddy($caddyPort)) {
        echo " TIMEOUT\n";
        echo "  Caddy did not become healthy within 30 s — skipping.\n\n";
        stopCaddy($containerName);

        foreach ($servers as $s) {
            stopBenchApp($s);
        }

        continue;
    }

    echo " ready\n";

    // ── 3. Warm up OPcache + JIT on every upstream (bypass Caddy) ────────────

    echo "  Warming up ({$warmupCount} GETs × {$n} upstreams × " . count($workloads) . ' routes) ...';
    warmupAll($basePort, $n, $warmupCount, $workloads);
    echo " done\n";

    // ── 4. Run each workload ──────────────────────────────────────────────────

    foreach ($workloads as $wl) {
        $wName = $wl['name'];
        $hammerN = $n * $hammersPerW;

        printf("  %-12s : spawning %d hammer(s) ...\n", $wName, $hammerN);

        /** @var list<array{0:resource,1:resource}> $handles */
        $handles = [];

        for ($i = 0; $i < $hammerN; ++$i) {
            $h = spawnHammer(
                $caddyPort,
                $wl['route'],
                $wl['action'],
                $wl['signal'],
                $wl['signals'],
                $actionsPerHam,
                $concurrPerHam,
                $timeoutSec,
                $phpBin,
                $hammerScript
            );

            if ($h === null) {
                fwrite(STDERR, "  ERROR: could not spawn hammer {$i}\n");
            } else {
                $handles[] = $h;
            }
        }

        if (empty($handles)) {
            printf("  %-12s : SKIP (no hammers spawned)\n", $wName);

            continue;
        }

        echo '  Collecting output ';
        $outputs = collectHammerOutputs($handles);

        $totalThroughput = 0;
        $okPcts = [];
        $allOk = true;

        foreach ($outputs as $out) {
            $m = parseHammerMetrics($out);
            $totalThroughput += $m['throughput'];
            $okPcts[] = $m['http_ok_pct'];

            if ($m['http_ok_pct'] < 90.0) {
                $allOk = false;
            }
        }

        $avgOkPct = count($okPcts) > 0 ? array_sum($okPcts) / count($okPcts) : 0.0;

        printf(
            "  %-12s : total %5d req/s  avg HTTP OK %.1f%%%s\n",
            $wName,
            $totalThroughput,
            $avgOkPct,
            $allOk ? '' : ' ⚠ some hammers < 90% OK'
        );

        $results[$wName][$n] = [
            'throughput' => $totalThroughput,
            'http_ok_pct' => $avgOkPct,
        ];
    }

    // ── 5. Tear down ──────────────────────────────────────────────────────────

    stopCaddy($containerName);

    foreach ($servers as $s) {
        stopBenchApp($s);
    }

    usleep(500_000); // let OS release ports before next iteration
    echo "\n";
}

// ── Results tables ────────────────────────────────────────────────────────────

$divider = str_repeat('─', 72);

echo "\n" . str_repeat('═', 72) . "\n";
echo " SCALING RESULTS — jit-tracing profile, sticky cookie routing via Caddy\n";
echo str_repeat('═', 72) . "\n";

foreach ($workloads as $wl) {
    $wName = $wl['name'];
    $label = $wl['label'];

    if (empty($results[$wName])) {
        continue;
    }

    $baseline = $results[$wName][1]['throughput'] ?? 0;

    echo "\n{$label}\n";
    echo $divider . "\n";
    printf("%-8s | %10s | %11s | %10s | %10s\n", 'Workers', 'Total r/s', 'vs 1-worker', 'Efficiency', 'Avg OK%');
    echo $divider . "\n";

    foreach ($workerCounts as $n) {
        if (!isset($results[$wName][$n])) {
            printf("%-8d | %10s\n", $n, 'SKIPPED');

            continue;
        }

        $r = $results[$wName][$n];
        $throughput = $r['throughput'];
        $okPct = $r['http_ok_pct'];

        $deltaStr = '—';
        $effStr = '—';

        if ($baseline > 0 && $n > 1) {
            $ratio = $throughput / $baseline;
            $deltaStr = sprintf('%+.1f%%', ($ratio - 1.0) * 100.0);
            $effPct = ($ratio / $n) * 100.0;
            $effStr = sprintf('%.1f%%', $effPct);
        } elseif ($n === 1) {
            $deltaStr = 'baseline';
            $effStr = '100.0%';
        }

        printf(
            "%-8d | %10s | %11s | %10s | %9.1f%%\n",
            $n,
            number_format($throughput),
            $deltaStr,
            $effStr,
            $okPct
        );
    }
}

echo "\n" . str_repeat('─', 72) . "\n";
echo "Efficiency = (total req/s) / (N × 1-worker req/s) × 100%\n";
echo "            100% = perfect linear scaling\n";
echo "            <100% = overhead (Caddy, OS scheduling, context switching)\n\n";

// Cleanup temp dir
if (is_dir($caddyWorkDir)) {
    array_map('unlink', glob($caddyWorkDir . '/*') ?: []);
    @rmdir($caddyWorkDir);
}
