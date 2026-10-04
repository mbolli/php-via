<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Rendering\HtmlBuilder;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Tracing\Tracer;

function fullDocument(string $head = '', string $body = '<main id="app">x</main>'): string {
    return "<!DOCTYPE html>\n<html><head><title>t</title>{$head}</head><body>{$body}</body></html>";
}

/**
 * Decoded value of the first `<meta {attribute}="...">` in the document.
 *
 * @return null|array<string, mixed>
 */
function metaSignals(string $html, string $attribute): ?array {
    if (preg_match('/<meta ' . preg_quote($attribute, '/') . '="([^"]*)">/', $html, $m) !== 1) {
        return null;
    }

    return json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<array<string, mixed>>
 */
function drainPatches(Context $ctx): array {
    $patches = [];
    while (($patch = $ctx->getPatchManager()->getPatch()) !== null) {
        $patches[] = $patch;
    }

    return $patches;
}

/**
 * @return list<string>
 */
function elementPatches(Context $ctx): array {
    return array_values(array_map(
        fn (array $p): string => $p['content'],
        array_filter(drainPatches($ctx), fn (array $p): bool => $p['type'] === 'elements'),
    ));
}

afterEach(function (): void {
    Tracer::setCurrent(null);
});

describe('full-document views: initial render', function (): void {
    test('global and per-context head/foot includes land before </head> and </body>', function (): void {
        $via = createVia();
        $via->appendToHead('<link rel="stylesheet" href="/global.css">');
        $via->appendToFoot('<script src="/global.js"></script>');
        $ctx = new Context('/_/doc1', '/doc', $via);
        $ctx->appendToHead('<meta name="page" content="doc">');
        $ctx->appendToFoot('<script src="/page.js"></script>');
        $ctx->view(fn () => fullDocument());

        $html = $via->buildHtmlDocument($ctx);

        $headEnd = stripos($html, '</head>');
        $bodyEnd = strripos($html, '</body>');
        expect(strpos($html, '/global.css'))->toBeInt()->toBeLessThan($headEnd)
            ->and(strpos($html, 'name="page"'))->toBeInt()->toBeLessThan($headEnd)
            ->and(strpos($html, '/global.js'))->toBeInt()->toBeGreaterThan($headEnd)->toBeLessThan($bodyEnd)
            ->and(strpos($html, '/page.js'))->toBeInt()->toBeGreaterThan($headEnd)->toBeLessThan($bodyEnd)
        ;
    });

    test('via_ctx and the changed signal values are seeded', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc2', '/doc', $via);
        $count = $ctx->signal(41, 'count');
        $label = $ctx->signal('it\'s "quoted" <b>', 'label');
        $ctx->view(fn () => fullDocument());

        $html = $via->buildHtmlDocument($ctx);

        expect(metaSignals($html, 'data-signals'))->toBe(['via_ctx' => '/_/doc2', '_disconnected' => false])
            ->and(metaSignals($html, 'data-signals__ifmissing'))->toBe([$count->id() => 41, $label->id() => 'it\'s "quoted" <b>'])
            ->and($html)->not->toContain('<b>')
        ;
    });

    test('scoped signals are seeded and a TAB signal marked synced is not', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc3', '/doc', $via);
        $ctx->addScope('room:doc3');
        $shared = $ctx->signal(7, 'shared', 'room:doc3');
        $page = $ctx->signal('overview', 'page');
        $page->markSynced();
        $ctx->view(fn () => fullDocument());

        $seed = metaSignals($via->buildHtmlDocument($ctx), 'data-signals__ifmissing');

        expect($seed)->toBe([$shared->id() => 7]);
    });

    test('a scoped signal marked synced is still seeded, as every sync sends it', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc13', '/doc', $via);
        $ctx->addScope('room:doc13');
        $shared = $ctx->signal('live', 'shared', 'room:doc13');
        $shared->markSynced();
        $ctx->view(fn () => fullDocument());

        $seed = metaSignals($via->buildHtmlDocument($ctx), 'data-signals__ifmissing');

        expect($seed)->toBe([$shared->id() => 'live']);
    });

    test('the seed escapes what Datastar would compile as code', function (): void {
        $values = ['foo@bar(baz)', '@media(max-width: 600px)', 'C:\\temp\\', 'color:red;', 'a\\"b', 'grüße'];
        $via = createVia();
        $ctx = new Context('/_/doc14', '/doc', $via);
        $ids = [];
        foreach ($values as $i => $value) {
            $ids[] = $ctx->signal($value, "v{$i}")->id();
        }
        $ctx->view(fn () => fullDocument());

        $html = $via->buildHtmlDocument($ctx);
        preg_match('/<meta data-signals__ifmissing="([^"]*)">/', $html, $m);
        $json = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');

        expect($json)->not->toContain('@')
            ->and($json)->not->toContain(';')
            ->and($json)->not->toContain('\\\\')
            ->and(mb_check_encoding($json, 'ASCII'))->toBeTrue()
            ->and(metaSignals($html, 'data-signals__ifmissing'))->toBe(array_combine($ids, $values))
        ;
    });

    test('via_ctx and the seed come before the layout\'s SSE bootstrap in <head>', function (): void {
        $via = createVia();
        $via->appendToHead('<link rel="stylesheet" href="/global.css">');
        $ctx = new Context('/_/doc17', '/doc', $via);
        $ctx->signal(1, 'count');
        $bootstrap = '<meta data-on-interval__duration.15s.leading="@get(\'/_sse\')">';
        $ctx->view(fn () => "<!DOCTYPE html>\n<html lang=\"en\"><head data-x=\"1\"><title>t</title>{$bootstrap}</head><body></body></html>");

        $html = $via->buildHtmlDocument($ctx);

        $boot = strpos($html, $bootstrap);
        expect(strpos($html, '<head data-x="1">'))->toBeLessThan(strpos($html, 'via_ctx'))
            ->and(strpos($html, 'via_ctx'))->toBeLessThan($boot)
            ->and(strpos($html, '__ifmissing'))->toBeLessThan($boot)
            ->and(strpos($html, '/global.css'))->toBeGreaterThan($boot)->toBeLessThan(stripos($html, '</head>'))
        ;
    });

    test('the seed goes right after via_head\'s via_ctx meta, behind <meta charset> and ahead of the SSE connect', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc18', '/doc', $via);
        $ctx->signal(5, 'count');
        $ctx->view(fn () => "<!DOCTYPE html>\n<html><head><meta charset=\"UTF-8\">" . $ctx->viaHead() . '<title>t</title></head><body></body></html>');

        $html = $via->buildHtmlDocument($ctx);
        $seed = strpos($html, '__ifmissing');

        expect(metaSignals($html, 'data-signals__ifmissing'))->toBe([$ctx->getSignal('count')?->id() => 5])
            ->and($html)->toContain('<meta charset="UTF-8"><meta data-via-head ')
            ->and(strpos($html, 'data-via-head'))->toBeLessThan($seed)
            ->and($seed)->toBeLessThan(strpos($html, '_sse'))
            ->and(substr_count($html, 'via_ctx'))->toBe(1)
        ;
    });

    test('component signals are seeded with the page', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc12', '/doc', $via);
        $inner = null;
        $render = $ctx->component(function (Context $k) use (&$inner): void {
            $inner = $k->signal('on', 'mode');
            $k->view(fn () => '<span>c</span>');
        }, 'cmp');
        $ctx->view(fn () => fullDocument('', $render()));

        $seed = metaSignals($via->buildHtmlDocument($ctx), 'data-signals__ifmissing');

        expect($inner)->not->toBeNull()
            ->and($seed)->toBe([$inner->id() => 'on'])
        ;
    });

    test('seeding does not mark anything synced', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc4', '/doc', $via);
        $count = $ctx->signal(1, 'count');
        $ctx->view(fn () => fullDocument());

        $via->buildHtmlDocument($ctx);

        expect($count->hasChanged())->toBeTrue();
    });

    test('a layout that already seeds via_ctx is not given a second one', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc5', '/doc', $via);
        $own = '<meta data-signals=\'{"via_ctx":"/_/doc5","_disconnected":false}\'>';
        $ctx->view(fn () => fullDocument($own));

        $html = $via->buildHtmlDocument($ctx);

        expect(substr_count($html, 'via_ctx'))->toBe(1);
    });

    test('a layout that seeds via_ctx another way is not given a second one', function (string $own): void {
        $via = createVia();
        $ctx = new Context('/_/doc5', '/doc', $via);
        $ctx->view(fn () => fullDocument($own));

        $html = $via->buildHtmlDocument($ctx);

        expect(substr_count($html, 'via_ctx'))->toBe(1);
    })->with([
        'entity-encoded' => ['<meta data-signals="{&quot;via_ctx&quot;:&quot;/_/doc5&quot;}">'],
        'keyed attribute' => ['<meta data-signals:via_ctx="\'/_/doc5\'">'],
        'if missing' => ['<meta data-signals__ifmissing=\'{"via_ctx":"/_/doc5"}\'>'],
    ]);

    test('a page that only mentions via_ctx still gets the via_ctx meta', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc5', '/doc', $via);
        // nfsen-ng's list requests post only via_ctx; the text is not a via_ctx signal.
        $body = '<button data-on:click="@post(\'/_action/x\', {filterSignals: {include: /^via_ctx$/}})">x</button>'
            . '<p>Send via_ctx in every action body.</p>';
        $ctx->view(fn () => fullDocument('', $body));

        $html = $via->buildHtmlDocument($ctx);

        expect(metaSignals($html, 'data-signals'))->toBe(['via_ctx' => '/_/doc5', '_disconnected' => false]);
    });

    test('an include the layout already contains is not duplicated', function (): void {
        $via = createVia();
        $tag = '<link rel="stylesheet" href="/global.css">';
        $via->appendToHead($tag);
        $ctx = new Context('/_/doc6', '/doc', $via);
        $ctx->view(fn () => fullDocument($tag));

        expect(substr_count($via->buildHtmlDocument($ctx), $tag))->toBe(1);
    });

    test('a document without </head> is returned without head content and logs why', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc7', '/doc', $via);
        $ctx->appendToHead('<link rel="stylesheet" href="/global.css">');
        $ctx->signal(1, 'count');
        $doc = '<html><body><main id="app">x</main></body></html>';
        $logged = [];
        $builder = new HtmlBuilder(null, function (string $level, string $message) use (&$logged): void {
            $logged[] = [$level, $message];
        });

        expect($builder->injectIntoDocument($doc, $ctx, initial: true))->toBe($doc)
            ->and($logged)->toBe([['debug', 'Full-document view has no </head>; head content not injected']])
        ;
    });
});

