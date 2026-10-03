<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Rendering\Bootstrap;
use Mbolli\PhpVia\Rendering\HtmlBuilder;

/*
 * Datastar 1.0 reads an event listener only as data-on:<event>. The dash form names a plugin, and
 * the only dash-form plugins are on-interval, on-intersect and on-signal-patch; any other
 * data-on-<x> attribute is silently ignored.
 */

describe('default shell template', function (): void {
    test('every data-on- attribute names a Datastar plugin', function (): void {
        $page = shellPage(new Config());
        preg_match_all('/\sdata-on-([a-z-]+)/', $page, $m);

        expect($m[1])->not->toBeEmpty();
        foreach ($m[1] as $name) {
            expect($name)->toMatch('/^(interval|intersect|signal-patch)(-|$)/');
        }
    });

    test('the connection warning listens to datastar-fetch events', function (): void {
        $page = shellPage(new Config());

        expect($page)->toContain('data-on:datastar-fetch="')
            ->and($page)->toContain('data-show="$_disconnected"')
        ;
    });

    test('writes via_head right after <meta charset> and via_foot last in <body>, and leaves no placeholder', function (): void {
        $via = createVia();
        $ctx = new Context(testContextId(), '/', $via);
        $ctx->view(fn () => '<p>hi</p>');
        $page = $via->buildHtmlDocument($ctx);

        expect($page)->toContain("<meta charset=\"UTF-8\">\n    " . $ctx->viaHead() . "\n")
            ->and($page)->toMatch('#' . preg_quote($ctx->viaFoot(), '#') . '\s*</body>#')
            ->and($page)->not->toContain('{{')
        ;
    });
});

/**
 * The page's import map (decoded, null without one), the Datastar script's src (decoded) and their offsets.
 *
 * @return array{0: null|array<string, array<string, string>>, 1: string, 2: ?int, 3: int}
 */
