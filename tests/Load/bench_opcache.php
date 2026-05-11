<?php

declare(strict_types=1);

/**
 * bench_opcache.php — OPcache / JIT benchmark orchestrator.
 *
 * Cycles through seven PHP runtime profiles and three workloads, running
 * action_hammer.php twice per combination (cold + warm pass), then prints a
 * side-by-side comparison table.
 *
 * Profiles
 * ────────
 *  1. no-opcache          opcache completely disabled — absolute baseline
 *  2. opcache-default-cli default INI but with enable_cli=1 (normally off)
 *  3. opcache-tuned       256 MB memory, 64 MB interned strings, 100k files,
 *                         validate_timestamps=0
 *  4. jit-function        tuned + jit=function + 128 MB JIT buffer
 *  5. jit-tracing         tuned + jit=tracing  + 128 MB JIT buffer
 *  6. opcache-preload     tuned + preload all src/ files at startup
 *  7. multi-worker-4w     jit-tracing + 4 workers + SwooleBroker (ROUTE scope)
 *
 * Workloads
 * ─────────
 *  counter  trivial integer increment — framework/SSE overhead only
 *  cpu      Mandelbrot 50×50, max 100 iter/pixel — pure float arithmetic
 *  io       Co::sleep(0.002) per action — simulates 2 ms DB latency
 *
 * Cold vs warm
 * ────────────
 *  Cold: first hammer run after server starts (OPcache / JIT cache empty).
 *        jit=tracing is interpreting and profiling; shows the ramp-up cost.
 *  Warm: second hammer run immediately after (cache hot, JIT compiled).
 *        opcache-preload warm ≈ cold — that is the point of preloading.
 *
 * Expected findings
 * ─────────────────
 *  • no-opcache → opcache-default-cli: biggest single jump (CLI default is off)
 *  • jit-tracing cpu warm: highest throughput — JIT excels at tight float loops
 *  • io workload: near-zero JIT Δ across all profiles (bottleneck is Co::sleep)
 *  • preload cold ≈ warm: proves preload eliminates the cold-start penalty
 *  • multi-worker-4w: higher throughput than single-worker for cpu, but with
 *    SwooleBroker broadcast overhead visible in io/counter
 *
 * Usage
 * ─────
 *  php tests/Load/bench_opcache.php [options]
 *
 * Options
 *  --port=N          Port for bench_app (default: 3099)
 *  --actions=N       Actions per hammer pass (default: 5000)
 *  --concurrency=N   Parallel hammer coroutines (default: 200)
 *  --timeout=SEC     Per-request timeout for hammer (default: 5)
 *  --app=PATH        App script to benchmark. Use 'website' as a shorthand for
 *                    website/app.php (started with APP_ENV=dev). Default: bench_app.php
 *  --profile=NAME    Run only this profile (comma-separated list allowed)
 *                    Names: no-opcache, opcache-default-cli, opcache-tuned,
 *                           jit-function, jit-tracing, opcache-preload, multi-worker-4w
 *  --workload=NAME   Run only this workload (comma-separated list allowed)
 *                    Names: counter, cpu, io, spreadsheet (bench_app only)
 *                           spreadsheet-live (website app only)
 *  --help            Show this help
 *
 * Prerequisites
 * ─────────────
 *  - ext-openswoole in the PHP CLI binary (same binary used to run this script)
 *  - php-via website dependencies installed (composer install in root)
 *  - Nothing listening on --port before the script starts
 *  - For opcache-preload: write permission to /tmp for preload cache
 *
 * Notes
 * ─────
 *  Server stdout/stderr is suppressed (/dev/null) to keep output clean.
 *  Pass VIA_BENCH_DEBUG=1 to redirect server stderr to this process's stderr.
 */

if (!extension_loaded('openswoole')) {
    fwrite(STDERR, "Error: ext-openswoole is required in the CLI PHP binary.\n");

    exit(1);
}

// ── CLI argument parsing ──────────────────────────────────────────────────────

$opts = getopt('', ['port:', 'actions:', 'concurrency:', 'timeout:', 'app:', 'profile:', 'workload:', 'help']);

