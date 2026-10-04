<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;
use Twig\Error\RuntimeError;

/*
 * via_head and via_foot: the tags that connect a page to php-via, the same from Context::viaHead()
 * and viaFoot(), the shell placeholders and the Twig functions.
 */

/**
 * Offset of $needle in $html, failing the test when it is missing.
 */
function offsetOf(string $html, string $needle): int {
    $at = strpos($html, $needle);
    expect($at)->toBeInt("'{$needle}' is missing");

    return (int) $at;
}

describe('Context::viaHead()', function (): void {
    test('declares via_ctx first, then the import map, the SSE connect and the close beacon', function (): void {
        $ctx = new Context('/_/head1', '/', createVia((new Config())->withDatastarRocket()));
        $head = $ctx->viaHead();

        $signals = offsetOf($head, 'data-signals=');
        expect($signals)->toBeLessThan(offsetOf($head, '<script type="importmap">'))
            ->and(offsetOf($head, '<script type="importmap">'))->toBeLessThan(offsetOf($head, "@get('/_sse')"))
            ->and(offsetOf($head, "@get('/_sse')"))->toBeLessThan(offsetOf($head, "navigator.sendBeacon('/_session/close', '/_/head1')"))
            ->and($head)->toStartWith('<meta data-via-head data-signals=\'{"via_ctx":"/_/head1","_disconnected":false}\'>')
            ->and($head)->toContain('data-on:datastar-fetch="')
            ->and($head)->toContain(<<<'HTML'
                data-on-signal-patch="@get('/_sse')" data-on-signal-patch-filter="{include: /^_via_reconnect$/}"
                HTML)
        ;
    });

    test('writes no import map with the plain bundle and no entries', function (): void {
        $head = (new Context('/_/head2', '/', createVia()))->viaHead();

        expect($head)->not->toContain('importmap')
            ->and(substr_count($head, '<meta '))->toBe(3)
        ;
    });

    test('uses the base path for the stream and the beacon', function (): void {
        $head = (new Context('/_/head3', '/', createVia((new Config())->withBasePath('/app'))))->viaHead();

        expect($head)->toContain("@get('/app/_sse')")
            ->and($head)->toContain("navigator.sendBeacon('/app/_session/close', '/_/head3')")
        ;
    });

    test('escapes a context id for the JSON, the JavaScript string and the attribute', function (): void {
        $id = "/p/{x}_/a'b\"<c>&";
        $head = (new Context($id, '/p/{x}', createVia()))->viaHead();
        preg_match("/data-signals='([^']*)'/", $head, $signals);
        preg_match('/data-init="([^"]*)"/', $head, $init);

        expect(json_decode($signals[1], true, flags: JSON_THROW_ON_ERROR)['via_ctx'])->toBe($id)
            ->and(html_entity_decode($init[1], ENT_QUOTES, 'UTF-8'))->toContain("'/p/{x}_/a\\'b\"<c>&'")
            ->and($head)->not->toContain('<c>')
        ;
    });

    test('puts the nonce from the via.csp_nonce request attribute on every tag of via_head and via_foot', function (): void {
        $ctx = new Context('/_/head4', '/', createVia((new Config())->withDatastarRocket()));
        $ctx->setRequestAttributes(['via.csp_nonce' => 'r4nd"om']);
        $head = $ctx->viaHead();
        $foot = $ctx->viaFoot();

        preg_match_all('/<(?:meta|script)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', $head . $foot, $tags);
        expect($tags[0])->toHaveCount(5);
        foreach ($tags[0] as $tag) {
            expect($tag)->toContain(' nonce="r4nd&quot;om"');
        }
    });

    test('writes no nonce without one, and refuses a nonce that is not a string', function (): void {
        $ctx = new Context('/_/head5', '/', createVia());

        expect($ctx->viaHead() . $ctx->viaFoot())->not->toContain('nonce');

        $ctx->setRequestAttributes(['via.csp_nonce' => 42]);
        expect(fn () => $ctx->viaHead())->toThrow(LogicException::class, "'via.csp_nonce' request attribute")
            ->and(fn () => $ctx->viaFoot())->toThrow(LogicException::class, 'got int')
        ;
    });

    test('a component returns its page\'s, with the page\'s nonce', function (): void {
        $page = new Context('/_/head6', '/', createVia());
        $page->setRequestAttributes(['via.csp_nonce' => 'n6']);
        $widget = null;
        $page->component(function (Context $w) use (&$widget): void {
            $widget = $w;
            $w->view(fn (): string => '<p>w</p>');
        }, 'widget');

        expect($widget)->toBeInstanceOf(Context::class)
            ->and($widget->viaHead())->toBe($page->viaHead())
            ->and($widget->viaFoot())->toBe($page->viaFoot())
            ->and($page->viaHead())->toContain('"via_ctx":"/_/head6"')
        ;
    });
});

