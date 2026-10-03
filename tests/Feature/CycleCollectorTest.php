<?php

declare(strict_types=1);

/*
 * By default PHP runs its cycle collector itself. With withGcIntervalMs($ms, onGrowth: true) a worker runs it when its
 * memory grows, with PHP's own runs off: 48 MiB held by a few hundred cycles are freed within a check, where PHP
 * waits for 10,000 possible roots.
 */

/** @return array<string, string> key => value from the fixture's key=value lines */
function cycleCollectorServer(int $intervalMs, bool $onGrowth = false): array {
    $out = (string) shell_exec('timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/cycle_collector_server.php') . ' ' . $intervalMs . ($onGrowth ? ' growth' : '') . ' 2>&1');
    preg_match_all('/^([a-z_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);
    $values = ['out' => $out];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }
    expect($values)->toHaveKey('client_done', '1', 'fixture output: ' . $out);

    return $values;
}

beforeEach(function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
        $this->markTestSkipped('ext-pcntl and ext-posix required');
    }
});

test('with onGrowth a worker turns PHP\'s collector off and frees cycles once its memory grows', function (): void {
    $r = cycleCollectorServer(30_000, onGrowth: true);

    expect($r['enabled'])->toBe('0', $r['out'])
        ->and((int) $r['runs_since'])->toBeGreaterThanOrEqual(1, $r['out'])
        ->and((int) $r['freed_mib'])->toBeGreaterThanOrEqual(40, $r['out'])
    ;
});

test('by default PHP keeps running its collector', function (): void {
    $r = cycleCollectorServer(30_000);

    expect($r['enabled'])->toBe('1', $r['out'])
        ->and($r['runs_since'])->toBe('0', $r['out'])
    ;
});
