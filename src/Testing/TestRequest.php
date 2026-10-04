<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

use OpenSwoole\Http\Request;

/**
 * A request as OpenSwoole hands it to php-via, built by a TestTab instead of parsed from a socket.
 *
 * @internal
 */
final class TestRequest extends Request {
    /** The host every request names; an action's Origin matches it. */
    public const string HOST = 'localhost';

    /**
     * @param array<array-key, mixed> $query   the parsed query string
     * @param array<string, string>   $cookies
     * @param array<string, string>   $headers by lowercase name, besides host
     */
    public function __construct(string $method, string $path, array $query, array $cookies, array $headers = [], private string $body = '') {
        $this->server = [
            'request_uri' => $path,
            'request_method' => $method,
            'query_string' => http_build_query($query),
            'remote_addr' => '127.0.0.1',
            'server_protocol' => 'HTTP/1.1',
            'request_time' => time(),
        ];
        $this->header = ['host' => self::HOST] + $headers;
        $this->cookie = $cookies;
        $this->get = $query === [] ? null : $query;
        if ($body !== '' && str_starts_with(strtolower($headers['content-type'] ?? ''), 'application/x-www-form-urlencoded')) {
            parse_str($body, $post);
            $this->post = $post;
        }
    }

    public function rawContent(): string {
        return $this->body;
    }

    public function getContent(): string {
        return $this->body;
    }

    public function getMethod(): false|string {
        return $this->server['request_method'];
    }
}
