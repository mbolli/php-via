<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Rendering\HtmlBuilder;
use Twig\Loader\ArrayLoader;

/*
 * Datastar 1.0 reads an event listener only as data-on:<event>. The dash form names a plugin, and
 * the only dash-form plugins are on-interval, on-intersect and on-signal-patch; any other
 * data-on-<x> attribute is silently ignored.
 */

describe('default shell template', function (): void {
    test('every data-on- attribute names a Datastar plugin', function (): void {
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Rendering/shell.html');
        preg_match_all('/\sdata-on-([a-z-]+)/', $shell, $m);

        foreach ($m[1] as $name) {
            expect($name)->toMatch('/^(interval|intersect|signal-patch)(-|$)/');
        }
    });

    test('the connection warning listens to datastar-fetch events', function (): void {
        $shell = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Rendering/shell.html');

        expect($shell)->toContain('data-on:datastar-fetch="')
            ->and($shell)->toContain('data-show="$_disconnected"')
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

    test('escapes the Datastar URL for the attribute and writes the import map tag as given', function (): void {
        $ctx = new Context(testContextId(), '/', createVia());
        $url = '/x"</script><!--&\'ü/datastar.js?v=1&b=2';
        $tag = '<script type="importmap">{"imports":{"datastar":"/x/datastar.js"}}</script>';

        $html = (new HtmlBuilder())->buildDocument('<p>hi</p>', $ctx, $ctx->getId(), '/x/', $url, $tag);
        [, $src] = shellImportMap($html);

        expect($src)->toBe($url)
            ->and($html)->toContain('src="/x&quot;&lt;/script&gt;&lt;!--&amp;&#039;ü/datastar.js?v=1&amp;b=2"')
            ->and(substr_count($html, $tag))->toBe(1)
        ;
    });
});

describe('a custom shell with the import map', function (): void {
    beforeEach(function (): void {
        $this->shellFiles = [];
        $this->shell = function (string $markup): string {
            $path = sys_get_temp_dir() . '/via-shell-' . bin2hex(random_bytes(6)) . '.html';
            file_put_contents($path, $markup);
            $this->shellFiles[] = $path;

            return $path;
        };
        $this->logs = [];
        $this->builder = fn (string $markup): HtmlBuilder => new HtmlBuilder(
            ($this->shell)($markup),
            function (string $level, string $message): void { $this->logs[] = [$level, $message]; },
        );
        $this->tag = '<script type="importmap">{"imports":{"datastar":"/datastar.js?v=1"}}</script>';
    });

    afterEach(function (): void {
        foreach ($this->shellFiles as $path) {
            @unlink($path);
        }
    });

    test('fills {{ import_map }} and {{ datastar_url }}', function (): void {
        $builder = ($this->builder)('{{ import_map }}{{ content }}<script type="module" src="{{ datastar_url }}"></script>');
        $ctx = new Context(testContextId(), '/', createVia());

        expect($builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', $this->tag))
            ->toBe($this->tag . '<p>a</p><script type="module" src="/datastar.js?v=1"></script>')
            ->and($builder->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1'))
            ->toBe('<p>b</p><script type="module" src="/datastar.js?v=1"></script>')
            ->and($this->logs)->toBe([])
        ;
    });

    test('warns once when it loads Datastar without {{ datastar_url }}', function (): void {
        $builder = ($this->builder)('<head>{{ import_map }}</head><body>{{ content }}<script type="module" src="{{ base_path }}datastar.js"></script></body>');
        $ctx = new Context(testContextId(), '/', createVia());

        $builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', $this->tag);
        $builder->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', $this->tag);

        expect($this->logs)->toHaveCount(1)
            ->and($this->logs[0][0])->toBe('warning')
            ->and($this->logs[0][1])->toContain('second Datastar engine')
        ;
    });

    test('warns once when a map is due and the shell has no {{ import_map }}', function (): void {
        $builder = ($this->builder)('{{ content }}<script type="module" src="{{ datastar_url }}"></script>');
        $ctx = new Context(testContextId(), '/', createVia());

        $builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', $this->tag);
        $html = $builder->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', $this->tag);

        expect($this->logs)->toHaveCount(1)
            ->and($this->logs[0][1])->toContain('has no {{ import_map }}')
            ->and($html)->not->toContain('importmap')
        ;
    });

    test('does not warn when the shell writes its own map, or no map is due', function (): void {
        $own = ($this->builder)('<script type="importmap" nonce="n">{"imports":{}}</script>{{ content }}<script type="module" src="{{ datastar_url }}"></script>');
        $plain = ($this->builder)('{{ content }}<script type="module" src="{{ base_path }}datastar.js"></script>');
        $ctx = new Context(testContextId(), '/', createVia());

        $own->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', $this->tag);
        $html = $plain->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1');

        expect($this->logs)->toBe([])
            ->and($html)->toBe('<p>b</p><script type="module" src="/datastar.js"></script>')
        ;
    });
});

describe('datastarUrl and importMap in Twig templates', function (): void {
    test('follow the config when it changes after new Via()', function (): void {
        $via = createVia();
        $via->getTwig()->setLoader(new ArrayLoader(['layout.html.twig' => '{{ datastarUrl }}|{{ importMap }}']));
        $ctx = new Context(testContextId(), '/', $via);
        $plain = $ctx->render('layout.html.twig');

        $config = $via->getConfig()->withDatastarRocket();
        $rocket = $config->getDatastarUrl() . '|' . $config->getImportMapTag();

        expect($plain)->toBe((new Config())->getDatastarUrl() . '|')
            ->and($ctx->render('layout.html.twig'))->toBe($rocket)
            ->and($ctx->renderString('{{ datastarUrl }}|{{ importMap }}'))->toBe($rocket)
            ->and($config->getImportMapTag())->toStartWith('<script type="importmap">{"imports":{"datastar":"' . $config->getDatastarUrl() . '"')
        ;

        $config->withImportMap(['chart' => '/js/chart.js']);

        expect($ctx->render('layout.html.twig'))->toBe($config->getDatastarUrl() . '|' . $config->getImportMapTag())
            ->and($config->getImportMapTag())->toContain('"chart":"/js/chart.js"')
        ;
    });

    test('a template rendered outside a context gets the values from new Via()', function (): void {
        $config = (new Config())->withImportMap(['chart' => '/js/chart.js']);
        $via = createVia($config);

        expect($via->getTwig()->createTemplate('{{ datastarUrl }}|{{ importMap }}')->render([]))
            ->toBe($config->getDatastarUrl() . '|' . $config->getImportMapTag())
        ;
    });
});
