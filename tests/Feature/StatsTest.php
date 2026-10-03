<?php

declare(strict_types=1);

use Mbolli\PhpVia\Support\Stats;

/*
 * Via::getStats()->getAll() read 0 for requests, actions, SSE connections and the live counts, because nothing called
 * their trackers. Every field now moves with its traffic. Requests, actions and SSE connections count for the whole
 * server, so every worker reads the same figures; the rest describe the worker that answers.
 */

/** @return array<string, string> the key=value lines of a fixture run, and its output under 'out' */
function statsFixture(int $workers): array {
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/stats_workers.php') . ' ' . $workers . ' 2>&1');
    preg_match_all('/^([a-z_]+)=(\S*)$/m', $out, $m);

    return array_combine($m[1], $m[2]) + ['out' => $out];
}

test('every getAll() field moves with its traffic, and the server-wide counters are the same on every worker', function (int $workers): void {
    $r = statsFixture($workers);

    $fields = array_keys((new Stats())->getAll());
    foreach ($fields as $field) {
        expect((float) ($r['a_' . $field] ?? 0))->toBeGreaterThan(0, "{$field} stayed 0\n" . $r['out']);
    }
    expect($r)->toMatchArray([
        'other_worker' => $workers > 1 ? '1' : '0',
        'static' => '200',
        'bump' => '200',
        'grew_requests' => '4',
        'grew_actions' => '1',
        'grew_sse_connections' => '1',
        'shared_requests' => '1',
        'shared_actions' => '1',
        'shared_sse_connections' => '1',
    ], $r['out']);
})->with([1, 2]);

test('requests average their duration in milliseconds, and the live counts come from the closure', function (): void {
    $stats = new Stats(static fn (): array => ['active_sse' => 3, 'active_contexts' => 5]);
    $stats->trackRequest(2.0);
    $stats->trackRequest(4.0);
    $stats->trackAction();
    $stats->trackSseConnection();

    expect($stats->getAll())->toMatchArray([
        'requests' => 2,
        'avg_request_time' => 3.0,
        'actions' => 1,
        'sse_connections' => 1,
        'active_sse' => 3,
        'active_contexts' => 5,
    ]);
});

test('shared counters keep what was counted before and reset with the rest', function (): void {
    $stats = new Stats();
    $stats->trackRequest(1.0);
    $stats->share();
    $stats->trackRequest(3.0);
    $stats->trackAction();
    $counted = $stats->getAll();
    $stats->reset();

    expect($counted)->toMatchArray(['requests' => 2, 'avg_request_time' => 2.0, 'actions' => 1])
        ->and($stats->getAll())->toMatchArray(['requests' => 0, 'avg_request_time' => 0.0, 'actions' => 0])
    ;
});
