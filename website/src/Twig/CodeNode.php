<?php

declare(strict_types=1);

namespace PhpVia\Website\Twig;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\CaptureNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\TextNode;

/**
 * A code block whose body is plain text is highlighted when Twig compiles the template, so a render only
 * yields the stored HTML. A body with Twig expressions in it is highlighted on every render.
 */
#[YieldReady]
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
        $gutter = $this->getAttribute('gutter');
        $text = self::constantText($this->getNode('body'));

        $compiler->addDebugInfo($this);

        if ($text !== null) {
            $html = $compiler->getEnvironment()->getRuntime(CodeRuntime::class)->highlight(trim($text), $language, $gutter);
            $compiler->write('yield ')->string($html)->raw(";\n");

            return;
        }

        $capture = new CaptureNode($this->getNode('body'), $this->getTemplateLine());
        $capture->setAttribute('raw', true);

        $compiler
            ->write('$_code_block = ')
            ->subcompile($capture)
            ->raw("\n")
            ->write('yield $this->env->getRuntime(\PhpVia\Website\Twig\CodeRuntime::class)->highlight(trim($_code_block), ')
            ->repr($language)
            ->raw(', ')
            ->repr($gutter)
            ->raw(");\n")
        ;
    }

    /**
     * The body's text when it is only text, null when it has anything that runs at render time.
     */
    private static function constantText(Node $node): ?string {
        if ($node instanceof TextNode) {
            return $node->getAttribute('data');
        }
        if ($node::class !== Nodes::class && $node::class !== Node::class) {
            return null;
        }

        $text = '';
        foreach ($node as $child) {
            $part = self::constantText($child);
            if ($part === null) {
                return null;
            }
            $text .= $part;
        }

        return $text;
    }
}
