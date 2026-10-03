<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Adapter;

use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Writes a PSR-7 Response into an OpenSwoole Response.
 *
 * Used at the middleware boundary: when middleware short-circuits (e.g. returns 401), and for the
 * response of a plain route (Via::route()), this emitter converts the PSR-7 response back into the
 * OpenSwoole response that the client receives.
 */
class PsrResponseEmitter {
    /** Bytes read from a body of unknown size per write. */
    private const int CHUNK_BYTES = 8192;

    /**
     * A body of known size goes out in one piece. One of unknown size, such as a stream of events,
     * goes out chunk by chunk as it is read, until it ends or the client leaves.
     *
     * @param Response $swooleResponse
     * @param bool     $withoutBody    for HEAD: the headers and the body's Content-Length only
     */
    public function emit(ResponseInterface $psrResponse, object $swooleResponse, bool $withoutBody = false): void {
        // Status code
        $swooleResponse->status($psrResponse->getStatusCode());

        // Headers
        foreach ($psrResponse->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, $value);
            }
        }

        $body = $psrResponse->getBody();
        $size = $body->getSize();

        if ($withoutBody) {
            if ($size !== null && !$psrResponse->hasHeader('Content-Length')) {
                $swooleResponse->header('Content-Length', (string) $size);
            }
            $swooleResponse->end();

            return;
        }

        if ($size !== null) {
            $swooleResponse->end((string) $body);

            return;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            $chunk = $body->read(self::CHUNK_BYTES);
            if ($chunk !== '' && !$swooleResponse->write($chunk)) {
                return;
            }
        }
        $swooleResponse->end();
    }
}
