<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Adapter;

use OpenSwoole\Http\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Writes a PSR-7 Response into an OpenSwoole Response.
 *
 * Used only at the middleware boundary — when middleware short-circuits
 * (e.g. returns 401), this emitter converts the PSR-7 response back into
 * the OpenSwoole response that the client receives.
 */
class PsrResponseEmitter {
    /**
     * @param Response $swooleResponse
     */
    public function emit(ResponseInterface $psrResponse, object $swooleResponse): void {
        // Status code
        $swooleResponse->status($psrResponse->getStatusCode());

        // Headers
        foreach ($psrResponse->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $swooleResponse->header($name, $value);
            }
        }

        // Body
        $body = $psrResponse->getBody();
        $swooleResponse->end((string) $body);
    }
}
