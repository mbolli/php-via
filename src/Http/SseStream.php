<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use Mbolli\PhpVia\Context;
use OpenSwoole\Http\Response;

/**
 * One running page SSE stream, as SseHandler tracks it for wakes and heartbeats.
 *
 * @internal
 */
final class SseStream {
    /** Set when the stream's connection closed; the loop ends at its next check. */
    public bool $clientGone = false;

    public readonly int $fd;

    /** @var array{int, int} what the last check of the cookie found, see SessionTokens::stillNames() */
    public array $cookieCheck = [-1, 0];

    /**
     * @param null|string $cookie  SessionTokens::key() of the session cookie the stream connected with, null for a
     *                             context that belongs to no session
     * @param null|string $session the session that cookie has to keep naming for the stream to go on
     */
    public function __construct(
        public readonly Context $context,
        public readonly string $contextId,
        public readonly Response $response,
        public readonly ?string $cookie = null,
        public readonly ?string $session = null,
    ) {
        $this->fd = $response->fd;
    }
}
