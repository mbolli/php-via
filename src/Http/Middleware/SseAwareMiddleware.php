<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Middleware;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Marker interface for middleware that should also run on SSE handshake requests.
 *
 * By default, middleware only runs on page requests and action requests.
 * Implement this interface (in addition to MiddlewareInterface) to have your
 * middleware execute on the initial SSE connection handshake as well.
 *
 * Example: an authentication middleware that should reject unauthenticated SSE
 * connections would implement both MiddlewareInterface and SseAwareMiddleware.
 *
 * WARNING: Middleware instances are long-lived in Swoole — they persist across
 * all requests in the worker process. Do NOT store per-request state on
 * middleware properties. Use $request->withAttribute() to pass data downstream.
 *
 * @example
 * ```php
 * class AuthMiddleware implements SseAwareMiddleware
 * {
 *     public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
 *     {
 *         // This runs on page, action, AND SSE requests
 *         $token = $request->getHeaderLine('Authorization');
 *         if (!$this->isValid($token)) {
 *             return new Response(401);
 *         }
 *         return $handler->handle($request->withAttribute('user', $this->getUser($token)));
 *     }
 * }
 * ```
 */
interface SseAwareMiddleware extends MiddlewareInterface {}
