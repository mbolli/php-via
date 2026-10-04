<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\UploadedFile;
use OpenSwoole\Http\Request;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Converts an OpenSwoole Request into a PSR-7 ServerRequest.
 *
 * Used only at the middleware boundary. Internal Via code continues to use
 * OpenSwoole types directly.
 */
class PsrRequestFactory {
    private Psr17Factory $factory;

    public function __construct() {
        $this->factory = new Psr17Factory();
    }

    /**
     * @param string $requestType One of 'page', 'action', 'sse', 'route'
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

        // Uploaded files (multipart)
        if (!empty($swooleRequest->files)) {
            $psrRequest = $psrRequest->withUploadedFiles(self::uploadedFiles($swooleRequest->files));
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

    /**
     * OpenSwoole's files by field name, nested as the field names nest ('docs[]'), as UploadedFile objects.
     * An entry that is no file, such as one with a malformed error code, is left out.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, mixed>
     */
    private static function uploadedFiles(array $files): array {
        $uploaded = [];
        foreach ($files as $field => $file) {
            if (!\is_array($file)) {
                continue;
            }
            if (!\is_array($file['error'] ?? null) && \array_key_exists('error', $file)) {
                $one = self::uploadedFile($file);
                if ($one !== null) {
                    $uploaded[$field] = $one;
                }

                continue;
            }
            $uploaded[$field] = self::uploadedFiles($file);
        }

        return $uploaded;
    }

    /**
     * @param array<array-key, mixed> $file one entry: tmp_name, size, error, name, type
     */
    private static function uploadedFile(array $file): ?UploadedFileInterface {
        $tmpName = $file['tmp_name'] ?? '';
        $name = $file['name'] ?? null;
        $type = $file['type'] ?? null;

        try {
            return new UploadedFile(
                \is_string($tmpName) ? $tmpName : '',
                (int) ($file['size'] ?? 0),
                (int) $file['error'],
                \is_string($name) ? $name : null,
                \is_string($type) ? $type : null,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
