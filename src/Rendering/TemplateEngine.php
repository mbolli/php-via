<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

/**
 * Renders the template views of an app: view('page.html.twig', ...) and Context::render().
 *
 * Register one with Config::withTemplateEngine(); php-via ships Mbolli\PhpVia\Twig\TwigEngine. A
 * context passes the view's data merged with its named signals (Signal objects by name), its named
 * actions (Action objects by camelCased name), '_via' (the names of both), 'contextId',
 * 'currentRoute', 'basePath', 'via_head' and 'via_foot' (see Context::viaHead() and viaFoot()), and
 * 'via_html_attrs', the attributes for the page's <html> element: data-nonce under a CSP nonce. Those
 * three are Html values, trusted markup that the engine prints unescaped, so an autoescaping engine
 * marks the Html class safe.
 */
interface TemplateEngine {
    /**
     * @param string               $template Template name
     * @param array<string, mixed> $data     Template variables
     * @param null|string          $block    Render only this block, for SSE updates. Never set when supportsBlocks() is false.
     */
    public function render(string $template, array $data, ?string $block = null): string;

    /**
     * Whether render() can render a single block of a template, which view(block: ...) needs.
     */
    public function supportsBlocks(): bool;
}
