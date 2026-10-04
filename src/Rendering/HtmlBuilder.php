<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Rendering;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Text;
use Mbolli\PhpVia\Context;

/**
 * Builds complete HTML documents from rendered content.
 *
 * Handles shell template processing, head/foot includes,
 * and signal injection for initial page loads.
 */
class HtmlBuilder {
    /** A data-signals attribute that declares via_ctx, keyed (data-signals:via_ctx) or in its value */
    private const string VIA_CTX_SIGNAL = '/<[a-z][^>]*\sdata-signals(?:[:-]via_ctx\b|[^\s=>]*\s*=\s*(?:"[^"]*via_ctx[^"]*"|\'[^\']*via_ctx[^\']*\'))/i';

    /** The default shell's Live Signals panel, shown in dev mode only */
    private const string DEV_SIDEBAR = <<<'HTML'
        <aside class="debug-sidebar">
                    <h3>🔍 Live Signals</h3>
                    <pre data-json-signals></pre>
                </aside>
        HTML;

    /** A script whose URL names Datastar, such as via_foot's or a bundle of the page's own */
    private const string DATASTAR_SCRIPT = '/<script\b[^>]*\ssrc\s*=\s*["\']?[^"\'\s>]*datastar/i';

    /** @var array<string, array{0: string, 1: string}> Shell contents by path, with the mtime and size read in dev mode */
    private array $shells = [];

    /** @var array<int, string> */
    private array $headIncludes = [];

    /** @var array<int, string> */
    private array $footIncludes = [];

    /** @var array<string, true> Shell paths warned about, and outside dev mode the ones found sound */
    private array $checkedShells = [];

    /** @var array<string, true> Routes whose full document or import maps were warned about, or found sound outside dev mode */
    private array $checkedDocuments = [];

    /**
     * @param null|\Closure(string, string, ?Context): void $logger Receives level, message and context
     */
    public function __construct(
        private ?string $shellTemplate = null,
        private ?\Closure $logger = null,
        private bool $devMode = false,
    ) {}

    /**
     * Add content to the <head> section.
     *
     * @param string ...$elements HTML elements to append
     */
    public function appendToHead(string ...$elements): void {
        foreach ($elements as $element) {
            $this->headIncludes[] = $element;
        }
    }

    /**
     * Add content before closing </body> tag.
     *
     * @param string ...$elements HTML elements to append
     */
    public function appendToFoot(string ...$elements): void {
        foreach ($elements as $element) {
            $this->footIncludes[] = $element;
        }
    }

