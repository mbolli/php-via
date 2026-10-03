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

    test('throw in a template rendered outside a context', function (): void {
        $via = createVia((new Config())->withTemplateEngine(arrayTwig([])));

        expect(fn () => $via->getTwig()->createTemplate('{{ via_head() }}')->render([]))
            ->toThrow(RuntimeError::class, 'via_head() needs the page it renders for')
            ->and(fn () => $via->getTwig()->createTemplate('{{ via_foot() }}')->render(['via_foot' => '<script>']))
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
