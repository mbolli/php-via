<?php

declare(strict_types=1);

namespace PhpVia\Website;

use Composer\InstalledVersions;
use PhpVia\Website\Twig\CodeNode;
use PhpVia\Website\Twig\CodeRuntime;
use PhpVia\Website\Twig\CodeTokenParser;
use Twig\Extension\AbstractExtension;

final class SyntaxHighlightExtension extends AbstractExtension {
    public function getTokenParsers(): array {
        return [new CodeTokenParser()];
    }

    /**
     * Compiled templates hold the highlighted HTML, so they go stale when the highlighting code or a
     * Composer package changes. Twig's auto_reload compiles them again after this time.
     */
    public function getLastModified(): int {
        $files = [
            __FILE__,
            (string) (new \ReflectionClass(CodeNode::class))->getFileName(),
            (string) (new \ReflectionClass(CodeRuntime::class))->getFileName(),
            (string) (new \ReflectionClass(CodeTokenParser::class))->getFileName(),
            \dirname((string) (new \ReflectionClass(InstalledVersions::class))->getFileName()) . '/installed.php',
        ];

        $lastModified = 0;
        foreach ($files as $file) {
            if (is_file($file)) {
                $lastModified = max($lastModified, (int) filemtime($file));
            }
        }

        return $lastModified;
    }
}
