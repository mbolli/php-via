<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Middleware that attaches incremental Brotli writer callables as PSR-7 request
 * attributes before delegating to the next handler.
 *
 * Handlers (handlePage, SseHandler) extract the writers and apply them at their
 * response->end() / response->write() callsites, which own the OpenSwoole response.
 *
 * PSR-7 request attributes set by this middleware:
 *   brotli_write:  fn(string $chunk): string|false  (BROTLI_FLUSH per chunk)
 *   brotli_finish: fn(): string|false               (BROTLI_FINISH, must be called after last chunk)
 *
 * Implements SseAwareMiddleware so it also runs on the /_sse handshake, where
 * the SSE handler extracts the writers and applies them in the streaming write loop.
 *
 * WARNING: Do NOT store per-request state on middleware properties. Middleware
 * instances are long-lived across all requests in the Swoole worker process.
 * The encoder lives in a BrotliStream created per request, not on $this.
 *
 * BrotliStream creates the encoder on the first write and releases it on brotli_finish,
 * so a writer that outlives its response does not keep the encoder buffers alive.
 */
class BrotliMiddleware implements SseAwareMiddleware {
    public function __construct(private int $level = 4) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        $encoding = $request->getHeaderLine('accept-encoding');

        if (!str_contains($encoding, 'br')) {
            return $handler->handle($request);
        }

        $stream = new BrotliStream($this->level);

        return $handler->handle(
            $request
                ->withAttribute('brotli_write', $stream->write(...))
                ->withAttribute('brotli_finish', $stream->finish(...))
        );
    }
}
