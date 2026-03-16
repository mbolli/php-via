<?php

declare(strict_types=1);

namespace PhpVia\Website\Highlight;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * Highlights Datastar action calls: @get(), @post(), @put(), etc.
 */
final class DatastarActionPattern implements Pattern {
    use IsPattern;

    public function getPattern(): string {
        return '(?<match>@[a-zA-Z]\w*)(?=\()';
    }

    public function getTokenType(): TokenTypeEnum {
        return TokenTypeEnum::KEYWORD;
    }
}