if (isset($opts['help'])) {
    echo file_get_contents(__FILE__);

    exit(0);
}

$benchPort   = (int) ($opts['port']        ?? 3099);
$hammActions = (int) ($opts['actions']     ?? 5000);
$hammConc    = (int) ($opts['concurrency'] ?? 200);
$hammTimeout = (int) ($opts['timeout']     ?? 5);
$debugServer = (bool) (getenv('VIA_BENCH_DEBUG') ?: false);

/** @var list<string>|null $filterProfiles */
$filterProfiles = isset($opts['profile'])
    ? array_map('trim', explode(',', (string) $opts['profile']))
    : null;

/** @var list<string>|null $filterWorkloads */
$filterWorkloads = isset($opts['workload'])
    ? array_map('trim', explode(',', (string) $opts['workload']))
    : null;

$phpBin   = PHP_BINARY;
$hammer   = __DIR__ . '/action_hammer.php';

// Resolve which app to benchmark.
$appArg = (string) ($opts['app'] ?? '');
if ($appArg === 'website') {
    $benchApp = (string) realpath(__DIR__ . '/../../website/app.php');
    $baseEnv  = ['APP_ENV' => 'dev']; // devMode=true → no Origin header required
} elseif ($appArg === '' || $appArg === 'bench') {
    $benchApp = __DIR__ . '/bench_app.php';
    $baseEnv  = [];
} else {
    $benchApp = (string) realpath($appArg);
    $baseEnv  = [];
}
$isWebsiteApp = str_ends_with($benchApp, 'website/app.php');

$preloadFile = (string) realpath(__DIR__ . '/bench_preload.php');

// ── Preload user detection ────────────────────────────────────────────────────
// opcache.preload_user is required unless the process runs as root.

$preloadUser = 'root';

if (function_exists('posix_getuid') && posix_getuid() !== 0) {
    $pwent       = posix_getpwuid(posix_getuid());
    $preloadUser = is_array($pwent) ? (string) $pwent['name'] : get_current_user();
}

// ── Profile definitions ───────────────────────────────────────────────────────
// Each entry:
//   name    Display name (used in table headers)
//   flags   Array of "key=value" strings, each passed as "-d key=value"
//   env     Extra environment variables for bench_app
//   workers Number of OpenSwoole workers (1 = single, >1 = SwooleBroker + ROUTE scope)

$profiles = [
    [
        'name'    => 'no-opcache',
        'flags'   => [
            'opcache.enable=0',
            'opcache.enable_cli=0',
        ],
        'env'     => [],
        'workers' => 1,
    ],
    [
        'name'    => 'opcache-default-cli',
        'flags'   => [
            'opcache.enable=1',
            'opcache.enable_cli=1',
        ],
        'env'     => [],
        'workers' => 1,
    ],
    [
        'name'    => 'opcache-tuned',
        'flags'   => [
            'opcache.enable=1',
            'opcache.enable_cli=1',
            'opcache.memory_consumption=256',
            'opcache.interned_strings_buffer=64',
            'opcache.max_accelerated_files=100000',
            'opcache.validate_timestamps=0',
        ],
        'env'     => [],
        'workers' => 1,
    ],
    [
        'name'    => 'jit-function',
        'flags'   => [
            'opcache.enable=1',
            'opcache.enable_cli=1',
            'opcache.memory_consumption=256',
            'opcache.interned_strings_buffer=64',
            'opcache.max_accelerated_files=100000',
            'opcache.validate_timestamps=0',
            'opcache.jit=function',
            'opcache.jit_buffer_size=128M',
        ],
        'env'     => [],
        'workers' => 1,
    ],
    [
        'name'    => 'jit-tracing',
        'flags'   => [
            'opcache.enable=1',
            'opcache.enable_cli=1',
            'opcache.memory_consumption=256',
            'opcache.interned_strings_buffer=64',
            'opcache.max_accelerated_files=100000',
            'opcache.validate_timestamps=0',
            'opcache.jit=tracing',
            'opcache.jit_buffer_size=128M',
        ],
        'env'     => [],
        'workers' => 1,
    ],
    [
        'name'    => 'opcache-preload',
        'flags'   => [
            'opcache.enable=1',
            'opcache.enable_cli=1',
            'opcache.memory_consumption=256',
            'opcache.interned_strings_buffer=64',
            'opcache.max_accelerated_files=100000',
            'opcache.validate_timestamps=0',
            'opcache.preload=' . $preloadFile,
            'opcache.preload_user=' . $preloadUser,
        ],
        'env'     => [],
        'workers' => 1,
    ],
    [
        'name'    => 'multi-worker-4w',
        'flags'   => [
            'opcache.enable=1',
            'opcache.enable_cli=1',
            'opcache.memory_consumption=256',
            'opcache.interned_strings_buffer=64',
            'opcache.max_accelerated_files=100000',
            'opcache.validate_timestamps=0',
            'opcache.jit=tracing',
            'opcache.jit_buffer_size=128M',
        ],
        // VIA_BENCH_WORKERS triggers SwooleBroker + ROUTE scope in bench_app
        'env'     => ['VIA_BENCH_WORKERS' => '4'],
        'workers' => 4,
    ],
];

