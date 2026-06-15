<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\DevBar;

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;

/**
 * Builds the Dev Bar overlay block and injects it into a rendered page.
 *
 * The overlay is a single custom element plus its stylesheet/script. It carries
 * everything the front-end needs at boot as data-attributes: base path, the
 * owning context id + route, the write-enabled flag, and the signal manifest.
 * Injection happens before the last </body> so it survives both the shell
 * template and full Twig <html> pages.
 */
final class Injector {
    public function __construct(private Config $config) {}

    public function inject(string $html, Context $context): string {
        // Idempotent: never inject twice (the update path re-asserts the overlay
        // on full-page morphs and must not duplicate it).
        if (stripos($html, '<via-dev-bar') !== false) {
            return $html;
        }

        $base = $this->config->getBasePath();

        // Boot config rides in a single NON-`data-` attribute. Datastar only
        // scans `data-*` attributes, so `via-config` is invisible to it — using
        // `data-signals` here would make Datastar load the manifest as real
        // signals (with numeric keys), corrupting the page's signal store.
        $config = json_encode([
            'base' => $base,
            'context' => $context->getId(),
            'route' => $context->getRoute(),
            'writes' => $this->config->isTracingWritesEnabled(),
            'signals' => SignalManifest::build($context),
        ]);
        if ($config === false) {
            $config = '{}';
        }

        $attr = htmlspecialchars($config, ENT_QUOTES, 'UTF-8');

        // A stable id lets idiomorph match the overlay across full-page morphs
        // and preserve the element (and its live component) in place.
        $block = "\n<link rel=\"stylesheet\" href=\"{$base}_via/devbar.css\">\n"
            . "<via-dev-bar id=\"via-dev-bar\" via-config='{$attr}'></via-dev-bar>\n"
            . "<script type=\"module\" src=\"{$base}_via/devbar.js\"></script>\n";

        $pos = strripos($html, '</body>');
        if ($pos === false) {
            return $html . $block;
        }

        return substr($html, 0, $pos) . $block . substr($html, $pos);
    }
}
