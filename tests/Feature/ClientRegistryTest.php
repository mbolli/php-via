<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\State\SharedClientRegistry;
use Mbolli\PhpVia\Support\LogBuffer;
use Mbolli\PhpVia\Via;
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

/** @return list<int> */
function countList(string $csv): array {
    return array_map('intval', array_filter(explode(',', $csv), 'strlen'));
}

/** @return array{connected: int, counts: list<int>, pids: int, after: list<int>, afterPids: int} */
function clientRegistryCounts(int $workers, int $connections = 6, int $probes = 8, int $close = 0): array {
    $fixture = dirname(__DIR__) . '/Fixtures/client_registry_workers.php';
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
        . ' ' . $workers . ' ' . $connections . ' ' . $probes . ' ' . $close . ' 2>&1'
    );

    expect($out)->toMatch('/connected=\d+/', 'fixture output: ' . var_export($out, true));
    preg_match('/connected=(\d+)/', $out, $c);
    preg_match('/counts=([\d,]*)/', $out, $m);
    preg_match('/\bpids=(\d+)/', $out, $p);
    preg_match('/after=([\d,]*)/', $out, $a);
    preg_match('/afterpids=(\d+)/', $out, $ap);

    return [
        'connected' => (int) $c[1],
        'counts' => countList($m[1] ?? ''),
        'pids' => (int) ($p[1] ?? 0),
        'after' => countList($a[1] ?? ''),
        'afterPids' => (int) ($ap[1] ?? 0),
    ];
}

/** @return array<string, string> key => value from the fixture's key=value lines */
function fixtureValues(string $fixture, string ...$args): array {
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/' . $fixture)
        . ' ' . implode(' ', array_map(escapeshellarg(...), $args)) . ' 2>&1'
    );
    preg_match_all('/\b([a-z_]+)=(\S*)/', $out, $m, PREG_SET_ORDER);

    $values = [];
    foreach ($m as [, $key, $value]) {
        $values[$key] ??= $value;
    }

    expect($values)->not->toBe([], 'fixture output: ' . var_export($out, true));

    return $values;
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

test('every worker sees clients leave, whichever worker served them', function (): void {
    // Every probed worker built its list in the first round, so a list cached past the
    // disconnects shows up as a stale count in the second.
    $r = clientRegistryCounts(workers: 4, connections: 6, probes: 12, close: 2);

    expect($r['connected'])->toBe(6);
    expect(array_unique($r['counts']))->toBe([6]);
    expect($r['afterPids'])->toBeGreaterThan(1, 'the probes must actually reach more than one worker');
    expect(array_unique($r['after']))->toBe([4], 'each worker reported: ' . implode(',', $r['after']));
});

test('a killed worker\'s clients leave the list once it restarts', function (): void {
    // Before, they stayed up to 120 s, until the rows expired.
    $r = fixtureValues('client_registry_crash.php', '6');

    $connected = (int) $r['connected'];
    $killed = (int) $r['killed'];
    expect($connected)->toBe(6);
    expect($killed)->toBeGreaterThan(0, 'the other worker must hold a stream to kill');
    expect(array_unique(countList($r['before'])))->toBe([$connected]);
    expect(array_unique(countList($r['after'])))->toBe([$connected - $killed]);
    expect((int) $r['elapsed_ms'])->toBeGreaterThanOrEqual(0)->toBeLessThan(2000);
});

test('a client whose stream never goes idle stays in the list', function (): void {
    // 0.13.0 deleted a row 120 s after its last idle heartbeat, on the next read, for good.
    $r = fixtureValues('client_registry_clock.php');

    expect($r)->toMatchArray(['before' => '1', 'after' => '1', 'again' => '1', 'rows' => '1']);
});

test('a full registry logs which setting to raise, and the client still connects', function (): void {
    $via = new Via((new Config())->withLogLevel('warn'));
    $registry = new SharedClientRegistry(1);
    $via->getApp()->setClientRegistry($registry);
    for ($filled = 0; $filled < 100_000 && $registry->register("fill-{$filled}", "client-{$filled}", '10.0.0.1', 1000); ++$filled) {
        // Fill the table.
    }
    $logs = new LogBuffer();
    $via->getApp()->getLogger()->setBuffer($logs);

    ob_start();

    try {
        $via->getApp()->registerClient('ctx-over', ['id' => 'client-over', 'identicon' => '', 'connected_at' => 1000, 'ip' => '10.0.0.2']);
    } finally {
        ob_end_clean();
    }

    $warnings = array_values(array_filter($logs->since(0), static fn (array $r): bool => $r['level'] === 'warn'));
    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['message'])->toContain('ctx-over')->toContain('Config::withContextDirectorySize()');
    expect($via->getClients())->not->toHaveKey('ctx-over');
});
