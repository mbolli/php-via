<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeStaticResponse;

/*
 * A throwable that escapes a request coroutine, a timer or a broadcast kills the whole
 * worker and every context on it, so each entry point must catch \Throwable, not \Exception.
 */

/**
 * @return array{0: string, 1: FakeStaticResponse} captured log output and the response
 */
function postThrowingAction(Throwable $thrown): array {
    $via = createVia();
    $ctx = new Context('ctx-throw', '/throw', $via);
    $via->contexts['ctx-throw'] = $ctx;
    $action = $ctx->action(function () use ($thrown): void {
        throw $thrown;
    }, 'boom');

    $response = new FakeStaticResponse();
    ob_start();

    try {
        (new ActionHandler($via))->handleAction(new FakeActionRequest($action->id(), ['via_ctx' => 'ctx-throw']), $response, $action->id());
    } finally {
        $log = (string) ob_get_clean();
    }

    return [$log, $response];
}

describe('actions', function (): void {
    test('an action throwing an Exception answers 500', function (): void {
        [$log, $response] = postThrowingAction(new RuntimeException('plain exception'));

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Action failed');
        expect($log)->toContain('RuntimeException: plain exception at ' . __FILE__ . ':');
    });

    test('an action throwing an Error answers 500 instead of escaping', function (Throwable $thrown, string $class): void {
        [$log, $response] = postThrowingAction($thrown);

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Action failed');
        expect($log)->toContain("{$class}: ");
    })->with([
        'TypeError' => [new TypeError('wrong type'), 'TypeError'],
        'ValueError' => [new ValueError('bad value'), 'ValueError'],
    ]);

    test('a real TypeError from an action closure answers 500', function (): void {
        $via = createVia();
        $ctx = new Context('ctx-type', '/throw', $via);
        $via->contexts['ctx-type'] = $ctx;
        $action = $ctx->action(function (): void {
            strlen([]); // @phpstan-ignore argument.type
        }, 'strlen');

        $response = new FakeStaticResponse();
        ob_start();
        (new ActionHandler($via))->handleAction(new FakeActionRequest($action->id(), ['via_ctx' => 'ctx-type']), $response, $action->id());
        $log = (string) ob_get_clean();

        expect($response->statusCode)->toBe(500);
        expect($log)->toContain('TypeError: strlen()');
    });
});

describe('page render', function (): void {
    test('a view that throws on the initial render answers 500', function (): void {
        $via = createVia();
        $via->page('/viewthrow', function (Context $c): void {
            $c->view(function (): string {
                throw new RuntimeException('view failed');
            });
        });

        // start() hands the routes over; there is no server here.
        $handler = (new ReflectionProperty(Via::class, 'requestHandler'))->getValue($via);
        assert($handler instanceof RequestHandler);
        $handler->setRoutes($via->getRouter()->getRoutes());

        $request = new Request();
        $request->server = ['request_uri' => '/viewthrow', 'request_method' => 'GET'];
        $request->header = [];
        $response = new FakeStaticResponse();

        ob_start();
        $via->handleRequestSafely($request, $response);
        $log = (string) ob_get_clean();

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Internal Server Error');
        expect($log)->toContain('Page render exception on /viewthrow: RuntimeException: view failed');
    });
});

describe('request guard', function (): void {
    test('a throw outside the page and action guards answers 500', function (): void {
        $via = createVia();
        $via->notFound(function (): void {
            throw new LogicException('not found handler failed');
        });

        $request = new Request();
        $request->server = ['request_uri' => '/missing', 'request_method' => 'GET'];
        $request->header = [];
        $response = new FakeStaticResponse();

        ob_start();
        $via->handleRequestSafely($request, $response);
        $log = (string) ob_get_clean();

        expect($response->statusCode)->toBe(500);
        expect($response->body)->toBe('Internal Server Error');
        expect($log)->toContain('Unhandled exception on /missing: LogicException: not found handler failed at ' . __FILE__ . ':');
    });
});

describe('broadcast fan-out', function (): void {
    test('a context whose view throws does not stop the others from getting the frame', function (): void {
        $via = createVia();
        $count = 0;

        $broken = new Context('ctx-broken', '/a', $via);
        $healthy = new Context('ctx-healthy', '/b', $via);
        foreach ([$broken, $healthy] as $ctx) {
            $ctx->scope(Scope::GLOBAL);
            $via->contexts[$ctx->getId()] = $ctx;
        }
        $broken->view(function () use (&$count): string {
            if ($count > 0) {
                throw new RuntimeException('broken view');
            }

            return '<div id="a">ok</div>';
        });
        $healthy->view(function () use (&$count): string {
            return '<div id="b">Count: ' . $count . '</div>';
        });
        $broken->renderView();
        $healthy->renderView();

        $count = 1;
        ob_start();
        $via->broadcast(Scope::GLOBAL);
        $log = (string) ob_get_clean();

        $patch = $healthy->getPatch();
        expect($patch)->not->toBeNull();
        expect($patch['content'])->toContain('Count: 1');
        expect($log)->toContain('[ctx-broken] Sync failed during broadcast: RuntimeException: broken view');
    });
});

describe('per-tab interval', function (): void {
    test('a throwing Context::setInterval() callback is logged and the timer keeps running', function (): void {
        $output = [];
        $exit = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../Fixtures/tab_interval_throw.php') . ' 2>&1', $output, $exit);
        $out = implode("\n", $output);

        expect($exit)->toBe(0, $out);
        expect($out)->toContain('Interval callback failed: RuntimeException: tab interval failed');
        expect($out)->toContain('ticks=3');
    });
});
