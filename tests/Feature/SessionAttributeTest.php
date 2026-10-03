<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Http\Middleware\SseAwareMiddleware;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response;
use OpenSwoole\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tests\Support\FakeActionRequest;
use Tests\Support\FakeRequest;
use Tests\Support\FakeStaticResponse;

/*
 * Middleware reads the visitor's session id from the 'via.session' request attribute on pages,
 * actions and SSE, and for a request without the cookie it is the id the page then sets.
 */

const SESSION_ATTRIBUTE_ID = '0123456789abcdef0123456789abcdef';

/** Records the 'via.session' attribute it sees, and answers 204 itself when asked to. */
final class SessionRecorder implements SseAwareMiddleware {
    /** @var list<mixed> */
    public array $seen = [];

    public function __construct(private bool $shortCircuit = false) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $this->seen[] = $request->getAttribute('via.session');

        return $this->shortCircuit ? new Response(204) : $handler->handle($request);
    }
}

function sessionAttributeHandler(Via $via): RequestHandler {
    $handler = (new ReflectionProperty(Via::class, 'requestHandler'))->getValue($via);
    assert($handler instanceof RequestHandler);
    $handler->setRoutes($via->getRouter()->getRoutes());

    return $handler;
}

/**
 * @param array<string, string> $cookies
 */
function sessionAttributeGet(Via $via, string $path, array $cookies = []): FakeStaticResponse {
    $response = new FakeStaticResponse();
    sessionAttributeHandler($via)->handleRequest(new FakeRequest('GET', $path, cookies: $cookies), $response);

    return $response;
}

describe("the 'via.session' attribute", function (): void {
    test('is the id a page sets as its cookie when the request has none', function (): void {
        $via = createVia();
        $recorder = new SessionRecorder();
        $pageSession = null;
        $via->page('/p', function (Context $c) use (&$pageSession): void {
            $pageSession = $c->getSessionId();
            $c->view(fn (): string => '<p>ok</p>');
        })->middleware($recorder);

        $response = sessionAttributeGet($via, '/p');

        expect($response->statusCode)->toBe(200)
            ->and($recorder->seen)->toHaveCount(1)
            ->and($recorder->seen[0])->toBeString()
            ->and(SessionManager::isValidSessionId($recorder->seen[0]))->toBeTrue()
            ->and($pageSession)->toBe($recorder->seen[0])
            ->and($response->cookies[SessionManager::SESSION_COOKIE_NAME] ?? null)->toBe($recorder->seen[0])
        ;
    });

    test('is the cookie\'s id when the request carries one', function (): void {
        $via = createVia();
        $recorder = new SessionRecorder();
        $via->middleware($recorder);
        $via->page('/p', fn (Context $c) => $c->view(fn (): string => '<p>ok</p>'));

        sessionAttributeGet($via, '/p', [SessionManager::SESSION_COOKIE_NAME => SESSION_ATTRIBUTE_ID]);

        expect($recorder->seen)->toBe([SESSION_ATTRIBUTE_ID]);
    });

    test('reaches middleware that answers a page itself', function (): void {
        $via = createVia();
        $recorder = new SessionRecorder(shortCircuit: true);
        $via->page('/p', fn (Context $c) => $c->view(fn (): string => '<p>ok</p>'))->middleware($recorder);

        $response = sessionAttributeGet($via, '/p', [SessionManager::SESSION_COOKIE_NAME => SESSION_ATTRIBUTE_ID]);

        expect($response->statusCode)->toBe(204)->and($recorder->seen)->toBe([SESSION_ATTRIBUTE_ID]);
    });

    test('is set on actions', function (): void {
        $via = createVia();
        $recorder = new SessionRecorder(shortCircuit: true);
        $via->middleware($recorder);
        $request = new FakeActionRequest('save', ['via_ctx' => 'nope']);
        $request->cookie = [SessionManager::SESSION_COOKIE_NAME => SESSION_ATTRIBUTE_ID];

        $response = new FakeStaticResponse();
        sessionAttributeHandler($via)->handleRequest($request, $response);

        expect($response->statusCode)->toBe(204)->and($recorder->seen)->toBe([SESSION_ATTRIBUTE_ID]);
    });

    test('is set on the SSE handshake', function (): void {
        $via = createVia();
        $recorder = new SessionRecorder(shortCircuit: true);
        $via->middleware($recorder);

        $response = sessionAttributeGet($via, '/_sse', [SessionManager::SESSION_COOKIE_NAME => SESSION_ATTRIBUTE_ID]);

        expect($response->statusCode)->toBe(204)->and($recorder->seen)->toBe([SESSION_ATTRIBUTE_ID]);
    });
});

describe('SessionManager::getOrCreateSessionId()', function (): void {
    test('gives one request without a cookie the same new id on every call, and another request another', function (): void {
        $manager = new SessionManager(new Logger('error'));
        $first = new Request();
        $first->cookie = [SessionManager::SESSION_COOKIE_NAME => 'not-an-id'];
        $second = new Request();

        $id = $manager->getOrCreateSessionId($first);

        expect(SessionManager::isValidSessionId($id))->toBeTrue()
            ->and($manager->getOrCreateSessionId($first))->toBe($id)
            ->and($manager->getOrCreateSessionId($second))->not->toBe($id)
        ;
    });
});
