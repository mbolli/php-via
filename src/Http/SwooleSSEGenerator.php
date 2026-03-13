<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use starfederation\datastar\events\EventInterface;
use starfederation\datastar\ServerSentEventGenerator;

/**
 * Swoole-compatible SSE generator that suppresses stdout output.
 *
 * The upstream Datastar SDK's sendEvent() echoes SSE data to stdout
 * (designed for PHP-FPM where stdout = browser). In Swoole, stdout = terminal.
 * We use $response->write() instead, so the echo must be suppressed.
 */
class SwooleSSEGenerator extends ServerSentEventGenerator {
    protected function sendEvent(EventInterface $event): string {
        return $event->getOutput();
    }
}
