<?php

declare(strict_types=1);

namespace PhpVia\Website\Twig;

use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

final class CodeTokenParser extends AbstractTokenParser {
    public function getTag(): string {
        return 'code';
    }

    public function parse(Token $token): CodeNode {
        $lineno = $token->getLine();
        $stream = $this->parser->getStream();

        $language = $stream->expect(Token::STRING_TYPE)->getValue();

        // Optional: gutter or gutter=N
        $gutter = null;
        if ($stream->test(Token::NAME_TYPE, 'gutter')) {
            $stream->next();
            if ($stream->test(Token::OPERATOR_TYPE, '=')) {
                $stream->next();
                $gutter = (int) $stream->expect(Token::NUMBER_TYPE)->getValue();
            } else {
                $gutter = 1;
            }
        }

        $stream->expect(Token::BLOCK_END_TYPE);

        $body = $this->parser->subparse(fn(Token $t): bool => $t->test('endcode'), true);
        $stream->expect(Token::BLOCK_END_TYPE);

        return new CodeNode($body, $language, $gutter, $lineno);
    }
}
