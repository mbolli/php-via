<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;

/**
 * Builds complete HTML documents from rendered content.
 *
 * Handles shell template processing, head/foot includes,
 * and signal injection for initial page loads.
 */
class HtmlBuilder {
    /** @var array<int, string> */
    private array $headIncludes = [];

    /** @var array<int, string> */
    private array $footIncludes = [];

    public function __construct(private ?string $shellTemplate = null) {}

    /**
     * Add content to the <head> section.
     *
     * @param string ...$elements HTML elements to append
     */
    public function appendToHead(string ...$elements): void {
        foreach ($elements as $element) {
            $this->headIncludes[] = $element;
        }
    }

    /**
     * Add content before closing </body> tag.
     *
     * @param string ...$elements HTML elements to append
     */
    public function appendToFoot(string ...$elements): void {
        foreach ($elements as $element) {
            $this->footIncludes[] = $element;
        }
    }

    /**
     * Build complete HTML document from rendered content.
     *
     * @param string  $content   Rendered HTML content
     * @param Context $context   Context for signal injection
     * @param string  $contextId Context ID for initial signals
     *
     * @return string Complete HTML document
     */
    public function buildDocument(string $content, Context $context, string $contextId): string {
        $headContent = implode("\n", $this->headIncludes);
        $footContent = implode("\n", $this->footIncludes);

        // If it's a full page (already processed by processView), return it
        if (stripos($content, '<html') !== false) {
            return $content;
        }

        // Use the shell template for fragments
        $signalsJson = json_encode([
            'via_ctx' => $contextId,
            '_disconnected' => false,
        ]);

        // Build replacement arrays (base + signals)
        $replacements = [
            '{{ signals_json }}' => $signalsJson,
            '{{ context_id }}' => $contextId,
            '{{ head_content }}' => $headContent,
            '{{ content }}' => $content,
            '{{ foot_content }}' => $footContent,
        ];

        // Add signal replacements - extract signal name from ID for route-scoped signals
        // e.g., "embed" from route-scoped or "greeting_TAB123" from tab-scoped
        foreach ($context->getSignals() as $fullId => $signal) {
            // Get the base name (before underscore for tab-scoped signals)
            $baseName = strpos($fullId, '_') !== false
                ? substr($fullId, 0, strpos($fullId, '_'))
                : $fullId;

            // Support both {{ signalName }} for value and {{ signalName.id }} for ID
            $replacements['{{ ' . $baseName . ' }}'] = json_encode($signal->getValue());
            $replacements['{{ ' . $baseName . '.id }}'] = $signal->id();
        }

        // Simple template replacement for the shell
        $shellPath = $this->shellTemplate ?? __DIR__ . '/../../templates/shell.html';
        $shell = file_get_contents($shellPath);

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $shell
        );
    }
}
