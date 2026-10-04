<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Testing\TestRequest;
use Mbolli\PhpVia\Testing\TestResponse;
use Mbolli\PhpVia\Via;

// A stream for a context the worker does not know: what it keeps and logs must not grow with what a client sends.

function unknownContextApp(): TestApp {
    return new TestApp((new Config())->withLogLevel('info')->withContextTimeouts(revivalWindowMs: 0), static function (Via $via): void {
        $via->page('/p', static fn (Context $c) => $c->view(static fn (): string => '<p id="p">p</p>'));
    });
}

/** @return array{TestResponse, string} the response and what was logged */
function unknownContextConnect(TestApp $app, mixed $contextId): array {
    $response = new TestResponse($app->nextFd());
    $logs = $app->send(new TestRequest('GET', '/_sse', ['datastar' => (string) json_encode(['via_ctx' => $contextId])], [], ['accept' => 'text/event-stream']), $response);

    return [$response, $logs];
}

function unknownContextSse(TestApp $app): SseHandler {
    $handler = (new ReflectionProperty(TestApp::class, 'handler'))->getValue($app);

    return (new ReflectionProperty(RequestHandler::class, 'sseHandler'))->getValue($handler);
}

function unknownContextReloadCount(TestApp $app): int {
    return count((new ReflectionProperty(SseHandler::class, 'reloadedContextIds'))->getValue(unknownContextSse($app)));
}

test('a via_ctx no context id could be is refused before it is kept or logged', function (mixed $contextId): void {
    $app = unknownContextApp();

    [$response, $logs] = unknownContextConnect($app, $contextId);

    expect([$response->statusCode, $response->body])->toBe([400, 'Invalid context'])
        ->and($logs)->toBe('')
        ->and(unknownContextReloadCount($app))->toBe(0)
    ;
})->with([
    'long' => [str_repeat('a', 4000) . '_/0123456789abcdef'],
    'a line break' => ["/p\n[error] forged_/0123456789abcdef"],
    'an escape sequence' => ["/p_/\e[31m"],
    'not a string' => [['/p_/0123456789abcdef']],
]);

test('an unknown context id is told to reload once, then closed silently', function (): void {
    $app = unknownContextApp();

    [$first, $logs] = unknownContextConnect($app, '/p_/0123456789abcdef');
    [$second] = unknownContextConnect($app, '/p_/0123456789abcdef');

    expect($first->body)->toContain('window.location.reload()')
        ->and($logs)->toContain('Context expired, sending reload: /p_/0123456789abcdef')
        ->and($second->body)->toBe('')
    ;
});

test('the contexts told to reload stay at 10 000, the oldest going first', function (): void {
    $app = unknownContextApp();
    $sse = unknownContextSse($app);
    $property = new ReflectionProperty(SseHandler::class, 'reloadedContextIds');
    $ids = [];
    for ($i = 0; $i < 10_000; ++$i) {
        $ids['/p_/' . sprintf('%016x', $i)] = time();
    }
    $property->setValue($sse, $ids);

    unknownContextConnect($app, '/p_/ffffffffffffffff');
    $kept = $property->getValue($sse);

    expect(count($kept))->toBe(10_000)
        ->and(array_key_first($kept))->toBe('/p_/0000000000000001')
        ->and(array_key_last($kept))->toBe('/p_/ffffffffffffffff')
    ;
});