describe('Context::viaFoot()', function (): void {
    test('loads Datastar from the versioned URL', function (): void {
        $via = createVia();

        expect((new Context('/_/foot1', '/', $via))->viaFoot())
            ->toBe('<script type="module" src="' . $via->getConfig()->getDatastarUrl() . '"></script>')
        ;
    });
});

describe('via_head() and via_foot() in Twig templates', function (): void {
    test('print what viaHead() and viaFoot() return, unescaped, as do the via_head and via_foot variables', function (): void {
        $via = createVia((new Config())->withDatastarRocket()->withTemplateEngine(arrayTwig([
            'layout.html.twig' => '{{ via_head() }}|{{ via_foot() }}|{{ via_head }}',
        ])));
        $ctx = new Context('/_/twig1', '/', $via);
        $ctx->setRequestAttributes(['via.csp_nonce' => 'n1']);

        expect($ctx->render('layout.html.twig'))->toBe($ctx->viaHead() . '|' . $ctx->viaFoot() . '|' . $ctx->viaHead())
            ->and($ctx->viaHead())->toContain('nonce="n1"')
        ;
    });

    test('work on a context without a page request', function (): void {
        $via = createVia((new Config())->withTemplateEngine(arrayTwig(['layout.html.twig' => '{{ via_head() }}'])));
        $ctx = new Context('/_/twig2', '/', $via);

        expect($ctx->render('layout.html.twig'))->toBe($ctx->viaHead())->not->toContain('nonce');
    });

    test('write the import map and the Datastar script outside a context, as on a notFound() page', function (): void {
        $config = (new Config())->withImportMap(['chart' => '/js/chart.js'])->withBasePath('/app');
        $via = createVia($config->withTemplateEngine(arrayTwig([
            '404.html.twig' => '<head>{{ via_head() }}</head><body>{{ basePath }}{{ via_foot() }}</body>',
        ])));
        $html = $via->getTwig()->render('404.html.twig');

        expect($html)->toBe('<head>' . $via->getSettings()->importMapTag() . '</head><body>/app/<script type="module" src="'
            . htmlspecialchars($config->getDatastarUrl()) . '"></script></body>')
            ->and($html)->toContain('"chart":"/js/chart.js"')->not->toContain('via_ctx')
            ->and(createVia((new Config())->withTemplateEngine(arrayTwig([])))->getTwig()->createTemplate('[{{ via_head() }}]')->render([]))->toBe('[]')
        ;
    });

    test('throw outside a context when php-via did not set them up', function (): void {
        $twig = arrayTwig([])->environment();

        expect(fn () => $twig->createTemplate('{{ via_head() }}')->render([]))
            ->toThrow(RuntimeError::class, 'via_head() needs the page it renders for')
            ->and(fn () => createVia((new Config())->withTemplateEngine(arrayTwig([])))->getTwig()->createTemplate('{{ via_foot() }}')->render(['via_foot' => '<script>']))
            ->toThrow(RuntimeError::class, 'via_foot() needs the page it renders for')
        ;
    });

    test('a full-document view that writes via_head() gets no via_ctx injected and no warning', function (): void {
        $via = new Via((new Config())->withDevMode(true)->withLogLevel('warn')->withTemplateEngine(arrayTwig([
            'doc.html.twig' => '<!DOCTYPE html><html><head><meta charset="UTF-8">{{ via_head() }}</head><body><main id="m">x</main>{{ via_foot() }}</body></html>',
        ])));
        $ctx = new Context('/_/twig3', '/doc', $via);
        $ctx->view('doc.html.twig');

        ob_start();
        $html = $via->buildHtmlDocument($ctx);
        $logs = (string) ob_get_clean();

        expect(substr_count($html, 'via_ctx'))->toBe(1)
            ->and($html)->toContain('<meta charset="UTF-8">' . $ctx->viaHead())
            ->and($logs)->not->toContain('via_head')
        ;
    });
});

