<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Support\Stats;
use OpenSwoole\Http\Request;
use Tests\Support\FakeStaticResponse;

/*
 * The Dev Bar's Stats panel reads /_via/stats: every getAll() figure, the broadcast tick and the runtime figures of
 * the dev-mode /_stats. It is gated like the other Dev Bar endpoints, so it answers outside dev mode when the Dev Bar
 * is on, and only to GET.
 */

test('a server with the Dev Bar on outside dev mode answers /_via/stats with every figure', function (): void {
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/devbar_stats_server.php') . ' 2>&1');
    preg_match_all('/^([a-z_]+)=(\S*)$/m', $out, $m);
    $r = array_combine($m[1], $m[2]);

    expect($r)->toMatchArray([
        'status' => '200',
        'json' => '1',
        'no_store' => '1',
        'keys' => 'worker,stats,broadcast_tick_ms,runtime,hook_flag_names',
        'stats_keys' => implode(',', array_keys((new Stats())->getAll())),
        'hook_flags' => '1',
        'tick_ms' => '25',
        'requests_grew' => '1',
        'contexts' => '1',
        'post' => '405',
    ], $out)
        ->and(explode(',', $r['runtime_keys'] ?? ''))->toEqualCanonicalizing(['hook_flags', 'aio_worker_num', 'aio_task_num', 'event_loop_lag_ms', 'event_loop_lag_max_ms', 'event_loop_lag_avg_ms'])
        ->and(explode(',', $r['hook_names'] ?? ''))->toContain('TCP', 'FILE', 'SLEEP')
    ;
});

test('/_via/stats answers 404 without the Dev Bar', function (): void {
    $via = createVia((new Config())->withDevMode(false));
    $request = new Request();
    $request->server = ['request_uri' => '/_via/stats', 'request_method' => 'GET'];
    $request->header = [];
    $response = new FakeStaticResponse();

    (new RequestHandler($via, new SseHandler($via), new ActionHandler($via)))->handleRequest($request, $response);

    expect($response->statusCode)->toBe(404);
});
