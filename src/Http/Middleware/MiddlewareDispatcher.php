<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Onion-style PSR-15 middleware pipeline executor.
 *
 * Executes a stack of middleware in order (outer → inner), then delegates to the
 * core handler if no middleware short-circuits. Each middleware wraps the next,
 * forming the classic onion model.
 *
 * WARNING: Middleware instances are long-lived in Swoole — they persist across
 * all requests in the worker process. Do NOT store per-request state on
 * middleware properties. Use $request->withAttribute() to pass data downstream.
 */
class MiddlewareDispatcher implements RequestHandlerInterface {
    /** @var list<MiddlewareInterface> */
    private array $stack;

    private RequestHandlerInterface $coreHandler;

    /**
     * @param list<MiddlewareInterface> $stack       Middleware to execute (first = outermost)
     * @param RequestHandlerInterface   $coreHandler Final handler if no middleware short-circuits
     */
    public function __construct(array $stack, RequestHandlerInterface $coreHandler) {
        $this->stack = $stack;
        $this->coreHandler = $coreHandler;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface {
        if ($this->stack === []) {
            return $this->coreHandler->handle($request);
        }

        // Pop the first middleware and create the next handler in the chain
        $middleware = array_shift($this->stack);
        $next = new self($this->stack, $this->coreHandler);

        return $middleware->process($request, $next);
    }
}
