<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSwoole\Http\Request;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Converts an OpenSwoole Request into a PSR-7 ServerRequest.
 *
 * Used only at the middleware boundary — internal Via code continues to use
 * OpenSwoole types directly.
 */
class PsrRequestFactory {
    private Psr17Factory $factory;

    public function __construct() {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param string $requestType One of 'page', 'action', 'sse'
     */
    public function create(Request $swooleRequest, string $requestType = 'page'): ServerRequestInterface {
        $server = $swooleRequest->server ?? [];
        $method = strtoupper($server['request_method'] ?? 'GET');
        $path = $server['request_uri'] ?? '/';
        $queryString = $server['query_string'] ?? '';

        // Build URI
        $scheme = isset($server['https']) && $server['https'] !== 'off' ? 'https' : 'http';
        $host = $swooleRequest->header['host'] ?? ($server['server_name'] ?? 'localhost');
        $uri = $scheme . '://' . $host . $path;
        if ($queryString !== '') {
            $uri .= '?' . $queryString;
        }

        // Create base request
        $psrRequest = $this->factory->createServerRequest($method, $uri, $server);

        // Headers
        foreach ($swooleRequest->header ?? [] as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }

        // Query params
        if (!empty($swooleRequest->get)) {
            $psrRequest = $psrRequest->withQueryParams($swooleRequest->get);
        }

        // Cookies
        if (!empty($swooleRequest->cookie)) {
            $psrRequest = $psrRequest->withCookieParams($swooleRequest->cookie);
        }

        // Parsed body (POST data)
        if (!empty($swooleRequest->post)) {
            $psrRequest = $psrRequest->withParsedBody($swooleRequest->post);
        }

        // Raw body
        $rawContent = $swooleRequest->rawContent();
        if ($rawContent !== false && $rawContent !== '') {
            $body = $this->factory->createStream($rawContent);
            $psrRequest = $psrRequest->withBody($body);
        }

        // Custom attributes for Via internals
        $psrRequest = $psrRequest->withAttribute('via.openswoole_request', $swooleRequest);

        return $psrRequest->withAttribute('via.request_type', $requestType);
    }
}
