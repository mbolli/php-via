<?php

declare(strict_types=1);

namespace PhpVia\Website;

use PhpVia\Website\Twig\CodeTokenParser;
use Twig\Extension\AbstractExtension;

final class SyntaxHighlightExtension extends AbstractExtension {
    public function getTokenParsers(): array {
        return [new CodeTokenParser()];
    }
}