function shellImportMap(string $html): array {
    preg_match('#<script type="module" src="([^"]*)"></script>\s*</body>#', $html, $src, PREG_OFFSET_CAPTURE);
    $script = html_entity_decode($src[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('#<script type="importmap">(.*?)</script>#s', $html, $map, PREG_OFFSET_CAPTURE) !== 1) {
        return [null, $script, null, $src[0][1]];
    }

    return [json_decode($map[1][0], true, flags: JSON_THROW_ON_ERROR), $script, $map[0][1], $src[0][1]];
}

function shellPage(Config $config, string $headScript = ''): string {
    $via = createVia($config);
    if ($headScript !== '') {
        $via->appendToHead($headScript);
    }
    $ctx = new Context(testContextId(), '/', $via);
    $ctx->view(fn () => '<p>hi</p>');

    return $via->buildHtmlDocument($ctx);
}

describe('the import map in the default shell', function (): void {
    test('maps datastar to exactly the versioned URL the page loads the Rocket build from', function (Config $config): void {
        [$map, $src, $mapAt, $srcAt] = shellImportMap(shellPage($config));

        expect($map)->toBe(['imports' => ['datastar' => $src]])
            ->and($src)->toBe($config->getDatastarUrl())
            ->and($src)->toMatch('#^/(app/)?datastar\.js\?v=[0-9a-f]{10}$#')
            ->and($mapAt)->toBeLessThan($srcAt)
        ;
    })->with([
        'at the root' => fn () => (new Config())->withDatastarRocket(),
        'under a base path' => fn () => (new Config())->withDatastarRocket()->withBasePath('/app'),
    ]);

    test('is left out with the plain bundle and no entries, which loads from the versioned URL', function (): void {
        $config = new Config();
        $html = shellPage($config);
        [$map, $src] = shellImportMap($html);

        expect($map)->toBeNull()
            ->and($html)->not->toContain('importmap')
            ->and($src)->toBe($config->getDatastarUrl())
        ;
    });

    test('is written with the plain bundle once the app adds entries', function (): void {
        $integrity = 'sha384-' . base64_encode(hash('sha384', 'chart', true));
        $config = (new Config())->withImportMap(['chart' => '/js/chart.js'], ['/js/chart.js' => $integrity]);

        [$map, $src] = shellImportMap(shellPage($config));

        expect($map)->toBe(['imports' => ['datastar' => $src, 'chart' => '/js/chart.js'], 'integrity' => ['/js/chart.js' => $integrity]])
            ->and($src)->toBe((new Config())->getDatastarUrl())
        ;
    });

    test('comes before module scripts added to the head', function (Config $config): void {
        $html = shellPage($config, '<script type="module" src="/components/sb-input.mjs"></script>');

        expect(strpos($html, '<script type="importmap">'))->toBeInt()->toBeLessThan(strpos($html, '<script type="module"'));
    })->with([
        'Rocket' => fn () => (new Config())->withDatastarRocket(),
        'entries' => fn () => (new Config())->withImportMap(['sb-input' => '/components/sb-input.mjs']),
    ]);

    test('escapes the Datastar URL for the attribute', function (): void {
        $url = '/x"</script><!--&\'ü/datastar.js?v=1&b=2';

        expect(Bootstrap::foot($url, null))->toBe('<script type="module" src="/x&quot;&lt;/script&gt;&lt;!--&amp;&#039;ü/datastar.js?v=1&amp;b=2"></script>');
    });
});

describe('a custom shell', function (): void {
    beforeEach(function (): void {
        $this->shellFiles = [];
        $this->shell = function (string $markup): string {
            $path = sys_get_temp_dir() . '/via-shell-' . bin2hex(random_bytes(6)) . '.html';
            file_put_contents($path, $markup);
            $this->shellFiles[] = $path;

            return $path;
        };
        $this->logs = [];
        $this->builder = fn (string $markup, bool $devMode = true): HtmlBuilder => new HtmlBuilder(
            ($this->shell)($markup),
            function (string $level, string $message): void { $this->logs[] = [$level, $message]; },
            $devMode,
        );
    });

    afterEach(function (): void {
        foreach ($this->shellFiles as $path) {
            @unlink($path);
        }
    });

    test('fills {{ via_head }} and {{ via_foot }} with the context\'s viaHead() and viaFoot()', function (): void {
        $builder = ($this->builder)('<head><meta charset="UTF-8">{{ via_head }}</head><body>{{ content }}{{ via_foot }}</body>');
        $ctx = new Context(testContextId(), '/', createVia((new Config())->withDatastarRocket()));

        expect($builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/'))
            ->toBe('<head><meta charset="UTF-8">' . $ctx->viaHead() . '</head><body><p>a</p>' . $ctx->viaFoot() . '</body>')
            ->and($ctx->viaHead())->toContain('<script type="importmap">')
            ->and($this->logs)->toBe([])
        ;
    });

    test('warns once in dev mode when it has no {{ via_head }}', function (): void {
        $builder = ($this->builder)('<head>{{ head_content }}</head><body>{{ content }}<script type="module" src="{{ base_path }}datastar.js"></script></body>');
        $ctx = new Context(testContextId(), '/', createVia());

        $builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/');
        $builder->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/');

        expect($this->logs)->toHaveCount(1)
            ->and($this->logs[0][0])->toBe('warning')
            ->and($this->logs[0][1])->toContain('has no {{ via_head }}')
        ;
    });

    test('written before 0.14, still gets {{ signals_json }} for its own bootstrap', function (): void {
        $builder = ($this->builder)("<meta data-signals='{{ signals_json }}'>{{ content }}", false);
        $ctx = new Context('/_/old', '/', createVia());

        expect($builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/'))->toBe('<meta data-signals=\'{"via_ctx":"\/_\/old","_disconnected":false}\'><p>a</p>');
    });

    test('does not warn outside dev mode', function (): void {
        $builder = ($this->builder)('{{ content }}', false);
        $ctx = new Context(testContextId(), '/', createVia());

        expect($builder->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/'))->toBe('<p>b</p>')
            ->and($this->logs)->toBe([])
        ;
    });

    test('warns once in dev mode when the page has a second import map', function (): void {
        $builder = ($this->builder)('<head>{{ via_head }}<script type="importmap">{"imports":{}}</script></head><body>{{ content }}{{ via_foot }}</body>');
        $ctx = new Context(testContextId(), '/maps', createVia((new Config())->withDatastarRocket()));

        $builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/');
        $builder->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/');

        expect($this->logs)->toHaveCount(1)
            ->and($this->logs[0][1])->toContain('The page of /maps has more than one import map')
        ;
    });
});

describe('a view that renders its own document', function (): void {
    beforeEach(function (): void {
        $this->logs = [];
        $this->builder = new HtmlBuilder(null, function (string $level, string $message): void { $this->logs[] = [$level, $message]; }, true);
        $this->document = fn (string $head): string => "<!DOCTYPE html><html><head><meta charset=\"UTF-8\">{$head}</head><body></body></html>";
    });

    test('warns once per route in dev mode when it has no via_head', function (): void {
        $via = createVia();
        $home = new Context(testContextId(), '/', $via);
        $docs = new Context(testContextId(), '/docs', $via);

        $this->builder->buildDocument(($this->document)(''), $home, $home->getId(), '/');
        $html = $this->builder->buildDocument(($this->document)(''), $home, $home->getId(), '/');
        $this->builder->buildDocument(($this->document)(''), $docs, $docs->getId(), '/');

        expect($this->logs)->toHaveCount(2)
            ->and($this->logs[0][0])->toBe('warning')
            ->and($this->logs[0][1])->toContain('for / has no via_head')
            ->and($this->logs[1][1])->toContain('for /docs has no via_head')
            ->and($html)->toContain('<meta data-signals="')
        ;
    });

    test('does not warn when it writes via_head, and adds no via_ctx of its own', function (): void {
        $via = createVia();
        $home = new Context(testContextId(), '/', $via);

        $html = $this->builder->buildDocument(($this->document)($home->viaHead()), $home, $home->getId(), '/');

        expect($this->logs)->toBe([])
            ->and(substr_count($html, 'via_ctx'))->toBe(1)
        ;
    });
});
