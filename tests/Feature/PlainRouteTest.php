<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\ErrorPhase;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Support\LogBuffer;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response;
use OpenSwoole\Coroutine\Http\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FakeRequest;
use Tests\Support\FakeStaticResponse;

/*
 * Via::route(): a PSR-15 handler behind the global and route middleware, with no context,
 * shell or template.
 */

const PLAIN_ROUTE_SESSION = 'fedcba9876543210fedcba9876543210';

/** Answers JSON with what it was asked, and keeps the requests. */
final class PlainEchoHandler implements RequestHandlerInterface {
    /** @var list<ServerRequestInterface> */
    public array $requests = [];

    public function __construct(private string $name = 'echo') {}

    public function handle(ServerRequestInterface $request): ResponseInterface {
        $this->requests[] = $request;

        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'handler' => $this->name,
            'method' => $request->getMethod(),
            'body' => (string) $request->getBody(),
        ]));
    }
}

/** Notes its name in a shared log, and answers 401 itself when asked to. */
final class PlainTraceMiddleware implements MiddlewareInterface {
    /**
     * @param ArrayObject<int, string> $log
     */
    public function __construct(private string $name, private ArrayObject $log, private bool $deny = false) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $this->log[] = $this->name;

        return $this->deny ? new Response(401, [], 'denied') : $handler->handle($request);
    }
}

/**
 * A body of unknown size that reads $parts in turn, throwing the ones that are throwables.
 *
 * @param list<string|Throwable> $parts
 */
