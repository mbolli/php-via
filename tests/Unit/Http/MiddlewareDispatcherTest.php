<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\Middleware\MiddlewareDispatcher;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/*
 * MiddlewareDispatcher Tests
 *
 * Tests the PSR-15 onion-style middleware pipeline executor.
 */

function createTestRequest(string $method = 'GET', string $uri = 'http://localhost/'): ServerRequestInterface {
    return (new Psr17Factory())->createServerRequest($method, $uri);
}

function createTestHandler(int $statusCode = 200, string $body = 'core'): RequestHandlerInterface {
    return new class($statusCode, $body) implements RequestHandlerInterface {
        public bool $called = false;

        public function __construct(private int $statusCode, private string $body) {}

        public function handle(ServerRequestInterface $request): ResponseInterface {
            $this->called = true;

            return new Response($this->statusCode, [], $this->body);
        }
    };
}

describe('MiddlewareDispatcher', function (): void {
    test('empty pipeline delegates to core handler', function (): void {
        $core = createTestHandler(200, 'core response');
        $dispatcher = new MiddlewareDispatcher([], $core);

        $response = $dispatcher->handle(createTestRequest());

        expect($core->called)->toBeTrue();
        expect((string) $response->getBody())->toBe('core response');
        expect($response->getStatusCode())->toBe(200);
    });

    test('single middleware wraps core handler', function (): void {
        $log = [];
        $middleware = new class($log) implements MiddlewareInterface {
            /** @var list<string> */
            private array $log;

            /** @param list<string> $log */
            public function __construct(array &$log) {
                $this->log = &$log;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $this->log[] = 'before';
                $response = $handler->handle($request);
                $this->log[] = 'after';

                return $response;
            }
        };

        $core = createTestHandler();
        $dispatcher = new MiddlewareDispatcher([$middleware], $core);

        $dispatcher->handle(createTestRequest());

        expect($log)->toBe(['before', 'after']);
        expect($core->called)->toBeTrue();
    });

    test('middleware executes in onion order (outer → inner → core → inner → outer)', function (): void {
        $log = [];

        $outerMiddleware = new class($log) implements MiddlewareInterface {
            /** @var list<string> */
            private array $log;

            /** @param list<string> $log */
            public function __construct(array &$log) {
                $this->log = &$log;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $this->log[] = 'outer-before';
                $response = $handler->handle($request);
                $this->log[] = 'outer-after';

                return $response;
            }
        };

        $innerMiddleware = new class($log) implements MiddlewareInterface {
            /** @var list<string> */
            private array $log;

            /** @param list<string> $log */
            public function __construct(array &$log) {
                $this->log = &$log;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $this->log[] = 'inner-before';
                $response = $handler->handle($request);
                $this->log[] = 'inner-after';

                return $response;
            }
        };

        $core = createTestHandler();
        $dispatcher = new MiddlewareDispatcher([$outerMiddleware, $innerMiddleware], $core);

        $dispatcher->handle(createTestRequest());

        expect($log)->toBe(['outer-before', 'inner-before', 'inner-after', 'outer-after']);
    });

    test('middleware can short-circuit without calling handler', function (): void {
        $authMiddleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return new Response(401, [], 'Unauthorized');
            }
        };

        $core = createTestHandler();
        $dispatcher = new MiddlewareDispatcher([$authMiddleware], $core);

        $response = $dispatcher->handle(createTestRequest());

        expect($core->called)->toBeFalse();
        expect($response->getStatusCode())->toBe(401);
        expect((string) $response->getBody())->toBe('Unauthorized');
    });

    test('middleware can modify request attributes and pass downstream', function (): void {
        $authMiddleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request->withAttribute('user', 'alice'));
            }
        };

        $receivedUser = null;
        $core = new class($receivedUser) implements RequestHandlerInterface {
            private ?string $receivedUser;

            public function __construct(?string &$receivedUser) {
                $this->receivedUser = &$receivedUser;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface {
                $this->receivedUser = $request->getAttribute('user');

                return new Response(200);
            }
        };

        $dispatcher = new MiddlewareDispatcher([$authMiddleware], $core);
        $dispatcher->handle(createTestRequest());

        expect($receivedUser)->toBe('alice');
    });

    test('middleware can modify response on the way out', function (): void {
        $corsMiddleware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $response = $handler->handle($request);

                return $response->withHeader('Access-Control-Allow-Origin', '*');
            }
        };

        $core = createTestHandler(200, 'ok');
        $dispatcher = new MiddlewareDispatcher([$corsMiddleware], $core);

        $response = $dispatcher->handle(createTestRequest());

        expect($response->getHeaderLine('Access-Control-Allow-Origin'))->toBe('*');
        expect((string) $response->getBody())->toBe('ok');
    });

    test('three-layer pipeline with short-circuit in the middle', function (): void {
        $log = [];

        $first = new class($log) implements MiddlewareInterface {
            /** @var list<string> */
            private array $log;

            /** @param list<string> $log */
            public function __construct(array &$log) {
                $this->log = &$log;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $this->log[] = 'first-before';
                $response = $handler->handle($request);
                $this->log[] = 'first-after';

                return $response;
            }
        };

        $blocker = new class($log) implements MiddlewareInterface {
            /** @var list<string> */
            private array $log;

            /** @param list<string> $log */
            public function __construct(array &$log) {
                $this->log = &$log;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $this->log[] = 'blocker';

                return new Response(403, [], 'Forbidden');
            }
        };

        $neverReached = new class($log) implements MiddlewareInterface {
            /** @var list<string> */
            private array $log;

            /** @param list<string> $log */
            public function __construct(array &$log) {
                $this->log = &$log;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                $this->log[] = 'never';

                return $handler->handle($request);
            }
        };

        $core = createTestHandler();
        $dispatcher = new MiddlewareDispatcher([$first, $blocker, $neverReached], $core);

        $response = $dispatcher->handle(createTestRequest());

        expect($log)->toBe(['first-before', 'blocker', 'first-after']);
        expect($response->getStatusCode())->toBe(403);
        expect($core->called)->toBeFalse();
    });
});
