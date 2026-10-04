<?php

declare(strict_types=1);

/*
 * Via::setInterval() must fire once per server, not once per worker.
 *
 * The intervals are registered inside the `workerStart` handler, so every worker armed its own
 * Timer::tick. The docblock promised "runs once per server process and is shared across all
 * connections"; with N workers it ran N times, N processes deep.
 *
 * Measured against the pre-fix code — real server, 4 workers, one 100ms interval over 2000ms:
 *
 *   fires=77  pids=4  expected=20
 *
 * Exact xN amplification, confirmed across 4 distinct pids. For a job that broadcasts, this is
 * also N^2 delivery: each of the N firings fans out to every worker.
 */

/** @return array{fires: int, pids: int, expected: int} */
function runIntervalServer(int $workers, bool $everyWorker = false, int $everyMs = 100, int $runMs = 2000): array {
    $fixture = dirname(__DIR__) . '/Fixtures/server_interval_workers.php';
    $tally = tempnam(sys_get_temp_dir(), 'via_interval_');

    try {
        @unlink($tally);
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
            . ' ' . $workers . ' ' . $everyMs . ' ' . $runMs
            . ' ' . escapeshellarg($tally) . ' ' . ($everyWorker ? '1' : '0')
            . ' > /dev/null 2>&1';
        shell_exec($cmd);

        $lines = array_filter(explode("\n", (string) @file_get_contents($tally)));

        return [
            'fires' => count($lines),
            'pids' => count(array_unique($lines)),
            'expected' => intdiv($runMs, $everyMs),
        ];
    } finally {
        @unlink($tally);
    }
}

test('a server interval fires in exactly one worker', function (): void {
    $r = runIntervalServer(workers: 4);

    expect($r['fires'])->toBeGreaterThan(0, 'the interval must actually fire');
    expect($r['pids'])->toBe(1, 'the interval must be armed by one worker only');
});

test('the firing count does not scale with worker count', function (): void {
    $r = runIntervalServer(workers: 4);

    // Worker start-up stagger and timer drift move the count a little; xN would not be subtle.
    expect($r['fires'])->toBeLessThanOrEqual(
        (int) ($r['expected'] * 1.5),
        "fired {$r['fires']} times against an expected {$r['expected']}"
    );
});

test('a single-worker server is unaffected', function (): void {
    $r = runIntervalServer(workers: 1);

    expect($r['pids'])->toBe(1);
    expect($r['fires'])->toBeGreaterThan(0);
    expect($r['fires'])->toBeLessThanOrEqual((int) ($r['expected'] * 1.5));
});

test('everyWorker: true opts back in to one timer per worker', function (): void {
    $r = runIntervalServer(workers: 4, everyWorker: true);

    expect($r['pids'])->toBe(4, 'the opt-in must arm every worker');
    expect($r['fires'])->toBeGreaterThan($r['expected'], 'N workers must produce roughly N times the firings');
});

test('onWorkerStart() and onWorkerStop() run in every worker and pass its id', function (): void {
    $fixture = dirname(__DIR__) . '/Fixtures/server_interval_workers.php';
    $tally = tempnam(sys_get_temp_dir(), 'via_interval_');
    $hookLog = tempnam(sys_get_temp_dir(), 'via_worker_hooks_');

    try {
        @unlink($hookLog);
        shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 3 100 600 '
            . escapeshellarg($tally) . ' 0 ' . escapeshellarg($hookLog) . ' > /dev/null 2>&1');
        $lines = array_filter(explode("\n", (string) @file_get_contents($hookLog)));
    } finally {
        @unlink($tally);
        @unlink($hookLog);
    }

    $starts = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'start ')));
    $stops = array_values(array_filter($lines, static fn (string $l): bool => str_starts_with($l, 'stop ')));
    sort($starts);
    sort($stops);

    expect($starts)->toBe(['start 0 0', 'start 1 1', 'start 2 2'])
        ->and($stops)->toBe(['stop 0 0', 'stop 1 1', 'stop 2 2'])
    ;
});
