<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\State\SharedContextDirectory;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/*
 * input() on a page load reads the page's query, and the context record keeps the query, so the
 * handler sees the same input when a revival or another worker runs it again.
 */

/**
 * Load $path through the RequestHandler and return the context it created, the log and the session cookie it set.
 *
 * @param array<string, mixed> $query
 *
 * @return array{0: Context, 1: string, 2: string}
 */
function pageInputLoad(Via $via, string $path, array $query): array {
    $handler = (new ReflectionProperty(Via::class, 'requestHandler'))->getValue($via);
    assert($handler instanceof RequestHandler);
    $handler->setRoutes($via->getRouter()->getRoutes());

    $request = new Request();
    $request->server = ['request_uri' => $path, 'request_method' => 'GET', 'remote_addr' => '127.0.0.1'];
    $request->header = [];
    $request->get = $query;
    $response = new FakeStaticResponse();
    $before = array_keys($via->contexts);

    ob_start();

    try {
        $handler->handleRequest($request, $response);
    } finally {
        $log = (string) ob_get_clean();
    }

    expect($response->statusCode)->toBe(200, $response->body);
    $created = array_values(array_diff(array_keys($via->contexts), $before));
    expect($created)->toHaveCount(1);

    return [$via->contexts[$created[0]], $log, (string) ($response->cookies['via_session_id'] ?? '')];
}

/**
 * A /search page whose handler records what input('q') and input('page') returned on each run.
 *
 * @param list<array{q: mixed, page: mixed}> $seen
 */
function pageInputApp(array &$seen, ?Config $config = null): Via {
    $via = $config === null ? createVia() : new Via($config);
    $via->page('/search', function (Context $c) use (&$seen): void {
        $seen[] = ['q' => $c->input('q'), 'page' => $c->input('page', '1')];
        $c->action(function (Context $c) use (&$seen): void {
            $seen[] = ['q' => $c->input('q'), 'page' => $c->input('page', '1')];
        }, 'more');
        $c->view(fn (): string => '<div id="r">results</div>');
    });

    return $via;
}

describe('input() on a page load', function (): void {
    test('the page handler reads the page query', function (): void {
        $seen = [];
        $via = pageInputApp($seen);

        [$ctx] = pageInputLoad($via, '/search', ['q' => 'flows', 'page' => '3']);

        expect($seen)->toBe([['q' => 'flows', 'page' => '3']])
            ->and($ctx->getPageInput())->toBe(['q' => 'flows', 'page' => '3'])
        ;
    });

    test('a component reads its page\'s query', function (): void {
        $via = createVia();
        $seen = null;
        $via->page('/c', function (Context $c) use (&$seen): void {
            $widget = $c->component(function (Context $w) use (&$seen): void {
                $seen = $w->input('tab');
                $w->view(fn (): string => '<p>w</p>');
            }, 'widget');
            $c->view(fn (): string => '<div>' . $widget() . '</div>');
        });

        pageInputLoad($via, '/c', ['tab' => 'details']);

        expect($seen)->toBe('details');
    });

    test('an action reads the action request, not the page query', function (): void {
        $seen = [];
        $via = pageInputApp($seen);
        [$ctx, , $cookie] = pageInputLoad($via, '/search', ['q' => 'flows']);

        $request = new FakeActionRequest('more', ['via_ctx' => $ctx->getId()]);
        $request->get = ['page' => '4'];
        $request->cookie = ['via_session_id' => $cookie];
        ob_start();
        (new ActionHandler($via))->handleAction($request, new FakeStaticResponse(), 'more');
        ob_end_clean();

        expect($seen[1])->toBe(['q' => null, 'page' => '4']);
    });

    test('a revived context runs its handler with the page query again', function (): void {
        $seen = [];
        $via = pageInputApp($seen);
        [$ctx] = pageInputLoad($via, '/search', ['q' => 'a b&c', 'f' => ['x', 'y']]);
        $id = $ctx->getId();
        $session = (string) $ctx->getSessionId();

        $via->getApp()->destroyContext($id);
        unset($via->contexts[$id]);
        $revived = $via->reviveContextFromClient($id, $session, []);

        expect($revived)->not->toBeNull()
            ->and($seen[1])->toBe(['q' => 'a b&c', 'page' => '1'])
            ->and($revived?->input('f'))->toBe(['x', 'y'])
        ;
    });

    test('another worker rebuilds the context with the page query from the shared directory', function (): void {
        $directory = new SharedContextDirectory(maxRows: 64);
        $seenA = [];
        $seenB = [];
        $a = pageInputApp($seenA);
        $b = pageInputApp($seenB);
        $a->getApp()->setContextDirectory($directory);
        $b->getApp()->setContextDirectory($directory);

        [$ctx] = pageInputLoad($a, '/search', ['q' => 'talkers']);
        $rebuilt = $b->reviveContextFromClient($ctx->getId(), (string) $ctx->getSessionId(), []);

        expect($rebuilt)->not->toBeNull()
            ->and($seenB)->toBe([['q' => 'talkers', 'page' => '1']])
        ;
    });

    test('a query over 512 bytes stays readable on the page load but leaves the record, with one warning per route', function (): void {
        $seen = [];
        $via = pageInputApp($seen, (new Config())->withLogLevel('warn'));
        $long = str_repeat('x', 600);

        [$ctx] = pageInputLoad($via, '/search', ['q' => $long]);
        [$other] = pageInputLoad($via, '/search', ['q' => $long]);
        ob_start();
        // A single worker takes the record when it destroys the context.
        $via->getApp()->destroyContext($ctx->getId());
        $via->getApp()->destroyContext($other->getId());
        $log = (string) ob_get_clean();
        unset($via->contexts[$ctx->getId()], $via->contexts[$other->getId()]);
        $via->reviveContextFromClient($ctx->getId(), (string) $ctx->getSessionId(), []);

        expect($seen[0]['q'])->toBe($long)
            ->and(substr_count($log, 'leaves out the page\'s query (602 bytes'))->toBe(1)
            ->and($seen[2])->toBe(['q' => null, 'page' => '1'])
        ;
    });

    test('a directory record over its byte cap with the query is written without it', function (): void {
        $directory = new SharedContextDirectory(maxRows: 64, maxRecordBytes: 200);
        $seen = [];
        $via = pageInputApp($seen, (new Config())->withLogLevel('warn'));
        $via->getApp()->setContextDirectory($directory);

        [$ctx, $log] = pageInputLoad($via, '/search', ['q' => str_repeat('y', 300)]);

        expect($directory->get($ctx->getId()))->not->toBeNull()
            ->and($directory->get($ctx->getId()))->not->toHaveKey('query')
            ->and($log)->toContain('maxRecordBytes')
        ;
    });

    test('a page without a query writes no query into the record', function (): void {
        $directory = new SharedContextDirectory(maxRows: 64);
        $seen = [];
        $via = pageInputApp($seen);
        $via->getApp()->setContextDirectory($directory);

        [$ctx] = pageInputLoad($via, '/search', []);

        expect($directory->get($ctx->getId()))->not->toHaveKey('query');
    });
});