// ── Workload definitions ──────────────────────────────────────────────────────

$workloads = [
    [
        'name'    => 'counter',
        'route'   => '/bench/counter',
        'label'   => 'Counter (trivial increment)',
    ],
    [
        'name'    => 'cpu',
        'route'   => '/bench/cpu',
        'label'   => 'CPU (Mandelbrot 50×50)',
    ],
    [
        'name'    => 'io',
        'route'   => '/bench/io',
        'label'   => 'IO (Co::sleep 2 ms)',
    ],
    [
        'name'    => 'spreadsheet',
        'route'   => '/bench/spreadsheet',
        'label'   => 'Spreadsheet (SQLite query + 20×10 viewport build)',
    ],
    [
        'name'    => 'spreadsheet-live',
        'route'   => '/examples/spreadsheet',
        'label'   => 'Spreadsheet live (full Twig + SQLite + virtual scroll)',
        'action'  => 'navigate',
        'signal'  => 'focusRow',
        'signals' => '{"key":"ArrowDown","shift":false}',
        'appOnly' => 'website', // skipped when using bench_app
    ],
    [
        'name'    => 'spreadsheet-raw-live',
        'route'   => '/examples/spreadsheet-raw',
        'label'   => 'Spreadsheet live — raw PHP SSE render (no Twig on hot path)',
        'action'  => 'navigate',
        'signal'  => 'focusRow',
        'signals' => '{"key":"ArrowDown","shift":false}',
        'appOnly' => 'website', // skipped when using bench_app
    ],
];

// ── Helper functions ──────────────────────────────────────────────────────────

/**
 * Start the bench_app server.
 *
 * @param list<string>         $flags   -d flag values ("key=value")
 * @param array<string,string> $extraEnv Extra env vars for the process
 * @param int                  $port    Listening port
 * @param bool                 $debug   Redirect server stderr to this process
 *
 * @return resource|false proc_open handle or false on failure
 */
function startServer(array $flags, array $extraEnv, array $baseEnv, int $port, bool $debug, string $phpBin, string $benchApp): mixed {
    $cmd = [$phpBin];

    foreach ($flags as $flag) {
        $cmd[] = '-d';
        $cmd[] = $flag;
    }

    $cmd[] = $benchApp;

    // Suppress server output unless VIA_BENCH_DEBUG=1
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $stderrTarget = $debug ? STDERR : ['file', $nullDevice, 'w'];

    $desc = [
        0 => ['file', $nullDevice, 'r'],
        1 => ['file', $nullDevice, 'w'],
        2 => $stderrTarget,
    ];

    // Merge current environment with profile-specific overrides and port
    $env = array_merge(
        getenv() ?: [],
        $baseEnv,
        $extraEnv,
        ['VIA_PORT' => (string) $port, 'VIA_DISABLE_HTTPS' => '1'],
    );

    return proc_open($cmd, $desc, $pipes, dirname($benchApp), $env);
}