function plainFailingBody(array $parts): StreamInterface {
    return new class($parts) implements StreamInterface {
        /** @param list<string|Throwable> $parts */
        public function __construct(private array $parts) {}

        public function __toString(): string {
            return '';
        }

        public function close(): void {}

        public function detach() {
            return null;
        }

        public function getSize(): ?int {
            return null;
        }

        public function tell(): int {
            return 0;
        }

        public function eof(): bool {
            return $this->parts === [];
        }

        public function isSeekable(): bool {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool {
            return false;
        }

        public function write(string $string): int {
            return 0;
        }

        public function isReadable(): bool {
            return true;
        }

        public function read(int $length): string {
            $part = array_shift($this->parts);
            if ($part instanceof Throwable) {
                throw $part;
            }

            return (string) $part;
        }

        public function getContents(): string {
            return '';
        }

        public function getMetadata(?string $key = null): mixed {
            return null;
        }
    };
}

/**
 * @param array<string, string> $headers
 * @param array<string, string> $cookies
 */
function plainRequest(Via $via, string $method, string $uri, array $headers = [], array $cookies = [], string $body = ''): FakeStaticResponse {
    $handler = (new ReflectionProperty(Via::class, 'requestHandler'))->getValue($via);
    assert($handler instanceof RequestHandler);
    $handler->setRoutes($via->getRouter()->getRoutes());
    $response = new FakeStaticResponse();
    $handler->handleRequest(new FakeRequest($method, $uri, $headers, $cookies, $body), $response);

    return $response;
}

describe('Via::route()', function (): void {
    test('sends the handler\'s response, and creates no context and no session cookie', function (): void {
        $via = createVia();
        $handler = new PlainEchoHandler();
        $via->route('GET', '/api/items/{id}', $handler);

        $response = plainRequest($via, 'GET', '/api/items/7?full=1', cookies: [SessionManager::SESSION_COOKIE_NAME => PLAIN_ROUTE_SESSION]);

        expect($response->statusCode)->toBe(200)
            ->and($response->headers['Content-Type'] ?? null)->toBe('application/json')
            ->and(json_decode($response->body, true))->toBe(['handler' => 'echo', 'method' => 'GET', 'body' => ''])
            ->and($via->contexts)->toBe([])
            ->and($response->cookies)->toBe([])
        ;

        $request = $handler->requests[0];
        expect($request->getAttribute('id'))->toBe('7')
            ->and($request->getAttribute('via.session'))->toBe(PLAIN_ROUTE_SESSION)
            ->and($request->getAttribute('via.request_type'))->toBe('route')
            ->and($request->getQueryParams())->toBe(['full' => '1'])
        ;
    });

    test('takes a request body without an Origin header, which php-via checks only for its own endpoints', function (): void {
        $via = createVia();
        $via->route('POST', '/hooks/github', new PlainEchoHandler());

        $response = plainRequest($via, 'POST', '/hooks/github', ['content-type' => 'application/json'], body: '{"zen":"ok"}');

        expect($response->statusCode)->toBe(200)
            ->and(json_decode($response->body, true)['body'])->toBe('{"zen":"ok"}')
        ;
    });

    test('runs the global middleware, then the route\'s, around the handler', function (): void {
        $via = createVia();
        $log = new ArrayObject();
        $handler = new PlainEchoHandler();
        $via->middleware(new PlainTraceMiddleware('global', $log));
        $via->route('GET', '/api', $handler)->middleware(new PlainTraceMiddleware('route', $log));
        $via->route('GET', '/admin', $handler)->middleware(new PlainTraceMiddleware('guard', $log, deny: true));

        $ok = plainRequest($via, 'GET', '/api');
        $denied = plainRequest($via, 'GET', '/admin');

        expect($ok->statusCode)->toBe(200)
            ->and($denied->statusCode)->toBe(401)
            ->and($denied->body)->toBe('denied')
            ->and($log->getArrayCopy())->toBe(['global', 'route', 'global', 'guard'])
            ->and($handler->requests)->toHaveCount(1)
        ;
    });

    test('answers its methods, and a page on the same path the others', function (): void {
        $via = createVia();
        $via->route('POST', '/form', new PlainEchoHandler());
        $via->page('/form', fn (Context $c) => $c->view(fn (): string => '<p>the form</p>'));

        $post = plainRequest($via, 'POST', '/form', body: 'a=1');
        $get = plainRequest($via, 'GET', '/form');

        expect(json_decode($post->body, true)['body'])->toBe('a=1')
            ->and($get->statusCode)->toBe(200)
            ->and($get->body)->toContain('<p>the form</p>')
        ;
    });

    test('answers 405 with the methods it takes on a path no page has', function (): void {
        $via = createVia();
        $via->route(['get', 'POST'], '/api', new PlainEchoHandler());

        $response = plainRequest($via, 'PUT', '/api');

        expect($response->statusCode)->toBe(405)
            ->and($response->headers['Allow'] ?? null)->toBe('GET, POST, HEAD')
            ->and(plainRequest($via, 'DELETE', '/elsewhere')->statusCode)->toBe(404)
        ;
    });

    test('answers HEAD through a GET route, which sees GET, with the length of the body GET sends and no body', function (): void {
        $via = createVia();
        $handler = new PlainEchoHandler();
        $via->route('GET', '/api', $handler);

        $response = plainRequest($via, 'HEAD', '/api');

        expect($response->statusCode)->toBe(200)
            ->and($response->body)->toBe('')
            ->and($response->headers['Content-Length'] ?? null)->toBe((string) strlen((string) json_encode(['handler' => 'echo', 'method' => 'GET', 'body' => ''])))
            ->and($handler->requests[0]->getMethod())->toBe('GET')
            ->and(plainRequest($via, 'HEAD', '/nothing')->statusCode)->toBe(404)
        ;
    });

    test('hands HEAD to a route registered for HEAD as HEAD, still without the body', function (): void {
        $via = createVia();
        $get = new PlainEchoHandler('get');
        $head = new PlainEchoHandler('head');
        $via->route('GET', '/api', $get);
        $via->route('HEAD', '/api', $head);

        $response = plainRequest($via, 'HEAD', '/api');

        expect($response->body)->toBe('')
            ->and($get->requests)->toBe([])
            ->and($head->requests[0]->getMethod())->toBe('HEAD')
        ;
    });

    test("takes every method with '*', after the path's own routes, so the path never answers 405", function (): void {
        $via = createVia();
        $any = new PlainEchoHandler('any');
        $via->route('*', '/mcp', $any);
        $via->route('GET', '/mcp', new PlainEchoHandler('get'));

        $put = plainRequest($via, 'PUT', '/mcp', body: 'x');
        $patch = plainRequest($via, 'PATCH', '/mcp');
        $get = plainRequest($via, 'GET', '/mcp');
        $head = plainRequest($via, 'HEAD', '/mcp');

        expect(json_decode($put->body, true))->toBe(['handler' => 'any', 'method' => 'PUT', 'body' => 'x'])
            ->and(json_decode($patch->body, true)['method'] ?? null)->toBe('PATCH')
            ->and(json_decode($get->body, true)['handler'] ?? null)->toBe('get')
            ->and($head->statusCode)->toBe(200)
            ->and($any->requests)->toHaveCount(2)
        ;
    });

    test('takes the prefix and the middleware of its group', function (): void {
        $via = createVia();
        $log = new ArrayObject();
        $via->group('/api', function (Via $app): void {
            $app->route('GET', '/ping', new PlainEchoHandler('ping'));
            $app->page('/docs', fn (Context $c) => $c->view(fn (): string => '<p>docs</p>'));
        })->middleware(new PlainTraceMiddleware('group', $log));

        $response = plainRequest($via, 'GET', '/api/ping');

        expect(json_decode($response->body, true)['handler'])->toBe('ping')
            ->and(plainRequest($via, 'GET', '/ping')->statusCode)->toBe(404)
            ->and($log->getArrayCopy())->toBe(['group'])
        ;
    });

    test('replaces the handler of a path and method registered again', function (): void {
        $via = createVia();
        $via->route('GET', '/api', new PlainEchoHandler('first'));
        $via->route('GET', '/api', new PlainEchoHandler('second'));

        expect(json_decode(plainRequest($via, 'GET', '/api')->body, true)['handler'])->toBe('second');
    });

    test('answers 500 and logs when the handler throws', function (): void {
        $via = createVia();
        $buffer = new LogBuffer();
        $via->getApp()->getLogger()->setBuffer($buffer);
        $via->route('GET', '/boom', new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface {
                throw new RuntimeException('handler broke');
            }
        });

        ob_start();

        try {
            $response = plainRequest($via, 'GET', '/boom');
        } finally {
            ob_end_clean();
        }

        expect($response->statusCode)->toBe(500)
            ->and($response->body)->toBe('Internal Server Error')
            ->and(implode("\n", array_column($buffer->since(0), 'message')))->toContain('Route handler exception on /boom')
        ;
    });

    test('answers 500 and reports Route when the response body throws before its first chunk', function (): void {
        $reports = [];
        $app = new TestApp((new Config())->withLogLevel('error'), static function (Via $via) use (&$reports): void {
            $via->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $route) use (&$reports): void {
                $reports[] = [$phase, $route, $e->getMessage(), $c];
            });
            $via->route('GET', '/events', new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface {
                    return new Response(200, ['Content-Type' => 'text/event-stream'], plainFailingBody([new RuntimeException('upstream down')]));
                }
            });
        });

        $response = $app->request('GET', '/events');
        $app->shutdown();

        expect($response->getStatusCode())->toBe(500)
            ->and((string) $response->getBody())->toBe('Internal Server Error')
            ->and($response->getHeaderLine('Content-Type'))->not->toBe('text/event-stream')
            ->and($reports)->toBe([[ErrorPhase::Route, '/events', 'upstream down', null]])
            ->and(implode("\n", $app->logs()))->toContain('Route response body failed on /events: RuntimeException: upstream down')
        ;
    });

    test('breaks the response off and reports Route when the response body throws after a chunk', function (): void {
        $reports = [];
        $app = new TestApp((new Config())->withLogLevel('error'), static function (Via $via) use (&$reports): void {
            $via->onError(static function (Throwable $e, ?Context $c, ErrorPhase $phase, ?string $route) use (&$reports): void {
                $reports[] = "{$phase->value} {$route} {$e->getMessage()}";
            });
            $via->route('POST', '/mcp', new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface {
                    return new Response(200, ['Content-Type' => 'text/event-stream'], plainFailingBody(["event: message\ndata: {}\n\n", new RuntimeException('upstream failed mid-stream')]));
                }
            });
        });

        expect(fn () => $app->request('POST', '/mcp', '{}'))->toThrow(RuntimeException::class, 'broke off')
            ->and($reports)->toBe(['route /mcp upstream failed mid-stream'])
        ;
        $app->shutdown();
    });

    test('throws for no method, or for a method name that is not one', function (mixed $methods): void {
        // @phpstan-ignore argument.type
        createVia()->route($methods, '/x', new PlainEchoHandler());
    })->throws(InvalidArgumentException::class)->with([
        'none' => [[]],
        'a path in the name' => ['GET /x'],
        'a number' => [[1]],
        'a wildcard in a name' => ['GE*'],
    ]);
});

