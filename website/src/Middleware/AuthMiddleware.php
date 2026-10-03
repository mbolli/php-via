<?php

declare(strict_types=1);

namespace PhpVia\Website\Middleware;

use Mbolli\PhpVia\Http\Middleware\SseAwareMiddleware;
use Mbolli\PhpVia\Via;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Example PSR-15 auth middleware for the Login Flow demo.
 *
 * Checks sessionData('auth') for the session in the request's 'via.session' attribute.
 * If the user is not authenticated, redirects to the login page.
 * If authenticated, passes the auth record downstream as a request attribute.
 *
 * Implements SseAwareMiddleware so unauthenticated SSE connections are also rejected.
 */
final class AuthMiddleware implements SseAwareMiddleware {
    public function __construct(
        private Via $app,
        private string $loginUrl = '/examples/login',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        /** @var string $sessionId */
        $sessionId = $request->getAttribute('via.session');

        /** @var null|array{user: string, name: string, role: string, at: int} $auth */
        $auth = $this->app->getSessionData($sessionId, 'auth');

        if ($auth === null) {
            return $this->redirectToLogin();
        }

        return $handler->handle(
            $request->withAttribute('auth', $auth)
        );
    }

    private function redirectToLogin(): ResponseInterface {
        return new Response(302, ['Location' => $this->loginUrl]);
    }
}
