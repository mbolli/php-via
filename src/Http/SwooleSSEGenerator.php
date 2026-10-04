<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\enums\NamespaceType;
use starfederation\datastar\events\EventInterface;
use starfederation\datastar\ServerSentEventGenerator;

/**
 * OpenSwoole-compatible SSE generator that suppresses stdout output.
 *
 * The upstream Datastar SDK's sendEvent() echoes SSE data to stdout
 * (designed for PHP-FPM where stdout = browser). In OpenSwoole, stdout = terminal.
 * We use $response->write() instead, so the echo must be suppressed.
 *
 * The SDK splits data lines on LF only, while SSE also ends a line at CR. A CR in rendered
 * text would end the data line and let that text write its own fields and events, so CR
 * becomes LF here, as HTML and JavaScript parsing would treat it anyway.
 */
class SwooleSSEGenerator extends ServerSentEventGenerator {
    /**
     * @param array{
     *     selector?: null|string,
     *     mode?: null|ElementPatchMode|string,
     *     namespace?: null|NamespaceType|string,
     *     useViewTransition?: null|bool,
     *     viewTransitionSelector?: null|string,
     *     eventId?: null|string,
     *     retryDuration?: null|int,
     * } $options
     */
    public function patchElements(string $elements, array $options = []): string {
        foreach (['selector', 'viewTransitionSelector'] as $key) {
            if (isset($options[$key])) {
                $options[$key] = str_replace(["\r", "\n"], ' ', $options[$key]);
            }
        }

        return parent::patchElements(self::normalizeLineEnds($elements), $options);
    }

    /**
     * @param array<mixed>|string  $signals
     * @param array<string, mixed> $options
     */
    public function patchSignals(array|string $signals, array $options = []): string {
        return parent::patchSignals(\is_string($signals) ? self::normalizeLineEnds($signals) : $signals, $options);
    }

    /** @param array<string, mixed> $options */
    public function executeScript(string $script, array $options = []): string {
        return parent::executeScript(self::normalizeLineEnds($script), $options);
    }

    protected function sendEvent(EventInterface $event): string {
        return $event->getOutput();
    }

    private static function normalizeLineEnds(string $text): string {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
