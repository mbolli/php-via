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

    public function header(string $key, mixed $value, bool $ucwords = true): bool {
        $this->headers[$key] = (string) $value;

        return true;
    }

    public function status(int $statusCode, string $reason = ''): bool {
        $this->statusCode = $statusCode;

        return true;
    }

    public function end(mixed $data = null): bool {
        $this->body = (string) $data;
        $this->ended = true;

        return true;
    }
}