describe('via_html_attrs: data-nonce on <html>, which Datastar needs under a nonce policy', function (): void {
    test('the default shell writes it when the page request carries a nonce, and nothing without one', function (): void {
        $via = createVia();
        $page = new Context('/_/attrs1', '/', $via);
        $page->view(fn (): string => '<main id="m">x</main>');
        $plain = new Context('/_/attrs2', '/', $via);
        $plain->view(fn (): string => '<main id="m">x</main>');
        $page->setRequestAttributes(['via.csp_nonce' => 'n"7']);

        expect($via->buildHtmlDocument($page))->toContain("<!DOCTYPE html>\n<html data-nonce=\"n&quot;7\">\n")
            ->and($via->buildHtmlDocument($plain))->toContain("<!DOCTYPE html>\n<html>\n")
        ;
    });

    test('a custom shell has the {{ via_html_attrs }} placeholder', function (): void {
        $shell = tempnam(sys_get_temp_dir(), 'shell');
        file_put_contents($shell, '<!DOCTYPE html><html lang="en"{{ via_html_attrs }}><head><meta charset="UTF-8">{{ via_head }}</head><body>{{ content }}{{ via_foot }}</body></html>');

        try {
            $via = createVia((new Config())->withShellTemplate($shell));
            $page = new Context('/_/attrs3', '/', $via);
            $page->setRequestAttributes(['via.csp_nonce' => 'n8']);
            $page->view(fn (): string => '<main id="m">x</main>');

            expect($via->buildHtmlDocument($page))->toStartWith('<!DOCTYPE html><html lang="en" data-nonce="n8"><head>');
        } finally {
            unlink($shell);
        }
    });

    test('a Twig layout has via_html_attrs(), which an update render leaves empty', function (): void {
        $via = createVia((new Config())->withTemplateEngine(arrayTwig([
            'doc.html.twig' => '<!DOCTYPE html><html lang="en"{{ via_html_attrs() }}><head><meta charset="UTF-8">{{ via_head() }}</head><body><main id="m">{{ via_html_attrs }}</main>{{ via_foot() }}</body></html>',
        ])));
        $page = new Context('/_/attrs4', '/doc', $via);
        $page->setRequestAttributes(['via.csp_nonce' => 'n9']);
        $page->view('doc.html.twig');

        expect($page->renderView())->toStartWith('<!DOCTYPE html><html lang="en" data-nonce="n9"><head>')
            ->and($page->renderView())->toContain('<main id="m"> data-nonce="n9"</main>')
            ->and($page->renderView(isUpdate: true))->toStartWith('<!DOCTYPE html><html lang="en"><head>')
            ->and($via->getTwig()->createTemplate('<html{{ via_html_attrs() }}>')->render([]))->toBe('<html>')
        ;
    });

    test('in dev mode, a page with a nonce whose <html> has no data-nonce is warned about once', function (): void {
        $shell = tempnam(sys_get_temp_dir(), 'shell');
        file_put_contents($shell, '<!DOCTYPE html><html><head><meta charset="UTF-8">{{ via_head }}</head><body>{{ content }}{{ via_foot }}</body></html>');

        try {
            $via = new Via((new Config())->withDevMode(true)->withLogLevel('warn')->withShellTemplate($shell));
            $logs = '';
            foreach (['a', 'b'] as $n) {
                $page = new Context("/_/attrs5{$n}", '/', $via);
                $page->setRequestAttributes(['via.csp_nonce' => 'n10']);
                $page->view(fn (): string => '<main id="m">x</main>');
                ob_start();
                $via->buildHtmlDocument($page);
                $logs .= (string) ob_get_clean();
            }

            expect(substr_count($logs, 'has no data-nonce on <html>'))->toBe(1)
                ->and($logs)->toContain('write <html{{ via_html_attrs }}>')
            ;
        } finally {
            unlink($shell);
        }
    });
});
