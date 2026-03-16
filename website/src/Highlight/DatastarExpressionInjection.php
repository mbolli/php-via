<?php

declare(strict_types=1);

namespace PhpVia\Website\Highlight;

use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Injection;
use Tempest\Highlight\IsInjection;

/**
 * Re-parses the value of every Datastar data-* attribute as a JavaScript
 * expression, giving $signals, @actions, etc., proper syntax colouring.
 */
final readonly class DatastarExpressionInjection implements Injection {
    use IsInjection;

    public function getPattern(): string {
        return 'data-[\w:.@-]+(?:__[\w.]+)*="(?<match>[^"]*)"';
    }

    public function parseContent(string $content, Highlighter $highlighter): string {
        if (mb_trim($content) === '') {
            return $content;
        }

        return $highlighter->parse($content, new DatastarExpressionLanguage());
    }
}
