<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use OpenSwoole\Http\Request;
use OpenSwoole\Runtime;
use Tests\Support\FakeStaticResponse;

/*
 * A call that blocks a worker is invisible in the logs. The dev-mode /_stats endpoint shows the hook
 * flags the worker runs under, its AIO threads and, on a running server, its event loop lag.
 */

test('the dev-mode /_stats endpoint reports the worker\'s hook flags and AIO threads', function (): void {
    $via = createVia((new Config())->withDevMode());
    $request = new Request();
    $request->server = ['request_uri' => '/_stats', 'request_method' => 'GET'];
    $request->header = [];
    $response = new FakeStaticResponse();

    (new RequestHandler($via, new SseHandler($via), new ActionHandler($via)))->handleRequest($request, $response);

    $runtime = json_decode($response->body, true)['runtime'] ?? [];
    expect($runtime['hook_flags'] ?? null)->toBe(Runtime::getHookFlags());
    expect($runtime)->toHaveKeys(['aio_worker_num', 'aio_task_num']);
});
