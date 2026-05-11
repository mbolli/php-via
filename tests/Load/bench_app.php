<?php

declare(strict_types=1);

/**
 * bench_app.php — Minimal php-via server for OPcache/JIT benchmarking.
 *
 * Exposes four routes with a common `increment` action and `count` signal,
 * each designed to stress a different part of the stack:
 *
 *   GET /bench/counter     — trivial integer increment (framework overhead baseline)
 *   GET /bench/cpu         — Mandelbrot set 50×50, max 100 iter/pixel (pure float arithmetic)
 *   GET /bench/io          — usleep(2_000) per action (simulates 2 ms DB latency)
 *   GET /bench/spreadsheet — SQLite range query + 20×10 viewport HTML build (mixed IO+CPU)
 *
 * In addition:
 *   GET /_health        — built-in Via readiness probe (used by orchestrator)
 *
 * Intentionally stripped of all website noise: no Twig template dir, no static
 * assets, no HTTPS, no CORS middleware, log level = error.
 *
 * Environment variables:
 *   VIA_PORT           Override listening port (default: 3099)
 *   VIA_BENCH_WORKERS  Worker count; >1 enables SwooleBroker + ROUTE scope (default: 1)
 *
 * Usage:
 *   php tests/Load/bench_app.php
 *   php -d opcache.enable_cli=1 -d opcache.jit=tracing tests/Load/bench_app.php
 *
 * Designed to be started/stopped by bench_opcache.php. Can also be run manually
 * for ad-hoc tests:
 *   php tests/Load/bench_app.php &
 *   php tests/Load/action_hammer.php --url=http://127.0.0.1:3099 \
 *       --route=/bench/cpu --action=increment --signal=count --signals='{}'
 */

require __DIR__ . '/../../vendor/autoload.php';

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

// ── Configuration ─────────────────────────────────────────────────────────────

$port    = (int) (getenv('VIA_PORT')    ?: 3099);
$workers = (int) (getenv('VIA_BENCH_WORKERS') ?: 1);

$config = (new Config())
    ->withHost('0.0.0.0')
    ->withPort($port)
    ->withLogLevel('error')
    ->withDevMode(true); // allows hammer's headerless tool requests (no Origin: header)

if ($workers > 1) {
    // ROUTE scope is used when multi-worker so that actions registered by
    // any worker can be reached by any other worker after warm-up.
    $config->withWorkerNum($workers)->withBroker(new SwooleBroker());
}

$app = new Via($config);

// When multi-worker, all routes run with ROUTE scope so that:
// 1. Actions get deterministic IDs (no per-context hash) and are reachable
//    from any worker after the first page load on each worker.
// 2. SwooleBroker broadcasts signal updates across workers, so the SSE
//    observer sees patches regardless of which worker processed the action.
$useRouteScope = $workers > 1;

// ── /bench/counter ────────────────────────────────────────────────────────────
// Trivial integer increment — measures raw framework + SSE overhead with zero
// application logic. Use as the baseline within a single OPcache profile.

$app->page('/bench/counter', function (Context $c) use ($useRouteScope): void {
    if ($useRouteScope) {
        $c->scope(Scope::ROUTE);
    }

    $c->signal(0, 'count');

    $action = $c->action(function (Context $ctx): void {
        $sig = $ctx->getSignal('count');
        $sig->setValue($sig->int() + 1);
        $ctx->syncSignals();
    }, 'increment');

    $url = $action->url();
    $c->view(fn () => "<button data-on:click=\"@post('{$url}')\">+1</button>");
});

// ── /bench/cpu ────────────────────────────────────────────────────────────────
// Mandelbrot set on a 50×50 grid, max 100 iterations per pixel.
// ~250k float multiply/add/compare ops per action call, no allocations, no IO.
//
// This is the canonical PHP JIT benchmark: tight inner loop over small floats.
// jit=tracing should compile the inner while-loop and show the largest Δ here.
// The stored value (sum of all iteration counts) is deterministic and identical
// for every action call — signal patches are still sent because setValue() marks
// the signal dirty regardless of the value changing.

$app->page('/bench/cpu', function (Context $c) use ($useRouteScope): void {
    if ($useRouteScope) {
        $c->scope(Scope::ROUTE);
    }

    $c->signal(0, 'count');

    $action = $c->action(function (Context $ctx): void {
        $sum = 0;

        for ($px = 0; $px < 50; $px++) {
            for ($py = 0; $py < 50; $py++) {
                $cx   = ($px / 50.0) * 3.5 - 2.5; // real axis: [-2.5, 1.0]
                $cy   = ($py / 50.0) * 2.0 - 1.0; // imaginary axis: [-1.0, 1.0]
                $zx   = $zy = 0.0;
                $iter = 0;

                while ($zx * $zx + $zy * $zy <= 4.0 && $iter < 100) {
                    $tmp = $zx * $zx - $zy * $zy + $cx;
                    $zy  = 2.0 * $zx * $zy + $cy;
                    $zx  = $tmp;
                    ++$iter;
                }

                $sum += $iter;
            }
        }

        $ctx->getSignal('count')->setValue($sum);
        $ctx->syncSignals();
    }, 'increment');

    $url = $action->url();
    $c->view(fn () => "<button data-on:click=\"@post('{$url}')\">mandelbrot</button>");
});

