<?php

declare(strict_types=1);

namespace PhpVia\Website\Highlight;

use Tempest\Highlight\Languages\Html\HtmlLanguage;

/**
 * Extended HTML language with Datastar data-* attribute and expression highlighting.
 */
final class DatastarHtmlLanguage extends HtmlLanguage {
    #[\Override]
    public function getPatterns(): array {
        return [
            new DatastarAttributePattern(),
            ...parent::getPatterns(),
        ];
    }

    #[\Override]
    public function getInjections(): array {
        return [
            new DatastarExpressionInjection(),
            ...parent::getInjections(),
        ];
    }
}