    /**
     * Build complete HTML document from rendered content.
     *
     * A view that renders its own `<html>` document is completed by injectIntoDocument(); any other
     * view is placed into the shell template. Warns once per shell and once per full-document route
     * without via_head, or with via_head and a second Datastar, and in dev mode once with via_head and
     * no Datastar, and once per route with more than one import map.
     *
     * @param string  $content   Rendered HTML content
     * @param Context $context   Context for signal injection
     * @param string  $contextId Context ID for initial signals
     * @param string  $basePath  Base path for URLs
     *
     * @return string Complete HTML document
     */
    public function buildDocument(string $content, Context $context, string $contextId, string $basePath): string {
        if (stripos($content, '<html') !== false) {
            $route = $context->getRoute();
            if (!isset($this->checkedDocuments[$route . "\0head"])) {
                $this->checkBootstrap($this->checkedDocuments, $route . "\0head", $content, $this->hasViaHead($content), $context, [
                    "The document rendered for {$route} has no via_head: write {{ via_head() }} (Twig) or \$c->viaHead() right after <meta charset>, and via_foot before </body>, or the page never opens its SSE stream.",
                    "The document rendered for {$route} has via_head but loads no Datastar: write {{ via_foot() }} (Twig) or \$c->viaFoot() before </body>.",
                    "The document rendered for {$route} loads a Datastar script of its own next to via_head's import map, which maps 'datastar' to Config::getDatastarUrl(), so modules that import 'datastar' start a second Datastar engine: write {{ via_foot() }} (Twig) or \$c->viaFoot() in place of the script.",
                ]);
            }

            $this->checkNonce($this->checkedDocuments, $route . "\0nonce", $content, $context, "The document rendered for {$route} has no data-nonce on <html>, so Datastar runs expressions through Function(), which a CSP with a nonce blocks: write <html{{ via_html_attrs() }}> (Twig).");

            return $this->checkImportMaps($this->injectIntoDocument($content, $context, initial: true), $context);
        }

        [$headIncludes, $footIncludes] = $this->includes($context);
        $seedMeta = $this->seedMeta($context);
        if ($seedMeta !== null) {
            array_unshift($headIncludes, $seedMeta);
        }

        $signalsJson = json_encode([
            'via_ctx' => $contextId,
            '_disconnected' => false,
        ]);

        // {{ name }} and {{ name.id }} for every signal, by the name given to signal()
        $replacements = [];
        foreach ($context->getSignalFactory()->getNamedSignals() as $name => $signal) {
            $replacements['{{ ' . $name . ' }}'] = htmlspecialchars($this->encodeJson($signal->getValue()) ?? 'null', ENT_QUOTES, 'UTF-8');
            $replacements['{{ ' . $name . '.id }}'] = $signal->id();
        }

        // Per-context shell overrides the configured one
        $shellPath = $context->getShellTemplate() ?? $this->shellTemplate ?? __DIR__ . '/shell.html';
        $shell = $this->loadShell($shellPath);

        if ($shell === false) {
            throw new \RuntimeException("Failed to load shell template from: {$shellPath}");
        }

        $replacements = [
            '{{ signals_json }}' => $signalsJson,
            '{{ context_id }}' => $contextId,
            '{{ base_path }}' => $basePath,
            '{{ via_html_attrs }}' => str_contains($shell, '{{ via_html_attrs }}') ? Bootstrap::htmlAttributes($context->cspNonce()) : '',
            '{{ via_head }}' => str_contains($shell, '{{ via_head }}') ? $context->viaHead() : '',
            '{{ head_content }}' => implode("\n", $headIncludes),
            '{{ content }}' => $content,
            '{{ foot_content }}' => implode("\n", $footIncludes),
            '{{ via_foot }}' => str_contains($shell, '{{ via_foot }}') ? $context->viaFoot() : '',
            '{{ dev_sidebar }}' => $this->devMode ? self::DEV_SIDEBAR : '',
            '{{ styles }}' => '',
        ] + $replacements;

        // strtr() replaces in one pass, so placeholder text inside the content or a value stays as is
        $html = strtr($shell, $replacements);
        if (!isset($this->checkedShells[$shellPath])) {
            $this->checkBootstrap($this->checkedShells, $shellPath, $html, str_contains($shell, '{{ via_head }}'), $context, [
                "Shell {$shellPath} has no {{ via_head }}: write it right after <meta charset>, and {{ via_foot }} before </body>, in place of a copied SSE bootstrap, Datastar script and import map.",
                "Shell {$shellPath} has {{ via_head }} but loads no Datastar: write {{ via_foot }} before </body>.",
                "Shell {$shellPath} loads a Datastar script of its own next to via_head's import map, which maps 'datastar' to Config::getDatastarUrl(), so modules that import 'datastar' start a second Datastar engine: write {{ via_foot }} in place of the script.",
            ]);
        }
        $this->checkNonce($this->checkedShells, $shellPath . "\0nonce", $html, $context, "Shell {$shellPath} has no data-nonce on <html>, so Datastar runs expressions through Function(), which a CSP with a nonce blocks: write <html{{ via_html_attrs }}>.");

        return $this->checkImportMaps($html, $context);
    }