// ── /bench/io ─────────────────────────────────────────────────────────────────
// 2 ms coroutine sleep per action simulates a non-blocking DB round-trip.
// OPcache/JIT gains are expected to be negligible here — the bottleneck is
// coroutine scheduling latency, not bytecode interpretation. This workload
// provides the "null hypothesis" column: if JIT shows gains here, something
// else is causing the improvement.

$app->page('/bench/io', function (Context $c) use ($useRouteScope): void {
    if ($useRouteScope) {
        $c->scope(Scope::ROUTE);
    }

    $c->signal(0, 'count');

    $action = $c->action(function (Context $ctx): void {
        usleep(2_000); // 2 ms simulated IO — SWOOLE_HOOK_ALL makes this coroutine-safe
        $sig = $ctx->getSignal('count');
        $sig->setValue($sig->int() + 1);
        $ctx->syncSignals();
    }, 'increment');

    $url = $action->url();
    $c->view(fn () => "<button data-on:click=\"@post('{$url}')\">io+1</button>");
});

// ── /bench/spreadsheet ────────────────────────────────────────────────────────
// Replicates the SpreadsheetExample render path without the Twig layer:
//   1. SQLite range query for a 20×10 viewport (IO: real DB round-trip)
//   2. Build the viewport HTML — column letters, cell borders, focus styles (CPU)
//   3. syncSignals() — framework signal dispatch overhead
//
// This is the most representative real-world workload: every keystroke in the
// live spreadsheet triggers exactly this sequence. The SQLite file is shared
// with the website; if it doesn't exist a fresh in-memory DB is used instead.

function benchColName(int $col): string {
    $name = '';
    for ($c = $col + 1; $c > 0; $c = intdiv($c - 1, 26)) {
        $name = chr(65 + ($c - 1) % 26) . $name;
    }

    return $name;
}

function benchGetCellRange(\SQLite3 $db, int $startRow, int $startCol, int $rows, int $cols): array {
    $stmt = $db->prepare(
        'SELECT row, col, value FROM cells
         WHERE row >= :sr AND row < :er AND col >= :sc AND col < :ec'
    );
    $stmt->bindValue(':sr', $startRow, SQLITE3_INTEGER);
    $stmt->bindValue(':er', $startRow + $rows, SQLITE3_INTEGER);
    $stmt->bindValue(':sc', $startCol, SQLITE3_INTEGER);
    $stmt->bindValue(':ec', $startCol + $cols, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $cells  = [];

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $cells[$row['row'] . ':' . $row['col']] = (string) $row['value'];
    }

    return $cells;
}

$dbPath  = file_exists(__DIR__ . '/../../website/spreadsheet.db')
    ? __DIR__ . '/../../website/spreadsheet.db'
    : ':memory:';
$benchDb = new \SQLite3($dbPath);
$benchDb->exec('PRAGMA journal_mode=WAL');
$benchDb->exec('PRAGMA synchronous=NORMAL');
$benchDb->exec(
    'CREATE TABLE IF NOT EXISTS cells (
        row INTEGER NOT NULL, col INTEGER NOT NULL, value TEXT NOT NULL DEFAULT \'\',
        PRIMARY KEY (row, col)
    )'
);

$app->page('/bench/spreadsheet', function (Context $c) use ($useRouteScope, $benchDb): void {
    if ($useRouteScope) {
        $c->scope(Scope::ROUTE);
    }

    $c->signal(0, 'count');
    $c->signal(0, 'viewRow', Scope::TAB);
    $c->signal(0, 'viewCol', Scope::TAB);
    $c->signal(0, 'focusRow', Scope::TAB);
    $c->signal(0, 'focusCol', Scope::TAB);

    $action = $c->action(function (Context $ctx) use ($benchDb): void {
        // Step 1 — IO: SQLite range fetch for the current 20×10 viewport
        $vr = $ctx->getSignal('viewRow')->int();
        $vc = $ctx->getSignal('viewCol')->int();
        $cells = benchGetCellRange($benchDb, $vr, $vc, 20, 10);

        // Step 2 — CPU: build the viewport HTML (same logic as SpreadsheetExample)
        $fr = $ctx->getSignal('focusRow')->int();
        $fc = $ctx->getSignal('focusCol')->int();
        $html = '<table>';
        for ($r = 0; $r < 20; ++$r) {
            $html .= '<tr>';
            for ($col = 0; $col < 10; ++$col) {
                $absR = $vr + $r;
                $absC = $vc + $col;
                $val  = htmlspecialchars($cells[$absR . ':' . $absC] ?? '');
                $focused = ($absR === $fr && $absC === $fc) ? ' style="outline:2px solid #4f8ef7"' : '';
                $html .= "<td{$focused}>{$val}</td>";
            }
            $html .= '</tr>';
        }
        $html .= '</table>';

        // Step 3 — advance viewport (simulate navigation), increment counter
        $sig = $ctx->getSignal('count');
        $sig->setValue($sig->int() + 1);

        $newVr = ($vr + 1) % 100; // slowly scroll through first 100 rows
        $ctx->getSignal('viewRow')->setValue($newVr, broadcast: false);
        $ctx->getSignal('focusRow')->setValue($newVr, broadcast: false);
        $ctx->syncSignals();
    }, 'increment');

    $url = $action->url();
    $c->view(fn () => "<div id=\"bench-sheet\"><button data-on:click=\"@post('{$url}')\">sheet+1</button></div>");
});

$app->start();
