<?php

declare(strict_types=1);

namespace PhpVia\Website\Twig;

use Twig\Compiler;
use Twig\Node\Node;

final class CodeNode extends Node {
    public function __construct(Node $body, string $language, ?int $gutter, int $lineno) {
        parent::__construct(
            ['body' => $body],
            ['language' => $language, 'gutter' => $gutter],
            $lineno,
        );
    }

    public function compile(Compiler $compiler): void {
        $language = $this->getAttribute('language');
        $gutter   = $this->getAttribute('gutter');

        $compiler
            ->addDebugInfo($this)
            ->write('ob_start();' . "\n")
            ->subcompile($this->getNode('body'))
            ->write('$_code_block = trim(ob_get_clean());' . "\n")
            ->write('echo $this->env->getRuntime(\PhpVia\Website\Twig\CodeRuntime::class)->highlight($_code_block, ')
            ->repr($language)
            ->raw(', ')
            ->repr($gutter)
            ->raw(');' . "\n");
    }
}
