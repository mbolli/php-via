<?php

declare(strict_types=1);

namespace PhpVia\Website;

use PhpVia\Website\Highlight\DatastarHtmlLanguage;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Themes\CssTheme;
use Tempest\Highlight\WebTheme;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Twig extension providing server-side syntax highlighting via tempest/highlight.
 *
 * Usage in templates:
 *   {{ highlight(code, 'php') }}             — raw string → highlighted <pre> block
 *   {{ html_content | highlight_blocks }}    — scans HTML for <pre><code class="language-xxx"> and highlights
 */
final class SyntaxHighlightExtension extends AbstractExtension {
    private readonly Highlighter $highlighter;

    public function __construct() {
        $this->highlighter = new Highlighter(new CssTheme());
        $this->highlighter->addLanguage(new DatastarHtmlLanguage());
    }

    public function getFunctions(): array {
        return [
            new TwigFunction('highlight', $this->highlight(...), ['is_safe' => ['html']]),
        ];
    }

    public function getFilters(): array {
        return [
            new TwigFilter('highlight', $this->highlight(...), ['is_safe' => ['html']]),
            new TwigFilter('highlight_blocks', $this->highlightBlocks(...), ['is_safe' => ['html']]),
        ];
    }

    public function highlight(string $code, string $language = 'php'): string {
        $theme = $this->highlighter->getTheme();
        $parsed = $this->highlighter->parse($code, $language);

        if ($theme instanceof WebTheme) {
            return $theme->preBefore($this->highlighter) . $parsed . $theme->preAfter($this->highlighter);
        }

        return '<pre data-lang="' . htmlspecialchars($language) . '">' . $parsed . '</pre>';
    }

    /**
     * Replace <pre><code class="language-xxx">…</code></pre> blocks with highlighted output.
     */
    public function highlightBlocks(string $html): string {
        return preg_replace_callback(
            '/<pre[^>]*>\s*<code(?:[^>]*\bclass="[^"]*\blanguage-([a-z0-9_+-]+)[^"]*"[^>]*|[^>]*)>(.*?)<\/code>\s*<\/pre>/si',
            function (array $matches): string {
                $language = $matches[1] ?: 'txt';
                $rawCode = html_entity_decode($matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return $this->highlight($rawCode, $language);
            },
            $html,
        ) ?? $html;
    }
}
