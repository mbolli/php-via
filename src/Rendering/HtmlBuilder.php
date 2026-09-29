<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

use Mbolli\PhpVia\Context;

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

    /**
     * @param null|\Closure(string, string, ?Context): void $logger Receives level, message and context
     */
    public function __construct(
        private ?string $shellTemplate = null,
        private ?\Closure $logger = null,
    ) {}

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
     * A view that renders its own `<html>` document is completed by injectIntoDocument(); any other
     * view is placed into the shell template.
     *
     * @param string  $content   Rendered HTML content
     * @param Context $context   Context for signal injection
     * @param string  $contextId Context ID for initial signals
     * @param string  $basePath  Base path for URLs
     *
     * @return string Complete HTML document
     */
    public function buildDocument(string $content, Context $context, string $contextId, string $basePath): string {
        if (stripos($content, '<html') !== false) {
            return $this->injectIntoDocument($content, $context, initial: true);
        }

        [$headIncludes, $footIncludes] = $this->includes($context);
        $seedMeta = $this->seedMeta($context);
        if ($seedMeta !== null) {
            array_unshift($headIncludes, $seedMeta);
        }

        $signalsJson = json_encode([
            'via_ctx' => $contextId,
            '_disconnected' => false,
        ]);

        // {{ name }} and {{ name.id }} for every signal, by the name given to signal()
        $replacements = [];
        foreach ($context->getSignalFactory()->getNamedSignals() as $name => $signal) {
            $replacements['{{ ' . $name . ' }}'] = htmlspecialchars($this->encodeJson($signal->getValue()) ?? 'null', ENT_QUOTES, 'UTF-8');
            $replacements['{{ ' . $name . '.id }}'] = $signal->id();
        }

        $replacements = [
            '{{ signals_json }}' => $signalsJson,
            '{{ context_id }}' => $contextId,
            '{{ base_path }}' => $basePath,
            '{{ head_content }}' => implode("\n", $headIncludes),
            '{{ content }}' => $content,
            '{{ foot_content }}' => implode("\n", $footIncludes),
            '{{ styles }}' => '',
        ] + $replacements;

        // Per-context shell overrides the configured one
        $shellPath = $context->getShellTemplate() ?? $this->shellTemplate ?? __DIR__ . '/shell.html';
        $shell = file_get_contents($shellPath);

        if ($shell === false) {
            throw new \RuntimeException("Failed to load shell template from: {$shellPath}");
        }

        // strtr() replaces in one pass, so placeholder text inside the content or a value stays as is
        return strtr($shell, $replacements);
    }

    /**
     * Complete a view that renders its own `<html>` document.
     *
     * Head and foot includes the document does not already contain go before the first `</head>`
     * and the last `</body>`. On the initial render the head also gets a `via_ctx` meta (only when
     * the document has none) and a `data-signals__ifmissing` seed with the values the first sync
     * sends. The SSE bootstrap and `datastar.js` are left to the document.
     *
     * @param bool $initial True for the initial page render, false for an SSE update render
     */
    public function injectIntoDocument(string $html, Context $context, bool $initial): string {
        [$headIncludes, $footIncludes] = $this->includes($context);

        $head = [];
        if ($initial) {
            if (stripos($html, 'via_ctx') === false) {
                $head[] = '<meta data-signals="' . htmlspecialchars(
                    (string) json_encode(['via_ctx' => $context->getId(), '_disconnected' => false], JSON_UNESCAPED_SLASHES),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '">';
            }
            $seedMeta = $this->seedMeta($context);
            if ($seedMeta !== null) {
                $head[] = $seedMeta;
            }
        }
        $head = array_merge($head, $this->missingFrom($html, $headIncludes));
        $foot = $this->missingFrom($html, $footIncludes);

        if ($head !== []) {
            $headEnd = stripos($html, '</head>');
            if ($headEnd === false) {
                $this->log('debug', 'Full-document view has no </head>; head content not injected', $context);
            } else {
                $html = substr_replace($html, implode("\n", $head) . "\n", $headEnd, 0);
            }
        }

        if ($foot !== []) {
            $bodyEnd = strripos($html, '</body>');
            if ($bodyEnd === false) {
                $this->log('debug', 'Full-document view has no </body>; foot content not injected', $context);
            } else {
                $html = substr_replace($html, implode("\n", $foot) . "\n", $bodyEnd, 0);
            }
        }

        return $html;
    }

    /**
     * Global includes followed by the context's own.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function includes(Context $context): array {
        return [
            array_values(array_merge($this->headIncludes, $context->getContextHeadIncludes())),
            array_values(array_merge($this->footIncludes, $context->getContextFootIncludes())),
        ];
    }

    /**
     * @param list<string> $includes
     *
     * @return list<string>
     */
    private function missingFrom(string $html, array $includes): array {
        $missing = [];
        foreach (array_unique($includes) as $include) {
            if (!str_contains($html, $include)) {
                $missing[] = $include;
            }
        }

        return $missing;
    }

    private function seedMeta(Context $context): ?string {
        $values = $context->getPatchManager()->initialSignalValues();
        if ($values === []) {
            return null;
        }

        $json = $this->encodeJson($values);
        if ($json === null) {
            $this->log('warning', 'Initial signal values are not JSON-encodable; the page is not seeded', $context);

            return null;
        }

        return '<meta data-signals__ifmissing="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '">';
    }

    private function encodeJson(mixed $value): ?string {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);

        return $json === false ? null : $json;
    }

    private function log(string $level, string $message, Context $context): void {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
