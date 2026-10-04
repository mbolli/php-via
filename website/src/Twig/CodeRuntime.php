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

    /**
     * The highlighted block with an sb-copy-button that copies the code as written, without the diff marks.
     */
    public function highlight(string $code, string $language, ?int $gutter = null): string {
        $hl = $gutter !== null ? $this->highlighter->withGutter($gutter) : $this->highlighter;
        $parsed = $hl->parse($code, $language);

        return '<div class="code-block"><pre data-lang="' . htmlspecialchars($language, ENT_QUOTES) . '"><code>' . $parsed . '</code></pre>'
            . '<sb-copy-button value="' . htmlspecialchars(self::plain($code), ENT_QUOTES) . '" label="Copy the code"></sb-copy-button></div>';
    }

    /**
     * The code without tempest/highlight's diff marks: {- deleted -} goes, {+ added +} stays.
     */
    public static function plain(string $code): string {
        // A deletion that fills its lines takes their line break along
        $code = (string) preg_replace('/^\{-(?!-)(?:(?!-\}).)*-\}(?!\})\R/ms', '', $code);
        $code = (string) preg_replace('/(?<!\{)\{-(?!-)(?:(?!-\}).)*-\}(?!\})/s', '', $code);

        return (string) preg_replace('/\{\+((?:(?!\+\}).)*)\+\}/s', '$1', $code);
    }
}