/**
 * Wait until the server's /_health endpoint returns HTTP 200.
 *
 * @return bool true = ready, false = timed out
 */
function waitForReady(int $port, int $maxAttempts = 50, int $sleepUs = 200_000): bool {
    $url = "http://127.0.0.1:{$port}/_health";
    $ctx = stream_context_create(['http' => ['timeout' => 0.5, 'ignore_errors' => true]]);

    for ($i = 0; $i < $maxAttempts; ++$i) {
        $result = @file_get_contents($url, false, $ctx);

        if ($result !== false) {
            return true;
        }

        usleep($sleepUs);
    }

    return false;
}

/**
 * Warm up every bench route on the server by sending $count plain GET requests.
 *
 * For multi-worker profiles, ROUTE-scoped actions must be registered on each
 * worker before the hammer runs. Since fds are assigned sequentially and
 * dispatch is fd % worker_num, making worker_count * multiplier requests
 * statistically covers all workers.
 *
 * @param list<array{name:string,route:string,label:string}> $workloads
 */
function warmUpWorkers(int $port, int $count, array $workloads): void {
    $ctx = stream_context_create(['http' => ['timeout' => 2.0, 'ignore_errors' => true]]);

    foreach ($workloads as $wl) {
        $url = "http://127.0.0.1:{$port}{$wl['route']}";

        for ($i = 0; $i < $count; ++$i) {
            @file_get_contents($url, false, $ctx);
        }
    }
}

/**
 * Run action_hammer.php against one workload route and return the parsed metrics.
 *
 * @return array{throughput:int,http_ok_pct:float,patch_pct:float,wall_time:float,raw:string}
 */
function runHammer(
    int $port,
    string $route,
    string $action,
    string $signal,
    string $signals,
    int $actions,
    int $concurrency,
    int $timeoutSec,
    string $phpBin,
    string $hammerScript
): array {
    $cmd = [
        $phpBin,
        $hammerScript,
        "--url=http://127.0.0.1:{$port}",
        "--route={$route}",
        "--action={$action}",
        "--signal={$signal}",
        "--signals={$signals}",
        "--actions={$actions}",
        "--concurrency={$concurrency}",
        "--timeout={$timeoutSec}",
    ];

    $desc = [
        0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'],
    ];

    $proc = proc_open($cmd, $desc, $pipes);

    if ($proc === false) {
        return ['throughput' => 0, 'http_ok_pct' => 0.0, 'patch_pct' => 0.0, 'wall_time' => 0.0, 'raw' => ''];
    }

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($proc);

    $output = $output === false ? '' : $output;

    return array_merge(parseHammerOutput($output), ['raw' => $output]);
}

/**
 * Parse the summary block from action_hammer stdout.
 *
 * @return array{throughput:int,http_ok_pct:float,patch_pct:float,wall_time:float}
 */
function parseHammerOutput(string $output): array {
    $result = [
        'throughput'   => 0,
        'http_ok_pct'  => 0.0,
        'patch_pct'    => 0.0,
        'wall_time'    => 0.0,
    ];

    if (preg_match('/HTTP OK\s+:\s+\d+\s+\((\d+\.?\d*)%\)/', $output, $m)) {
        $result['http_ok_pct'] = (float) $m[1];
    }

    if (preg_match('/Patches observed\s+:\s+\d+\s+\((\d+\.?\d*)%/', $output, $m)) {
        $result['patch_pct'] = (float) $m[1];
    }

    if (preg_match('/Wall time\s+:\s+(\d+\.?\d*)s/', $output, $m)) {
        $result['wall_time'] = (float) $m[1];
    }

    if (preg_match('/Throughput\s+:\s+(\d+)\s+req\/s/', $output, $m)) {
        $result['throughput'] = (int) $m[1];
    }

    return $result;
}

/**
 * Gracefully stop a server process (SIGTERM, then SIGKILL after 5 s).
 *
 * @param resource $proc proc_open handle
 */
