#!/usr/bin/env php
<?php

/**
 * profile_spreadsheet.php — SPX profiler harness for SpreadsheetExample navigate action.
 *
 * Starts website/app.php on VIA_PORT (default 3099) with php-spx loaded,
 * fires N navigate (ArrowDown) actions via action_hammer.php, then reports the
 * SPX report key so you can open it in the web UI.
 *
 * Usage:
 *   php tests/Load/profile_spreadsheet.php [--actions=N] [--spx-so=PATH]
 *
 * Output:
 *   SPX reports land in /tmp/spx-data/
 *   Open http://127.0.0.1:3099/?SPX_KEY=dev&SPX_UI_URI=/ in a browser
 *   (while the website app is running on port 3099)
 *
 * Requirements:
 *   php-spx built at /tmp/spx.so  (see README or run build_spx.sh)
 *   zlib1g-dev, php8.4-dev  — already installed on this host
 */

declare(strict_types=1);

$opts = getopt('', ['actions:', 'spx-so:', 'port:', 'help']);
$actions = (int) ($opts['actions'] ?? 30);
$spxSo = (string) ($opts['spx-so'] ?? '/tmp/spx.so');
$port = (int) ($opts['port'] ?? 3099);
$phpBin = PHP_BINARY;
$websiteApp = (string) realpath(__DIR__ . '/../../website/app.php');
$hammer = __DIR__ . '/action_hammer.php';
$spxData = '/tmp/spx-data';
$spxAssets = '/tmp/spx-assets/web-ui';

if (isset($opts['help'])) {
    echo <<<'HELP'
    profile_spreadsheet.php — SPX profile of SpreadsheetExample navigate action

    Options:
      --actions=N    Actions to fire (default: 30)
      --spx-so=PATH  Path to spx.so (default: /tmp/spx.so)
      --port=N       Port for website app (default: 3099)
      --help         Show this help

    Requirements:
      /tmp/spx.so        — build with: cd /tmp/php-spx && make
      /tmp/spx-assets/   — copy from php-spx/assets/web-ui
      /tmp/spx-data/     — created automatically

    HELP;

    exit(0);
}

// ─── Validate prerequisites ───────────────────────────────────────────────────

if (!file_exists($spxSo)) {
    fwrite(STDERR, "ERROR: spx.so not found at {$spxSo}\n");
    fwrite(STDERR, "Build it with:\n  cd /tmp && git clone https://github.com/NoiseByNorthwest/php-spx.git\n");
    fwrite(STDERR, "  cd php-spx && git checkout release/latest && phpize8.4 && ./configure --with-php-config=php-config8.4 && make\n");
    fwrite(STDERR, "  cp modules/spx.so /tmp/spx.so && cp -r assets/web-ui /tmp/spx-assets/\n");

    exit(1);
}

if (!file_exists($spxAssets . '/index.html')) {
    fwrite(STDERR, "ERROR: SPX web UI assets not found at {$spxAssets}\n");
    fwrite(STDERR, "Copy them with: cp -r /tmp/php-spx/assets/web-ui /tmp/spx-assets/\n");

    exit(1);
}

if (!is_dir($spxData)) {
    mkdir($spxData, 0755, true);
}

// ─── Helper: wait for port ────────────────────────────────────────────────────

function waitForReady(int $port, int $maxSeconds = 15): bool {
    $url = "http://127.0.0.1:{$port}/_health";
    $ctx = stream_context_create(['http' => ['timeout' => 1.0, 'ignore_errors' => true]]);
    $deadline = time() + $maxSeconds;

    while (time() < $deadline) {
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false && str_contains($body, '"status":"ok"')) {
            return true;
        }
        usleep(200_000);
    }

    return false;
}

// ─── Start website app with SPX loaded ───────────────────────────────────────

echo "Starting website/app.php with SPX on port {$port} ...\n";

$serverEnv = array_merge(getenv() ?: [], [
    'VIA_PORT' => (string) $port,
    'VIA_DISABLE_HTTPS' => '1',
    'APP_ENV' => 'dev',
    // SPX env for CLI (will be inherited by the server process)
    // Auto-start disabled — we control profiling via spx_profiler_start/stop
    'SPX_AUTO_START' => '0',
    'SPX_ENABLED' => '1',
    'SPX_REPORT' => 'full',
    'SPX_BUILTINS' => '1',  // Profile internal functions too (reveals SQLite, Twig internals)
    'SPX_METRICS' => 'wt,ct,zm', // Wall time, CPU time, ZE memory
]);

$serverCmd = [
    $phpBin,
    '-d', "extension={$spxSo}",
    '-d', 'spx.http_enabled=1',
    '-d', 'spx.http_key=dev',
    '-d', 'spx.http_ip_whitelist=127.0.0.1',
    '-d', "spx.data_dir={$spxData}",
    '-d', "spx.http_ui_assets_dir={$spxAssets}",
    '-d', 'zlib.output_compression=0',
    $websiteApp,
];

