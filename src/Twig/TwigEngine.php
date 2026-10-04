<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Twig;

use Mbolli\PhpVia\Rendering\Html;
use Mbolli\PhpVia\Rendering\TemplateEngine;
use Mbolli\PhpVia\Signal;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\Runtime\EscaperRuntime;
use Twig\TwigFunction;

/**
 * Twig templates for php-via, registered with Config::withTemplateEngine(new TwigEngine($dir)).
 *
 * Templates are autoescaped as HTML and strict about undefined variables. They get the functions
 * bind(signal, prop), dump(), via_html_attrs(), via_head() and via_foot(), and the basePath global, which
 * Via sets to Config::getBasePath() so that renders outside a context, such as a notFound() page, can read
 * it. There via_head() and via_foot() write what needs no page, the import map and the Datastar script,
 * without a nonce, and via_html_attrs() nothing, so such a page can use the site's layout.
 * environment() is the Twig Environment, for extensions, runtime loaders, globals and templates
 * held as strings.
 */
final class TwigEngine implements TemplateEngine {
    private Environment $twig;

    /**
     * @param string       $templateDir directory that template names are relative to
     * @param false|string $cacheDir    directory for compiled templates; false compiles them in memory
     *
     * @throws \LogicException when twig/twig is not installed
     */
    public function __construct(string $templateDir, false|string $cacheDir = false) {
        if (!class_exists(Environment::class)) {
            throw new \LogicException('Twig templates need twig/twig, which is not installed: run composer require twig/twig');
        }

        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => $cacheDir,
            'auto_reload' => true,
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);

        $this->twig->addGlobal('basePath', '/');
        // Via sets these to the parts that need no page; declared now, since Twig takes no new global once it renders.
        $this->twig->addGlobal('via_html_attrs', null);
        $this->twig->addGlobal('via_head', null);
        $this->twig->addGlobal('via_foot', null);
        $this->twig->addFunction(new TwigFunction(
            'bind',
            static fn (Signal $signal, ?string $prop = null): Markup => new Markup($signal->bind($prop), 'UTF-8'),
        ));
        $this->twig->addFunction(new TwigFunction(
            'dump',
            static fn (mixed ...$vars): string => '<pre>' . htmlspecialchars(print_r($vars, true), ENT_QUOTES, 'UTF-8') . '</pre>',
            ['is_safe' => ['html']],
        ));
        foreach (['via_html_attrs', 'via_head', 'via_foot'] as $name) {
            $this->twig->addFunction(new TwigFunction(
                $name,
                static fn (array $context): string => self::documentPart($context, $name),
                ['needs_context' => true, 'is_safe' => ['html']],
            ));
        }
        $this->twig->getRuntime(EscaperRuntime::class)->addSafeClass(Html::class, ['html']);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data, ?string $block = null): string {
        if ($block !== null) {
            return $this->twig->load($template)->renderBlock($block, $data);
        }

        return $this->twig->render($template, $data);
    }

    public function supportsBlocks(): bool {
        return true;
    }

    /**
     * The Twig Environment that renders the templates.
     */
    public function environment(): Environment {
        return $this->twig;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function documentPart(array $context, string $name): string {
        $html = $context[$name] ?? null;
        if (!$html instanceof Html) {
            throw new RuntimeError("{$name}() needs the page it renders for: render this template with \$c->view() or \$c->render(), not through the Twig environment directly.");
        }

        return (string) $html;
    }
}