describe('Via::route() on a real server', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
        }
    });

    test('answers JSON, a streamed body, HEAD, a preflight, 405, any method and uploads over HTTP, and closes a body that fails', function (): void {
        $out = (string) shell_exec(
            'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/plain_route_server.php') . ' 2>&1'
        );
        preg_match_all('/^([a-z_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);
        $r = [];
        foreach ($m as [, $key, $value]) {
            $r[$key] = $value;
        }

        expect($r['json'] ?? null)->toBe('200 application/json {"pong":true,"session":true}', $out)
            ->and($r['stream'] ?? null)->toBe('200 one|two|three')
            ->and($r['head'] ?? null)->toBe('200 body=0')
            ->and($r['preflight'] ?? null)->toBe('204 POST')
            ->and($r['wrong_method'] ?? null)->toBe('405 GET, HEAD')
            ->and($r['set_cookie'] ?? null)->toBe('none')
            ->and($r['head_as_get'] ?? null)->toBe('200 body=0')
            ->and($r['any_method'] ?? null)->toBe('404 off for PUT')
            ->and($r['upload'] ?? null)->toBe('200 up.csv:7:id,name docs=2 name=flows')
            ->and($r['body_early'] ?? null)->toBe('500 text/html Internal Server Error')
            ->and($r['body_midway'] ?? null)->toBe('200 "data: 1\n\n" ended=no')
            ->and($r['reports'] ?? null)->toBe('route /api/early upstream down | route /api/midway upstream failed mid-stream')
        ;
    });
});