$desc = [
    0 => ['file', '/dev/null', 'r'],
    1 => ['file', '/tmp/spx-server.log', 'w'],
    2 => ['file', '/tmp/spx-server.log', 'a'],
];
$serverProc = proc_open($serverCmd, $desc, $pipes, null, $serverEnv);

if ($serverProc === false) {
    fwrite(STDERR, "ERROR: proc_open failed\n");

    exit(1);
}

echo "  Waiting for server ...\n";
if (!waitForReady($port)) {
    fwrite(STDERR, "TIMEOUT: server did not start within 15 s\n");
    fwrite(STDERR, "Check /tmp/spx-server.log for errors\n");
    proc_terminate($serverProc);

    exit(1);
}
echo "  Server ready.\n\n";

// ─── Run the hammer ───────────────────────────────────────────────────────────
// The hammer loads the page (which registers the context on the server),
// then fires N navigate actions. SPX will profile the action handler on
// the server side because spx_profiler_start/stop are called per-action
// (see the instrumentation in SpreadsheetExample or via ActionHandler).
//
// NOTE: SPX in "http" mode won't auto-profile OpenSwoole requests.
// Instead we instrument the action closure directly with spx_profiler_start/stop.
// The reports land in $spxData and are viewable via the embedded web UI.

echo "Firing {$actions} navigate (ArrowDown) actions against /examples/spreadsheet ...\n";

$hammerCmd = [
    $phpBin,
    $hammer,
    "--url=http://127.0.0.1:{$port}",
    '--route=/examples/spreadsheet',
    '--action=navigate',
    '--signal=focusRow',
    '--signals={"key":"ArrowDown","shift":false}',
    "--actions={$actions}",
    '--concurrency=1',   // Serial — cleaner profile, one action at a time
    '--timeout=10',
];

$hammerResult = '';
$hp = proc_open($hammerCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $hp_pipes);
if ($hp) {
    fclose($hp_pipes[0]);
    $hammerResult = stream_get_contents($hp_pipes[1]);
    fclose($hp_pipes[1]);
    fclose($hp_pipes[2]);
    proc_wait($hp);
}

$httpOk = 'unknown';
if (preg_match('/HTTP OK\s*:\s*(\d+)\s*\(([0-9.]+%)\)/', $hammerResult, $m)) {
    $httpOk = "{$m[1]} ({$m[2]})";
}
$throughput = 'unknown';
if (preg_match('/Throughput\s*:\s*(\d+ req\/s)/', $hammerResult, $m)) {
    $throughput = $m[1];
}

echo "  HTTP OK: {$httpOk}  Throughput: {$throughput}\n\n";

// ─── Stop server ──────────────────────────────────────────────────────────────

proc_terminate($serverProc);
usleep(300_000);
proc_close($serverProc);

// ─── List generated reports ───────────────────────────────────────────────────

$reports = glob("{$spxData}/spx-full-*.json.zz") ?: [];
rsort($reports); // newest first

echo str_repeat('─', 60) . "\n";
echo "SPX reports in {$spxData}:\n\n";

if ($reports === []) {
    echo "  (none found — server may not have had SPX instrumentation active)\n";
    echo "\n  To instrument the navigate action, add to SpreadsheetExample.php\n";
    echo "  at the start of the navigate closure:\n";
    echo "    spx_profiler_start();\n";
    echo "  and at the end (before the closing }):\n";
    echo "    spx_profiler_stop();\n";
} else {
    foreach (array_slice($reports, 0, 5) as $r) {
        $key = basename($r, '.json.zz');
        $key = str_replace('spx-full-', '', $key);
        printf("  key: %-40s  size: %s\n", $key, human_readable_size(filesize($r)));
    }

    echo "\n";
    echo str_repeat('─', 60) . "\n";
    echo "To view: start the website with SPX and open:\n";
    echo "  http://127.0.0.1:{$port}/?SPX_KEY=dev&SPX_UI_URI=/\n";
    echo "\nStart command:\n";
    echo "  VIA_PORT={$port} VIA_DISABLE_HTTPS=1 APP_ENV=dev php \\\n";
    echo "    -d extension={$spxSo} \\\n";
    echo "    -d spx.http_enabled=1 -d spx.http_key=dev \\\n";
    echo "    -d \"spx.http_ip_whitelist=127.0.0.1\" \\\n";
    echo "    -d spx.data_dir={$spxData} \\\n";
    echo "    -d \"spx.http_ui_assets_dir={$spxAssets}\" \\\n";
    echo "    -d zlib.output_compression=0 \\\n";
    echo "    website/app.php\n";
}

echo "\n";

