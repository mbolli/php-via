<?php

declare(strict_types=1);
use OpenSwoole\Coroutine\Http\Client;

/*
 * getClients() must see every connected client, not just this worker's.
 *
 * Application::$clients was a plain array property, written when an SSE stream connects. With
 * N workers each one saw only the streams it happened to serve, so "N users online" showed a
 * random fraction — and sometimes zero. Measured with 6 SSE connections held open:
 *
 *   workers=1  6,6,6,6,6,6,6,6
 *   workers=4  2,1,2,2,2,1,1,1
 *   workers=8  1,1,1,1,1,1,0,0
 *
 * The identicon is NOT shared: it is a 1,550-byte SVG derived deterministically from the
 * client ID, so every worker can regenerate it and only the ID needs storing.
 */

/** @return array{connected: int, counts: list<int>, pids: int} */
function clientRegistryCounts(int $workers, int $connections = 6, int $probes = 8): array {
    $fixture = dirname(__DIR__) . '/Fixtures/client_registry_workers.php';
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
        . ' ' . $workers . ' ' . $connections . ' ' . $probes . ' 2>&1'
    );

    expect($out)->toMatch('/connected=\d+/', 'fixture output: ' . var_export($out, true));
    preg_match('/connected=(\d+)/', $out, $c);
    preg_match('/counts=([\d,]*)/', $out, $m);
    preg_match('/pids=(\d+)/', $out, $p);

    return [
        'connected' => (int) $c[1],
        'counts' => array_map('intval', array_filter(explode(',', $m[1] ?? ''), 'strlen')),
        'pids' => (int) ($p[1] ?? 0),
    ];
}

beforeEach(function (): void {
    if (!class_exists(Client::class)) {
        $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
    }
});

test('a single worker sees every client', function (): void {
    $r = clientRegistryCounts(workers: 1);

    expect($r['connected'])->toBe(6);
    expect($r['counts'])->not->toBeEmpty();
    expect(array_unique($r['counts']))->toBe([6]);
});

test('every worker sees every client, and none reports zero', function (): void {
    // Eight workers against six connections guarantees some worker served no SSE stream at
    // all — the case that used to report an empty user list.
    $r = clientRegistryCounts(workers: 8);

    expect($r['connected'])->toBe(6);
    expect($r['pids'])->toBeGreaterThan(1, 'the probes must actually reach more than one worker');
    expect(array_unique($r['counts']))->toBe(
        [6],
        'each worker reported: ' . implode(',', $r['counts'])
    );
});
