<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http;

use starfederation\datastar\Consts;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\enums\NamespaceType;
use starfederation\datastar\events\EventInterface;
use starfederation\datastar\events\ExecuteScript;
use starfederation\datastar\events\PatchElements;
use starfederation\datastar\events\PatchSignals;
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
 *
 * patchElements(), patchSignals() and executeScript() write the same text as the SDK, but prefix
 * the lines of the payload with one str_replace() instead of a call per line. The SDK's events
 * still parse the options, and tests/Unit/Http/SwooleSSEGeneratorTest.php compares the bytes.
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

        $event = new PatchElements(self::normalizeLineEnds($elements), $options);

        $lines = '';
        // The SDK tests the selector with empty(), so '0' is left out as well.
        if ($event->selector !== '' && $event->selector !== '0') {
            $lines .= 'data: ' . Consts::SELECTOR_DATALINE_LITERAL . $event->selector . "\n";
        }
        if ($event->mode !== Consts::DEFAULT_ELEMENT_PATCH_MODE) {
            $lines .= 'data: ' . Consts::MODE_DATALINE_LITERAL . $event->mode->value . "\n";
        }
        if ($event->namespace !== Consts::DEFAULT_NAMESPACE) {
            $lines .= 'data: ' . Consts::NAMESPACE_DATALINE_LITERAL . $event->namespace->value . "\n";
        }
        if ($event->useViewTransition !== Consts::DEFAULT_ELEMENTS_USE_VIEW_TRANSITIONS) {
            $lines .= 'data: ' . Consts::USE_VIEW_TRANSITION_DATALINE_LITERAL . $event->getBooleanAsString($event->useViewTransition) . "\n";
            if ($event->viewTransitionSelector !== '') {
                $lines .= 'data: ' . Consts::VIEW_TRANSITION_SELECTOR_DATALINE_LITERAL . $event->viewTransitionSelector . "\n";
            }
        }

        return self::format($event, $lines, Consts::ELEMENTS_DATALINE_LITERAL, $event->elements);
    }

    /**
     * @param array<mixed>|string  $signals
     * @param array<string, mixed> $options
     */
    public function patchSignals(array|string $signals, array $options = []): string {
        $event = new PatchSignals(\is_string($signals) ? self::normalizeLineEnds($signals) : $signals, $options);

        $lines = '';
        if ($event->onlyIfMissing !== Consts::DEFAULT_PATCH_SIGNALS_ONLY_IF_MISSING) {
            $lines .= 'data: ' . Consts::ONLY_IF_MISSING_DATALINE_LITERAL . $event->getBooleanAsString($event->onlyIfMissing) . "\n";
        }
        $data = \is_array($event->signals) ? (string) json_encode($event->signals) : $event->signals;

        return self::format($event, $lines, Consts::SIGNALS_DATALINE_LITERAL, $data);
    }

    /** @param array<string, mixed> $options */
    public function executeScript(string $script, array $options = []): string {
        $event = new ExecuteScript(self::normalizeLineEnds($script), $options);

        $lines = 'data: ' . Consts::SELECTOR_DATALINE_LITERAL . "body\n"
            . 'data: ' . Consts::MODE_DATALINE_LITERAL . ElementPatchMode::Append->value . "\n";

        $elements = '<script';
        foreach ($event->attributes as $key => $value) {
            $elements .= ' ' . $key . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }
        if ($event->autoRemove) {
            $elements .= ' data-effect="el.remove()"';
        }
        $elements .= '>' . $event->script . '</script>';

        return self::format($event, $lines, Consts::ELEMENTS_DATALINE_LITERAL, $elements);
    }

    protected function sendEvent(EventInterface $event): string {
        return $event->getOutput();
    }

    /**
     * The event as EventTrait::getOutput() writes it, with $lines as the data lines before the payload.
     */
    private static function format(EventInterface $event, string $lines, string $literal, string $data): string {
        $options = $event->getOptions();
        $out = 'event: ' . $event->getEventType()->value . "\n";
        if (isset($options['eventId'])) {
            $out .= 'id: ' . $options['eventId'] . "\n";
        }
        if (isset($options['retryDuration'])) {
            $out .= 'retry: ' . $options['retryDuration'] . "\n";
        }
        $prefix = 'data: ' . $literal;

        return $out . $lines . $prefix . str_replace("\n", "\n" . $prefix, trim($data)) . "\n\n";
    }

    private static function normalizeLineEnds(string $text): string {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