describe('full-document views: update render', function (): void {
    test('includes are re-added to the update HTML, with via_ctx and an empty seed', function (): void {
        $via = createVia();
        $via->appendToHead('<link rel="stylesheet" href="/global.css">');
        $ctx = new Context('/_/doc8', '/doc', $via);
        $ctx->appendToFoot('<script src="/page.js"></script>');
        $ctx->signal(1, 'count');
        $ctx->view(fn () => fullDocument());

        $ctx->sync();
        $elements = array_values(array_filter(drainPatches($ctx), fn (array $p): bool => $p['type'] === 'elements'));

        expect($elements)->toHaveCount(1);
        $html = $elements[0]['content'];
        expect($html)->toContain('/global.css')
            ->and($html)->toContain('/page.js')
            ->and(metaSignals($html, 'data-signals'))->toBe(['via_ctx' => '/_/doc8', '_disconnected' => false])
            ->and(metaSignals($html, 'data-signals__ifmissing'))->toBe([])
        ;
    });

    test('an update has the head tags of the initial render in their order, so the morph leaves via_head\'s connect alone', function (string $layout): void {
        $via = createVia();
        $ctx = new Context('/_/doc16', '/doc', $via);
        $ctx->signal(1, 'count');
        $ctx->view(fn (): string => match ($layout) {
            'via_head' => '<!DOCTYPE html><html><head><meta charset="utf-8">' . $ctx->viaHead() . '<title>t</title></head><body><main id="m">x</main>' . $ctx->viaFoot() . '</body></html>',
            'none' => fullDocument(),
        });
        $headTags = static function (string $html): array {
            $head = substr($html, 0, (int) stripos($html, '</head>'));
            preg_match_all('/<([a-z]+)((?:\s+[a-z][\w:.-]*)*)/i', $head, $m, PREG_SET_ORDER);

            return array_map(static fn (array $tag): string => $tag[0], $m);
        };

        $initial = $via->buildHtmlDocument($ctx);
        $ctx->sync();
        $update = elementPatches($ctx)[0];

        expect(metaSignals($initial, 'data-signals__ifmissing'))->not->toBe([])
            ->and($headTags($update))->toBe($headTags($initial))
        ;
    })->with(['via_head', 'none']);

    test('an update leaves the CSP nonce off via_head and via_foot, where the browser hid it', function (): void {
        $via = createVia((new Config())->withImportMap(['app' => '/app.js']));
        $ctx = new Context('/_/doc17', '/doc', $via);
        $ctx->setRequestAttributes(['via.csp_nonce' => 'n1']);
        $ctx->view(fn (): string => '<!DOCTYPE html><html><head><meta charset="utf-8">' . $ctx->viaHead() . '</head><body><main id="m">x</main>' . $ctx->viaFoot() . '</body></html>');

        $initial = $via->buildHtmlDocument($ctx);
        $ctx->sync();
        $update = elementPatches($ctx)[0];

        expect(substr_count($initial, 'nonce="n1"'))->toBe(5)
            ->and($update)->toContain('<script type="importmap"')
            ->and($update)->not->toContain('nonce=')
            ->and($ctx->viaHead())->toContain('nonce="n1"')
        ;
    });

    test('update includes keep their place: head before </head>, foot before </body> and the Dev Bar', function (): void {
        $via = createVia((new Config())->withDevMode()->withDevBar(true));
        $owned = '<link rel="stylesheet" href="/owned.css">';
        $via->appendToHead($owned);
        $via->appendToHead('<link rel="stylesheet" href="/global.css">');
        $via->appendToFoot('<script src="/global.js"></script>');
        $ctx = new Context('/_/doc15', '/doc', $via);
        $ctx->view(fn () => fullDocument($owned));

        $initial = $via->buildHtmlDocument($ctx);
        $ctx->sync();
        $update = elementPatches($ctx)[0];

        foreach ([$initial, $update] as $html) {
            $headEnd = stripos($html, '</head>');
            expect(substr_count($html, $owned))->toBe(1)
                ->and(strpos($html, '/global.css'))->toBeInt()->toBeLessThan($headEnd)
                ->and(strpos($html, '/global.js'))->toBeInt()->toBeGreaterThan($headEnd)
                ->and(strpos($html, '/global.js'))->toBeLessThan(strpos($html, '<via-dev-bar'))
                ->and(strpos($html, '<via-dev-bar'))->toBeLessThan(strripos($html, '</body>'))
            ;
        }
    });

    test('component updates are not decorated', function (): void {
        $via = createVia((new Config())->withDevMode()->withDevBar(true));
        $via->appendToHead('<link rel="stylesheet" href="/global.css">');
        $ctx = new Context('/_/doc16', '/doc', $via);
        $inner = null;
        $render = $ctx->component(function (Context $k) use (&$inner): void {
            $inner = $k;
            $k->view(fn () => fullDocument());
        }, 'cmp');
        $ctx->view(fn () => fullDocument('', $render()));
        $via->buildHtmlDocument($ctx);
        drainPatches($ctx);

        $inner->sync();
        $updates = [...elementPatches($inner), ...elementPatches($ctx)];

        expect($updates)->not->toBeEmpty();
        foreach ($updates as $html) {
            expect($html)->not->toContain('/global.css')
                ->and($html)->not->toContain('<via-dev-bar')
            ;
        }
    });

    test('an update with <body> but no <html> keeps the Dev Bar and gets no includes', function (): void {
        $via = createVia((new Config())->withDevMode()->withDevBar(true));
        $via->appendToHead('<link rel="stylesheet" href="/global.css">');
        $ctx = new Context('/_/doc18', '/doc', $via);
        $ctx->view(fn () => '<body><main id="app">x</main></body>');

        $ctx->sync();
        $html = elementPatches($ctx)[0];

        expect($html)->toContain('<via-dev-bar')
            ->and($html)->not->toContain('/global.css')
        ;
    });

    test('fragment updates are not decorated', function (): void {
        $via = createVia();
        $via->appendToHead('<link rel="stylesheet" href="/global.css">');
        $ctx = new Context('/_/doc9', '/doc', $via);
        $ctx->view(fn () => '<main id="app">x</main>');

        $ctx->sync();
        $elements = array_values(array_filter(drainPatches($ctx), fn (array $p): bool => $p['type'] === 'elements'));

        expect($elements[0]['content'])->toBe('<main id="app">x</main>');
    });
});