// ─── CLI flat profile via SPX (no server needed) ─────────────────────────────
// Run a single navigate action as a CLI script so SPX flat profile can be
// printed to STDERR. This is the quickest way to see the hot functions.

echo str_repeat('─', 60) . "\n";
echo "Running standalone flat profile (CLI, no server) ...\n\n";

$profileScript = createInlineProfileScript($port, $spxData);
$profileCmd = [
    $phpBin,
    '-d', "extension={$spxSo}",
    '-d', "spx.data_dir={$spxData}",
    '-d', "spx.http_ui_assets_dir={$spxAssets}",
    '-d', 'zlib.output_compression=0',
    '-r', $profileScript,
];

$profEnv = array_merge(getenv() ?: [], [
    'SPX_ENABLED' => '1',
    'SPX_AUTO_START' => '1',
    'SPX_REPORT' => 'fp',
    'SPX_BUILTINS' => '1',
    'SPX_METRICS' => 'wt,ct,zm',
    'SPX_FP_LIMIT' => '20',
    'SPX_FP_COLOR' => '1',
]);

$pp = proc_open($profileCmd, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDOUT], $pp_pipes, __DIR__ . '/../../website', $profEnv);
if ($pp) {
    fclose($pp_pipes[0]);
    proc_wait($pp);
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function human_readable_size(int $bytes): string {
    if ($bytes < 1024) {
        return "{$bytes} B";
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return round($bytes / (1024 * 1024), 2) . ' MB';
}

function createInlineProfileScript(int $port, string $spxData): string {
    // Inline PHP script run under SPX CLI mode.
    // Boots a minimal simulation of the navigate action:
    //  - loads Composer + website autoloader
    //  - boots Twig (same environment as website/app.php)
    //  - opens the real spreadsheet.db
    //  - calls the relevant methods directly
    return <<<'PHP'
    // Minimal boot for profiling
    require __DIR__ . '/vendor/autoload.php';

    use Twig\Environment;
    use Twig\Loader\FilesystemLoader;
    use Twig\Extra\Html\HtmlExtension;

    // Boot Twig exactly as the website app does
    $loader = new FilesystemLoader(__DIR__ . '/templates');
    $twig = new Environment($loader, ['cache' => false, 'auto_reload' => true]);

    // Open the real DB (or memory)
    $dbPath = __DIR__ . '/spreadsheet.db';
    $db = new \SQLite3(file_exists($dbPath) ? $dbPath : ':memory:');
    if (!file_exists($dbPath)) {
        $db->exec('CREATE TABLE cells (row INTEGER, col INTEGER, value TEXT, PRIMARY KEY (row, col))');
    }

    // Simulate getViewport: query 20×10 window at row=0, col=0
    $vr = 0; $vc = 0; $vrows = 20; $vcols = 10;
    $cells = [];
    $stmt = $db->prepare('SELECT row, col, value FROM cells WHERE row >= :r1 AND row < :r2 AND col >= :c1 AND col < :c2');
    $stmt->bindValue(':r1', $vr,           SQLITE3_INTEGER);
    $stmt->bindValue(':r2', $vr + $vrows,  SQLITE3_INTEGER);
    $stmt->bindValue(':c1', $vc,           SQLITE3_INTEGER);
    $stmt->bindValue(':c2', $vc + $vcols,  SQLITE3_INTEGER);
    $res = $stmt->execute();
    $cellMap = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $cellMap[$row['row']][$row['col']] = $row['value'];
    }

    // Build same data structure that the template receives
    $rows = [];
    for ($r = $vr; $r < $vr + $vrows; ++$r) {
        $cols = [];
        for ($c = $vc; $c < $vc + $vcols; ++$c) {
            $cols[] = ['value' => $cellMap[$r][$c] ?? '', 'row' => $r, 'col' => $c];
        }
        $rows[] = ['cols' => $cols, 'row' => $r];
    }

    // Render the spreadsheet block (same Twig template, minimal data)
    $templateData = [
        'rows'         => $rows,
        'vr'           => $vr,
        'vc'           => $vc,
        'viewportRows' => $vrows,
        'viewportCols' => $vcols,
        'focusRow'     => 1,
        'focusCol'     => 0,
        'maxRow'       => 1000,
        'maxCol'       => 52,
        'cursors'      => [],
        'selections'   => [],
        'version'      => 1,
        'editing'      => false,
        'editValue'    => '',
        'navigateUrl'  => '/_action/navigate',
        'scrollToUrl'  => '/_action/scrollTo',
        'scrollToRowId' => 'str',
        'scrollToColId' => 'stc',
    ];

    // Render 10 times to simulate warm profiling
    for ($i = 0; $i < 10; ++$i) {
        $templateData['focusRow'] = $i;
        $html = $twig->render('examples/spreadsheet.html.twig', $templateData);
    }
    echo "Rendered " . strlen($html) . " bytes of HTML\n";
    PHP;
}
