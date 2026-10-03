<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

use OpenSwoole\Http\Response;

/**
 * A response that records what php-via sends instead of writing it to a socket.
 *
 * A stream's response stays writable until hangUp(), and takeBody() hands out what it wrote since.
 *
 * @internal
 */
final class TestResponse extends Response {
    public int $statusCode = 200;

    /** @var array<string, string> by lowercase name, the last value of each */
    public array $headers = [];

    /** @var array<string, null|string> name => value, null for a cookie the response deletes */
    public array $cookies = [];

    public string $body = '';

    /** The connection was closed before the response ended, as a download whose source throws midway closes it. */
    public bool $closed = false;

    private bool $sent = false;
    private bool $ended = false;
    private bool $hungUp = false;

    public function __construct(int $fd = 0) {
        $this->fd = $fd;
    }

    /**
     * The client goes away: the response is no longer writable.
     */
    public function hangUp(): void {
        $this->hungUp = true;
    }

    /**
     * What was written since the last call.
     */
    public function takeBody(): string {
        $body = $this->body;
        $this->body = '';

        return $body;
    }

    public function header(string $key, string $value, bool $format = true): bool {
        $name = strtolower($key);
        $this->headers[$name] = $value;
        if ($name === 'set-cookie') {
            $this->takeSetCookie($value);
        }

        return true;
    }

    public function setHeader(string $key, string $value, bool $format = true): bool {
        return $this->header($key, $value, $format);
    }

    public function cookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        $this->cookies[$key] = $value === null || $value === '' || ($expire > 0 && $expire <= time()) ? null : $value;

        return true;
    }

    public function setCookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        return $this->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority);
    }

    public function rawcookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        return $this->cookie($key, $value, $expire, $path, $domain, $secure, $httpOnly, $sameSite, $priority);
    }

    public function status(int $statusCode, string $reason = ''): bool {
        // OpenSwoole keeps the status of a response that has started to send.
        if (!$this->sent) {
            $this->statusCode = $statusCode;
        }

        return true;
    }

    public function setStatusCode(int $statusCode, string $reason = ''): bool {
        return $this->status($statusCode, $reason);
    }

    public function isWritable(): bool {
        return !$this->ended && !$this->hungUp;
    }

    public function write(string $data): bool {
        if (!$this->isWritable()) {
            return false;
        }

        $this->sent = true;
        $this->body .= $data;

        return true;
    }

    public function end(?string $data = null): bool {
        if ($this->ended) {
            return false;
        }

        if ($data !== null && $data !== '') {
            $this->write($data);
        }
        $this->sent = $this->ended = true;

        return true;
    }

    public function close(): bool {
        if ($this->ended) {
            return false;
        }
        $this->closed = $this->ended = true;

        return true;
    }

    public function sendfile(string $fileName, int $offset = 0, int $length = 0): bool {
        $contents = file_get_contents($fileName, false, null, $offset, $length > 0 ? $length : null);

        return $this->end($contents === false ? '' : $contents);
    }

    public function redirect(string $url, int $status_code = 302): bool {
        $this->status($status_code);
        $this->header('Location', $url);

        return $this->end();
    }

    /**
     * A Set-Cookie header written by hand, as SessionManager writes a partitioned cookie.
     */
    private function takeSetCookie(string $header): void {
        [$pair] = explode(';', $header, 2);
        if (!str_contains($pair, '=')) {
            return;
        }

        [$name, $value] = explode('=', $pair, 2);
        $expired = preg_match('/;\s*max-age=(-?\d+)/i', $header, $m) === 1 && (int) $m[1] <= 0;
        $this->cookies[trim($name)] = $value === '' || $expired ? null : urldecode($value);
    }
}
