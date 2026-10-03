<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Testing;

use Mbolli\PhpVia\PatchMode;

/**
 * Reads the Datastar events of an SSE stream back into patches, as the browser's Datastar receives them.
 *
 * Keep-alive comments carry no patch. A script from execScript() is an element patch: Datastar sends it
 * as a <script> appended to body.
 *
 * @internal
 *
 * @phpstan-type WirePatch array{type: 'elements', html: string, selector: null|string, mode: PatchMode}|array{type: 'signals', signals: array<array-key, mixed>, onlyIfMissing: bool}
 */
final class SseReader {
    private string $buffer = '';

    /**
     * @return list<WirePatch> the patches of the events $text completes
     */
    public function read(string $text): array {
        $this->buffer .= $text;
        $patches = [];
        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $patch = self::parse(substr($this->buffer, 0, $end));
            $this->buffer = substr($this->buffer, $end + 2);
            if ($patch !== null) {
                $patches[] = $patch;
            }
        }

        return $patches;
    }

    /**
     * @return null|WirePatch
     */
    private static function parse(string $event): ?array {
        $type = null;

        /** @var array<string, list<string>> $data */
        $data = [];
        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'event: ')) {
                $type = substr($line, 7);
            } elseif (str_starts_with($line, 'data: ')) {
                $parts = explode(' ', substr($line, 6), 2);
                $data[$parts[0]][] = $parts[1] ?? '';
            }
        }

        if ($type === 'datastar-patch-elements') {
            return [
                'type' => 'elements',
                'html' => implode("\n", $data['elements'] ?? []),
                'selector' => $data['selector'][0] ?? null,
                'mode' => PatchMode::tryFrom($data['mode'][0] ?? 'outer') ?? PatchMode::Outer,
            ];
        }

        if ($type === 'datastar-patch-signals') {
            $signals = json_decode(implode("\n", $data['signals'] ?? []), true);

            return [
                'type' => 'signals',
                'signals' => \is_array($signals) ? $signals : [],
                'onlyIfMissing' => ($data['onlyIfMissing'][0] ?? 'false') === 'true',
            ];
        }

        return null;
    }
}
