<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSwoole\Http\Request;

/**
 * A same-origin Datastar action POST that ActionHandler::handleAction() accepts without a socket.
 */
final class FakeActionRequest extends Request {
    private string $body;

    /**
     * @param array<string, mixed> $signals
     */
    public function __construct(string $actionId, array $signals) {
        $this->server = [
            'request_uri' => '/_action/' . $actionId,
            'request_method' => 'POST',
            'remote_addr' => '127.0.0.1',
        ];
        $this->header = [
            'host' => 'localhost:3000',
            'origin' => 'http://localhost:3000',
            'content-type' => 'application/json',
        ];
        $this->cookie = [];
        $this->body = (string) json_encode($signals);
    }

    public function getContent(): false|string {
        return $this->body;
    }

    public function rawContent(): false|string {
        return $this->body;
    }
}
