<?php

declare(strict_types=1);

/*
 * Category table across scenarios, as Markdown, from the NAME.shares.json files fold.php wrote.
 *
 *   php table.php DIR NAME...
 */

$dir = $argv[1] ?? '';
$names = array_slice($argv, 2);
$labels = [
    'highlighting' => 'Syntax highlighting (tempest/highlight)',
    'templating' => 'Twig',
    'app' => 'Website code',
    'compression' => 'Brotli',
    'sse_format' => 'Datastar SDK (event text)',
    'db' => 'SQLite',
    'json' => 'JSON',
    'socket_write' => 'Socket writes (Response)',
    'swoole_calls' => 'OpenSwoole calls (channels, coroutines)',
    'gc' => 'Cycle GC',
    'fw_broadcast' => 'via: broadcast',
    'fw_sse' => 'via: SSE loop',
    'fw_patch' => 'via: patches',
    'fw_render' => 'via: render',
    'fw_state' => 'via: signals and state',
    'fw_http' => 'via: HTTP, middleware, PSR-7',
    'fw_devbar' => 'via: Dev Bar tracing',
    'fw_other' => 'via: other',
    'loop' => 'Event loop (no PHP frame)',
    'other' => 'Other',
];
$data = [];
foreach ($names as $n) {
    $data[$n] = json_decode((string) file_get_contents("{$dir}/{$n}.shares.json"), true);
}

$fmt = static fn (float $v): string => $v < 0.05 ? '0' : number_format($v, 1);
echo '| Category | ' . implode(' | ', $names) . " |\n";
echo '|---' . str_repeat('|---:', count($names)) . "|\n";
$rest = array_fill_keys($names, 0.0);
foreach ($labels as $cat => $label) {
    $row = [];
    $max = 0.0;
    foreach ($names as $n) {
        $v = ($data[$n]['shares'][$cat] ?? 0) / max(1, $data[$n]['samples']) * 100;
        $row[] = $v;
        $max = max($max, $v);
    }
    if ($max < 1.0) {
        foreach ($names as $i => $n) {
            $rest[$n] += $row[$i];
        }

        continue;
    }
    echo "| {$label} | " . implode(' | ', array_map($fmt, $row)) . " |\n";
}
echo '| Rest (each under 1% everywhere) | ' . implode(' | ', array_map($fmt, $rest)) . " |\n";
echo '| **Samples (ms of worker CPU)** | ' . implode(' | ', array_map(static fn ($n) => number_format($data[$n]['samples']), $names)) . " |\n";
echo '| Worker CPU per operation | ' . implode(' | ', array_map(static function ($n) use ($data): string {
    $ops = (int) ($data[$n]['meta']['ops'] ?? 0);

    return $ops ? sprintf('%.2f ms/%s', $data[$n]['samples'] / $ops, $data[$n]['meta']['opname'] ?? 'op') : '-';
}, $names)) . " |\n";
echo '| Master process (reactor threads), share of server CPU | ' . implode(' | ', array_map(static function ($n) use ($data): string {
    $m = $data[$n]['meta'];

    return $m['cpu_s'] > 0 ? sprintf('%.0f%%', ($m['cpu_by_proc']['master'] ?? 0) / $m['cpu_s'] * 100) : '-';
}, $names)) . " |\n";

echo "\n| Phase (inclusive) | " . implode(' | ', $names) . " |\n";
echo '|---' . str_repeat('|---:', count($names)) . "|\n";
$phases = [];
foreach ($data as $d) {
    $phases += array_fill_keys(array_keys($d['phases']), true);
}
foreach (['page request', 'SSE connect', 'action request', 'broadcast fan-out', 'SSE stream loop', 'timers and cleanup', 'other request', 'other'] as $ph) {
    if (!isset($phases[$ph])) {
        continue;
    }
    echo "| {$ph} | " . implode(' | ', array_map(static fn ($n) => $fmt(($data[$n]['phases'][$ph] ?? 0) / max(1, $data[$n]['samples']) * 100), $names)) . " |\n";
}
