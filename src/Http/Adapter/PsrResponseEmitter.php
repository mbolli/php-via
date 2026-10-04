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
     * The status and headers are set once the body has been read, or its first chunk, so a body that
     * throws before that leaves the response untouched. One that throws later has the connection
     * closed, so the client sees the response break off, and the throw goes on to the caller.
     *
     * @param Response $swooleResponse
     * @param bool     $withoutBody    for HEAD: the headers and the body's Content-Length only
     */
    public function emit(ResponseInterface $psrResponse, object $swooleResponse, bool $withoutBody = false): void {
        $body = $psrResponse->getBody();
        $size = $body->getSize();

        if ($withoutBody) {
            self::sendHead($psrResponse, $swooleResponse);
            if ($size !== null && !$psrResponse->hasHeader('Content-Length')) {
                $swooleResponse->header('Content-Length', (string) $size);
            }
            $swooleResponse->end();

            return;
        }

        if ($size !== null) {
            $content = (string) $body;
            self::sendHead($psrResponse, $swooleResponse);
            $swooleResponse->end($content);

            return;
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }
        $started = false;

        try {
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);
                if ($chunk === '') {
                    continue;
                }
                if (!$started) {
                    self::sendHead($psrResponse, $swooleResponse);
                    $started = true;
                }
                if (!$swooleResponse->write($chunk)) {
                    return;
                }
            }
        } catch (\Throwable $e) {
            if ($started) {
                $swooleResponse->close();
            }

            throw $e;
        }

        if (!$started) {
            self::sendHead($psrResponse, $swooleResponse);
        }
        $swooleResponse->end();
    }

    /**
     * The arguments of OpenSwoole's rawcookie() for a Set-Cookie header line, null for a line without a name.
     * OpenSwoole keeps one value per header name, so each cookie has to go through rawcookie(). Max-Age becomes
     * the expiry time; Partitioned and other attributes rawcookie() has no argument for are left out.
     *
     * @internal
     *
     * @return null|array{0: string, 1: string, 2: int, 3: string, 4: string, 5: bool, 6: bool, 7: string, 8: string}
     */
    public static function parseSetCookie(string $line): ?array {
        $attributes = explode(';', $line);
        $pair = explode('=', (string) array_shift($attributes), 2);
        $name = trim($pair[0]);
        if (\count($pair) < 2 || $name === '') {
            return null;
        }

        $cookie = [$name, trim($pair[1]), 0, '', '', false, false, '', ''];
        $maxAge = null;
        foreach ($attributes as $attribute) {
            [$key, $value] = array_map(trim(...), explode('=', $attribute, 2)) + [1 => ''];
            match (strtolower($key)) {
                'expires' => $cookie[2] = (int) strtotime($value),
                'max-age' => $maxAge = (int) $value,
                'path' => $cookie[3] = $value,
                'domain' => $cookie[4] = $value,
                'secure' => $cookie[5] = true,
                'httponly' => $cookie[6] = true,
                'samesite' => $cookie[7] = $value,
                'priority' => $cookie[8] = $value,
                default => null,
            };
        }
        if ($maxAge !== null) {
            // An expiry in 1970 deletes the cookie, as Max-Age=0 does.
            $cookie[2] = $maxAge > 0 ? time() + $maxAge : 1;
        }

        return $cookie;
    }

    /**
     * @param Response $swooleResponse
     */
    private static function sendHead(ResponseInterface $psrResponse, object $swooleResponse): void {
        $swooleResponse->status($psrResponse->getStatusCode());
        foreach ($psrResponse->getHeaders() as $name => $values) {
            if (strtolower((string) $name) !== 'set-cookie') {
                $swooleResponse->header((string) $name, implode(', ', $values));

                continue;
            }
            foreach ($values as $line) {
                $cookie = self::parseSetCookie($line);
                if ($cookie !== null) {
                    $swooleResponse->rawcookie(...$cookie);
                }
            }
        }
    }
}
