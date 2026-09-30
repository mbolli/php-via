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

    public function __construct(
        public readonly Context $context,
        public readonly string $contextId,
        public readonly Response $response,
    ) {
        $this->fd = $response->fd;
    }
}
