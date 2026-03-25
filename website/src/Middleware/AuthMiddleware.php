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
 * Checks sessionData('auth') for the session cookie found in the request.
 * If the user is not authenticated, redirects to the login page.
 * If authenticated, passes the auth record downstream as a request attribute.
 *
 * Implements SseAwareMiddleware so unauthenticated SSE connections are also rejected.
 */
final class AuthMiddleware implements SseAwareMiddleware {
    private const string SESSION_COOKIE = 'via_session_id';
    private const string SECURE_SESSION_COOKIE = '__Host-via_session_id';

    public function __construct(
        private Via $app,
        private string $loginUrl = '/examples/login',
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $cookies = $request->getCookieParams();
        $sessionId = $cookies[self::SECURE_SESSION_COOKIE] ?? $cookies[self::SESSION_COOKIE] ?? null;

        if ($sessionId === null) {
            return $this->redirectToLogin();
        }

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