describe('shell (fragment) views', function (): void {
    test('the seed meta goes into the head content', function (): void {
        $via = createVia();
        $ctx = new Context('/_/doc10', '/doc', $via);
        $count = $ctx->signal(3, 'count');
        $ctx->view(fn () => '<main id="app">x</main>');

        $html = $via->buildHtmlDocument($ctx);

        expect(metaSignals($html, 'data-signals__ifmissing'))->toBe([$count->id() => 3])
            ->and(strpos($html, '__ifmissing'))->toBeLessThan(stripos($html, '</head>'))
        ;
    });

    test('signal placeholders are keyed on the full signal name and escaped', function (): void {
        $shell = tempnam(sys_get_temp_dir(), 'shell');
        file_put_contents($shell, '<html><head></head><body>[{{ graph_display }}|{{ graph_ports }}|{{ count }}|{{ graph_ports.id }}]{{ content }}</body></html>');

        try {
            $via = createVia();
            $ctx = new Context('/_/doc11', '/doc', $via);
            $ctx->setShellTemplate($shell);
            $ctx->signal('"lines"@x(y);', 'graph_display');
            $ports = $ctx->signal([22, 443], 'graph_ports');
            $ctx->signal(5, 'count', Scope::ROUTE);
            $ctx->view(fn () => '<p>{{ count }}</p>');

            $html = $via->buildHtmlDocument($ctx);
        } finally {
            unlink($shell);
        }

        expect($html)->toContain('[&quot;\&quot;lines\&quot;\u0040x(y)\u003b&quot;|[22,443]|5|' . $ports->id() . ']')
            ->and($html)->toContain('<p>{{ count }}</p>')
        ;
    });
});