    /**
     * Complete a view that renders its own `<html>` document.
     *
     * Head and foot includes the document does not already contain go before the first `</head>`
     * and the last `</body>`. On the initial render a `via_ctx` meta (only when no data-signals
     * attribute of the document declares via_ctx) and a `data-signals__ifmissing` seed with the values the first sync sends go right after
     * via_head's via_ctx meta, or without via_head right after the opening `<head>` tag, ahead of the document's
     * SSE bootstrap. The bootstrap and `datastar.js` are left to the document.
     *
     * @param bool $initial True for the initial page render, false for an SSE update render
     */
    public function injectIntoDocument(string $html, Context $context, bool $initial): string {
        [$headIncludes, $footIncludes] = $this->includes($context);

        $signals = [];
        if ($initial) {
            if (preg_match(self::VIA_CTX_SIGNAL, $html) !== 1) {
                $signals[] = '<meta data-signals="' . htmlspecialchars(
                    (string) json_encode(['via_ctx' => $context->getId(), '_disconnected' => false], JSON_UNESCAPED_SLASHES),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '">';
            }
            $seedMeta = $this->seedMeta($context);
            if ($seedMeta !== null) {
                $signals[] = $seedMeta;
            }
        }
        $head = $this->missingFrom($html, $headIncludes);
        $foot = $this->missingFrom($html, $footIncludes);

        if ($signals !== [] || $head !== []) {
            $headEnd = stripos($html, '</head>');
            if ($headEnd === false) {
                $this->log('debug', 'Full-document view has no </head>; head content not injected', $context);
            } else {
                if ($head !== []) {
                    $html = substr_replace($html, implode("\n", $head) . "\n", $headEnd, 0);
                }
                // Datastar applies attributes in document order, so via_ctx must precede a bootstrap @get in <head>:
                // right after via_head's via_ctx meta, before its SSE connect, else right after <head>
                if ($signals !== []) {
                    $at = $headEnd;
                    if (preg_match('/<[a-z][^>]*\s' . Bootstrap::MARKER . '(?=[\s=>])[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] < $headEnd) {
                        $at = $m[0][1] + \strlen($m[0][0]);
                    } elseif (preg_match('/<head(?=[\s>])[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1 && $m[0][1] < $headEnd) {
                        $at = $m[0][1] + \strlen($m[0][0]);
                    }
                    $html = substr_replace($html, "\n" . implode("\n", $signals), $at, 0);
                }
            }
        }

        if ($foot !== []) {
            $bodyEnd = strripos($html, '</body>');
            if ($bodyEnd === false) {
                $this->log('debug', 'Full-document view has no </body>; foot content not injected', $context);
            } else {
                $html = substr_replace($html, implode("\n", $foot) . "\n", $bodyEnd, 0);
            }
        }

        return $html;
    }

    /**
     * In dev mode, warn once per route about a page update whose top level has an element without an id or text:
     * Datastar patches a page view's top-level elements by id, and drops the others.
     *
     * @internal called by Via::decorateUpdate() for an update render of a page view that is no whole document
     */
    public function checkRootIds(string $content, Context $context): void {
        $key = $context->getRoute() . "\0ids";
        if (!$this->devMode || isset($this->checkedDocuments[$key]) || trim($content) === '' || !class_exists(HTMLDocument::class)) {
            return;
        }
        $this->checkedDocuments[$key] = true;

        $body = HTMLDocument::createFromString('<!DOCTYPE html><body>' . $content, LIBXML_NOERROR)->body;
        if ($body === null) {
            return;
        }
        foreach ($body->childNodes as $node) {
            $what = match (true) {
                $node instanceof Element && ($node->getAttribute('id') ?? '') === '' => 'a top-level <' . $node->localName . '> without an id',
                $node instanceof Text && trim($node->textContent) !== '' => 'text outside any element',
                default => null,
            };
            if ($what !== null) {
                $this->log('warning', "The view of {$context->getRoute()} renders {$what}, so Datastar drops its updates (PatchElementsNoTargetsFound in the browser console): give the view one root element with an id, such as <div id=\"counter\">...</div>.", $context);

                return;
            }
        }
    }

    /**
     * Warn once per key about a page without via_head, or with a Datastar script other than via_foot's
     * next to via_head's import map, and in dev mode with via_head and no Datastar script, which a
     * bundle whose URL does not name Datastar would set off. Outside dev mode a sound page is not
     * checked again; in dev mode the next render checks the edited template.
     *
     * @param array<string, true>                    $checked  keys warned about or found sound
     * @param array{0: string, 1: string, 2: string} $messages for no via_head, no Datastar and a second Datastar
     */
    private function checkBootstrap(array &$checked, string $key, string $html, bool $hasViaHead, Context $context, array $messages): void {
        $problem = null;
        if (!$hasViaHead) {
            $problem = $messages[0];
        } elseif (!str_contains($html, $context->viaFoot())) {
            if (preg_match(self::DATASTAR_SCRIPT, $html) !== 1) {
                $problem = $this->devMode ? $messages[1] : null;
            } elseif (str_contains($context->viaHead(), '<script type="importmap"')) {
                $problem = $messages[2];
            }
        }

        if ($problem !== null) {
            $this->warnOnce($checked, $key, $problem, $context);
        } elseif (!$this->devMode) {
            $checked[$key] = true;
        }
    }

    /**
     * In dev mode, warn once per key about a page whose request carries a CSP nonce and whose <html> has no data-nonce.
     *
     * @param array<string, true> $checked keys warned about
     */
    private function checkNonce(array &$checked, string $key, string $html, Context $context, string $message): void {
        if (!$this->devMode || isset($checked[$key]) || ($context->cspNonce() ?? '') === '') {
            return;
        }
        if (preg_match('/<html\b[^>]*\sdata-nonce\s*=/i', $html) !== 1) {
            $this->warnOnce($checked, $key, $message, $context);
        }
    }

    /**
     * Whether the document carries via_head's marker on a tag.
     */
    private function hasViaHead(string $html): bool {
        return preg_match('/<[a-z][^>]*\s' . Bootstrap::MARKER . '[\s=>]/i', $html) === 1;
    }

    /**
     * In dev mode, warn once per route about a page with more than one import map, of which a
     * browser uses only the first.
     */
    private function checkImportMaps(string $html, Context $context): string {
        $key = $context->getRoute() . "\0maps";
        if ($this->devMode && !isset($this->checkedDocuments[$key]) && preg_match_all('/<script\s[^>]*type=["\']?importmap\b/i', $html) > 1) {
            $this->warnOnce($this->checkedDocuments, $key, "The page of {$context->getRoute()} has more than one import map, and a browser may use only the first: add your entries with Config::withImportMap() and leave the writing to via_head.", $context);
        }

        return $html;
    }

    /**
     * @param array<string, true> $warned keys already warned about
     */
    private function warnOnce(array &$warned, string $key, string $message, Context $context): void {
        if (!isset($warned[$key])) {
            $warned[$key] = true;
            $this->log('warning', $message, $context);
        }
    }

    /**
     * Read a shell template once per path; in dev mode, again whenever its mtime or size changes.
     */
    private function loadShell(string $path): false|string {
        $version = '';
        if ($this->devMode) {
            // Under the file hooks PHP keeps stat() results across writes, which would hide an edit.
            clearstatcache(true, $path);
            $stat = @stat($path);
            $version = $stat === false ? '' : $stat['mtime'] . ':' . $stat['size'];
        }

        $cached = $this->shells[$path] ?? null;
        if ($cached !== null && $cached[0] === $version) {
            return $cached[1];
        }

        $shell = file_get_contents($path);
        if ($shell !== false) {
            $this->shells[$path] = [$version, $shell];
        }

        return $shell;
    }

    /**
     * Global includes followed by the context's own.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function includes(Context $context): array {
        return [
            array_values(array_merge($this->headIncludes, $context->getContextHeadIncludes())),
            array_values(array_merge($this->footIncludes, $context->getContextFootIncludes())),
        ];
    }

    /**
     * @param list<string> $includes
     *
     * @return list<string>
     */
    private function missingFrom(string $html, array $includes): array {
        $missing = [];
        foreach (array_unique($includes) as $include) {
            if (!str_contains($html, $include)) {
                $missing[] = $include;
            }
        }

        return $missing;
    }

    private function seedMeta(Context $context): ?string {
        $values = $context->getPatchManager()->initialSignalValues();
        if ($values === []) {
            return null;
        }

        $json = $this->encodeJson($values);
        if ($json === null) {
            $this->log('warning', 'Initial signal values are not JSON-encodable; the page is not seeded', $context);

            return null;
        }

        return '<meta data-signals__ifmissing="' . htmlspecialchars($json, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * JSON for a value that ends up in a Datastar attribute.
     */
    private function encodeJson(mixed $value): ?string {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            return null;
        }

        // Datastar compiles attributes as JS and splits statements on `;` with a pattern that misreads `\\"`.
        // `@` stays escaped for layouts on Datastar before 1.0.4, which rewrote `@name(` inside strings.
        return strtr($json, ['@' => '\u0040', ';' => '\u003b', '\\\\' => '\u005c']);
    }

    private function log(string $level, string $message, Context $context): void {
        if ($this->logger !== null) {
            ($this->logger)($level, $message, $context);
        }
    }
}
