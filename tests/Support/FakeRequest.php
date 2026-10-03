<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSwoole\Http\Request;

/**
 * A request with a body that RequestHandler::handleRequest() and the PSR-7 adapter can read without a socket.
 */
final class FakeRequest extends Request {
    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    public function __construct(string $method, string $uri, array $headers = [], array $cookies = [], private string $body = '') {
        $path = strtok($uri, '?');
        $query = (string) parse_url($uri, PHP_URL_QUERY);
        $this->server = [
            'request_uri' => $path === false ? $uri : $path,
            'request_method' => $method,
            'remote_addr' => '127.0.0.1',
            'query_string' => $query,
            'server_protocol' => 'HTTP/1.1',
        ];
        $this->header = $headers + ['host' => 'localhost'];
        $this->cookie = $cookies;
        if ($query !== '') {
            parse_str($query, $get);
            $this->get = $get;
        }
    }

    public function getContent(): false|string {
        return $this->body;
    }

    public function rawContent(): false|string {
        return $this->body;
    }
}
