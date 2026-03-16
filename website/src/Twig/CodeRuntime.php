<?php

declare(strict_types=1);

namespace PhpVia\Website\Twig;

use Mbolli\TempestHighlightDatastar\Html\DatastarHtmlLanguage;
use Mbolli\TempestHighlightDatastar\Twig\DatastarTwigLanguage;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Themes\CssTheme;
use Twig\Extension\RuntimeExtensionInterface;

final class CodeRuntime implements RuntimeExtensionInterface {
    private readonly Highlighter $highlighter;

    public function __construct() {
        $this->highlighter = new Highlighter(new CssTheme());
        $this->highlighter->addLanguage(new DatastarHtmlLanguage());
        $this->highlighter->addLanguage(new DatastarTwigLanguage());
    }

    public function highlight(string $code, string $language, ?int $gutter = null): string {
        $hl = $gutter !== null ? $this->highlighter->withGutter($gutter) : $this->highlighter;
        $parsed = $hl->parse($code, $language);

        return '<pre data-lang="' . htmlspecialchars($language, ENT_QUOTES) . '"><code>' . $parsed . '</code></pre>';
    }
}