function stopServer(mixed $proc): void {
    proc_terminate($proc, SIGTERM);

    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $status = proc_get_status($proc);

        if (!$status['running']) {
            proc_close($proc);

            return;
        }

        usleep(100_000); // 100 ms
    }

    // Still running — force kill
    proc_terminate($proc, SIGKILL);
    proc_close($proc);
}

// ── Banner ────────────────────────────────────────────────────────────────────

echo "bench_opcache.php — php-via OPcache / JIT benchmark\n";
echo str_repeat('─', 60) . "\n";
printf("  PHP binary   : %s\n", $phpBin);
printf("  PHP version  : %s\n", PHP_VERSION);
printf("  App          : %s\n", basename($benchApp));
printf("  Port         : %d\n", $benchPort);
printf("  Actions/pass : %d\n", $hammActions);
printf("  Concurrency  : %d\n", $hammConc);
printf("  Profiles     : %d", count($profiles));
if ($filterProfiles !== null) {
    printf(' (filter: %s)', implode(', ', $filterProfiles));
}
echo "\n";
printf("  Workloads    : %d", count($workloads));
if ($filterWorkloads !== null) {
    printf(' (filter: %s)', implode(', ', $filterWorkloads));
}
echo "\n";
echo "\n";

// ── Main benchmark loop ───────────────────────────────────────────────────────

/**
 * results[profile_name][workload_name] = [
 *   'cold' => ['throughput' => int, 'http_ok_pct' => float, ...],
 *   'warm' => [...],
 * ]
 *
 * @var array<string,array<string,array{cold:array<string,mixed>,warm:array<string,mixed>}>> $results
 */
$results = [];

foreach ($profiles as $profile) {
    $pName   = $profile['name'];
    $workers = $profile['workers'];

    if ($filterProfiles !== null && !in_array($pName, $filterProfiles, true)) {
        continue;
    }

    echo "▶ Profile: {$pName}";

    if ($workers > 1) {
        echo " ({$workers} workers)";
    }

    echo "\n";

    // Build and start server
    $server = startServer(
        $profile['flags'],
        $profile['env'],
        $baseEnv,
        $benchPort,
        $debugServer,
        $phpBin,
        $benchApp
    );

    if ($server === false) {
        echo "  ERROR: proc_open failed — skipping profile\n\n";

        continue;
    }

    // Wait for readiness (max 10 s)
    echo "  Waiting for server to start ...";

    if (!waitForReady($benchPort)) {
        echo " TIMEOUT\n";
        stopServer($server);
        echo "  Skipping profile\n\n";

        continue;
    }

    echo " ready\n";

    // Warm up all workers so ROUTE-scoped actions are registered everywhere.
    // For single-worker this is a no-op cost (3 fast GETs).
    $warmUpCount = max(1, $workers * 4);
    warmUpWorkers($benchPort, $warmUpCount, $workloads);

    $results[$pName] = [];

    foreach ($workloads as $wl) {
        $wName  = $wl['name'];
        $route  = $wl['route'];
        $action = $wl['action']  ?? 'increment';
        $signal = $wl['signal']  ?? 'count';
        $signals = $wl['signals'] ?? '{}';

        if ($filterWorkloads !== null && !in_array($wName, $filterWorkloads, true)) {
            continue;
        }

        // Skip workloads that require the website app when not using it
        if (isset($wl['appOnly']) && $wl['appOnly'] === 'website' && !$isWebsiteApp) {
            continue;
        }

        echo "  {$wName}:";

        // ── Cold pass ────────────────────────────────────────────────────────
        echo " cold...";
        $cold = runHammer($benchPort, $route, $action, $signal, $signals, $hammActions, $hammConc, $hammTimeout, $phpBin, $hammer);
        printf(" %5d req/s (OK: %.1f%%)", $cold['throughput'], $cold['http_ok_pct']);

        if ($cold['http_ok_pct'] === 0.0) {
            echo "\n  SKIP: cold pass returned 0% HTTP OK — profile likely incompatible with this environment.\n";
            echo "        (OPcache preloading + OpenSwoole worker fork may SIGSEGV on WSL2/this host.)\n\n";
            stopServer($server);
            usleep(500_000);
            continue 2;
        }

        // ── Warm pass ────────────────────────────────────────────────────────
        echo "  warm...";
        $warm = runHammer($benchPort, $route, $action, $signal, $signals, $hammActions, $hammConc, $hammTimeout, $phpBin, $hammer);
        printf(" %5d req/s (OK: %.1f%%)\n", $warm['throughput'], $warm['http_ok_pct']);

        if ($warm['http_ok_pct'] === 0.0) {
            echo "\n  SKIP: warm pass returned 0% HTTP OK — server may have crashed after cold pass.\n\n";
            stopServer($server);
            usleep(500_000);
            continue 2;
        }

        $results[$pName][$wName] = ['cold' => $cold, 'warm' => $warm];
    }

    stopServer($server);

    // Brief pause to let the OS release the port before the next profile
    usleep(500_000);
    echo "\n";
}

