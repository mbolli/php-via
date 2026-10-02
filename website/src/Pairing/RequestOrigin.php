<?php

declare(strict_types=1);

namespace PhpVia\Website\Pairing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Stores the scheme and host the browser used (e.g. "https://via.zweiundeins.gmbh") in the request attribute
 * ATTRIBUTE, preferring X-Forwarded-Proto and X-Forwarded-Host. A host that is not a plain host[:port] gives $fallback.
 */
final class RequestOrigin implements MiddlewareInterface {
    public const string ATTRIBUTE = 'site.origin';

    /**
     * @param string $fallback origin without a trailing slash
     * @param bool   $https    whether the server itself terminates TLS
     */
    public function __construct(
        private string $fallback,
        private bool $https,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $this->origin($request)));
    }

    public function origin(ServerRequestInterface $request): string {
        $scheme = strtolower(self::first($request->getHeaderLine('X-Forwarded-Proto')));
        if ($scheme !== 'http' && $scheme !== 'https') {
            $scheme = $this->https ? 'https' : 'http';
        }

        $host = strtolower(self::first($request->getHeaderLine('X-Forwarded-Host')));
        if ($host === '') {
            $host = strtolower($request->getHeaderLine('Host'));
        }
        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::\d{1,5})?$/', $host) !== 1) {
            return $this->fallback;
        }

        return $scheme . '://' . $host;
    }

    /** The first entry of a comma-separated header that proxies append to */
    private static function first(string $header): string {
        return trim(explode(',', $header, 2)[0]);
    }
}
