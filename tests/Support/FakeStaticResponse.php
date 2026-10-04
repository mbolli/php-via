<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSwoole\Http\Response;

/**
 * Captures header()/status()/end() calls instead of writing to a live socket.
 *
 * OpenSwoole\Http\Response is unusable when constructed directly (its
 * header()/status()/end() methods no-op with a warning: "http response is
 * unavailable" once there is no real connection behind it) — overriding them
 * here lets RequestHandler::handleRequest() be exercised end-to-end in tests.
 */
final class FakeStaticResponse extends Response {
    /** @var array<string, string> */
    public array $headers = [];

    public int $statusCode = 200;

    public string $body = '';

    public bool $ended = false;

    /** The file passed to sendfile(), whose contents become the body. */
    public ?string $sentFile = null;

    /** @var array<string, null|string> */
    public array $cookies = [];

    public function header(string $key, mixed $value, bool $ucwords = true): bool {
        $this->headers[$key] = (string) $value;

        return true;
    }

    public function cookie(string $key, ?string $value = null, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httpOnly = false, string $sameSite = '', string $priority = ''): bool {
        $this->cookies[$key] = $value;

        return true;
    }

    public function status(int $statusCode, string $reason = ''): bool {
        $this->statusCode = $statusCode;

        return true;
    }

    public function isWritable(): bool {
        return !$this->ended;
    }

    public function end(mixed $data = null): bool {
        $this->body = (string) $data;
        $this->ended = true;

        return true;
    }

    public function sendfile(string $fileName, int $offset = 0, int $length = 0): bool {
        $this->sentFile = $fileName;

        return $this->end((string) file_get_contents($fileName));
    }
}