// ── Comparison tables ─────────────────────────────────────────────────────────

/**
 * Format an integer throughput with thousands separator.
 */
function fmtReq(int $n): string {
    return number_format($n);
}

/**
 * Format a percentage delta compared to the baseline warm throughput.
 * Returns '—' for the baseline row, '+N.N%' or '-N.N%' otherwise.
 */
function fmtDelta(int $value, int $baseline): string {
    if ($baseline === 0) {
        return '  n/a';
    }

    $pct = ($value - $baseline) / $baseline * 100.0;

    if ($pct === 0.0) {
        return '  0.0%';
    }

    return sprintf('%+.1f%%', $pct);
}

$divider = str_repeat('─', 96);

echo "\n" . str_repeat('═', 96) . "\n";
echo " RESULTS — throughput in req/s  (cold = fresh OPcache,  warm = second pass,  Δ = warm vs no-opcache warm)\n";
echo str_repeat('═', 96) . "\n";

foreach ($workloads as $wl) {
    $wName = $wl['name'];
    $label = $wl['label'];

    echo "\n{$label}\n";
    echo $divider . "\n";
    printf(
        "%-22s | %9s | %9s | %8s | %9s | %9s | %8s | %6s\n",
        'Profile',
        'cold r/s',
        'warm r/s',
        'warm Δ',
        'cold OK%',
        'warm OK%',
        'cold≈warm',
        'Δ cold→warm'
    );
    echo $divider . "\n";

    // Baseline is no-opcache warm throughput for this workload
    $baseline = $results['no-opcache'][$wName]['warm']['throughput'] ?? 0;

    foreach ($profiles as $profile) {
        $pName = $profile['name'];

        if (!isset($results[$pName][$wName])) {
            printf("%-22s | %9s\n", $pName, 'SKIPPED');

            continue;
        }

        $cold = $results[$pName][$wName]['cold'];
        $warm = $results[$pName][$wName]['warm'];

        $warmDelta = $pName === 'no-opcache' ? '—' : fmtDelta($warm['throughput'], $baseline);

        // cold→warm Δ: did the JIT ramp up between passes?
        $coldToWarm = $cold['throughput'] > 0
            ? sprintf('%+.1f%%', ($warm['throughput'] - $cold['throughput']) / $cold['throughput'] * 100.0)
            : 'n/a';

        printf(
            "%-22s | %9s | %9s | %8s | %9.1f | %9.1f | %9s | %s\n",
            $pName,
            fmtReq($cold['throughput']),
            fmtReq($warm['throughput']),
            $warmDelta,
            $cold['http_ok_pct'],
            $warm['http_ok_pct'],
            $coldToWarm,
            '' // spacer
        );
    }

    echo $divider . "\n";
}

echo "\n";
echo "Tips:\n";
echo "  • 'cold≈warm' near 0% on preload row confirms preload eliminates cold-start.\n";
echo "  • Large 'cold→warm' on jit-tracing rows shows the JIT ramp-up window.\n";
echo "  • IO workload Δ near 0% across all profiles confirms the bottleneck is Co::sleep,\n";
echo "    not bytecode — the IO column is a sanity check, not a target for tuning.\n";
echo "  • Pass VIA_BENCH_DEBUG=1 to see bench_app server output for troubleshooting.\n";
echo "\n";
