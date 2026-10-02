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
 * The import map's 'datastar' URL (null without a map), the Datastar script's src (decoded) and their offsets.
 *
 * @return array{0: ?string, 1: string, 2: ?int, 3: int}
 */
function shellDatastarUrls(string $html): array {
    preg_match('#<script type="module" src="([^"]*)"></script>\s*</body>#', $html, $src, PREG_OFFSET_CAPTURE);
    $script = html_entity_decode($src[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('#<script type="importmap">(.*?)</script>#s', $html, $map, PREG_OFFSET_CAPTURE) !== 1) {
        return [null, $script, null, $src[0][1]];
    }
    $imports = json_decode($map[1][0], true, flags: JSON_THROW_ON_ERROR)['imports'];

    return [$imports['datastar'], $script, $map[0][1], $src[0][1]];
}

describe('the Datastar import map in the default shell', function (): void {
    test('maps datastar to exactly the versioned URL the page loads the Rocket build from', function (Config $config): void {
        $via = createVia($config);
        $ctx = new Context(testContextId(), '/', $via);
        $ctx->view(fn () => '<p>hi</p>');

        [$mapped, $src, $mapAt, $srcAt] = shellDatastarUrls($via->buildHtmlDocument($ctx));

        expect($mapped)->toBe($src)
            ->and($src)->toBe($config->getDatastarUrl())
            ->and($src)->toMatch('#^/(app/)?datastar\.js\?v=[0-9a-f]{10}$#')
            ->and($mapAt)->toBeLessThan($srcAt)
        ;
    })->with([
        'at the root' => fn () => (new Config())->withDatastarRocket(),
        'under a base path' => fn () => (new Config())->withDatastarRocket()->withBasePath('/app'),
    ]);

    test('is left out with the plain bundle, which loads from the versioned URL', function (): void {
        $config = new Config();
        $via = createVia($config);
        $ctx = new Context(testContextId(), '/', $via);
        $ctx->view(fn () => '<p>hi</p>');

        $html = $via->buildHtmlDocument($ctx);
        [$mapped, $src] = shellDatastarUrls($html);

        expect($mapped)->toBeNull()
            ->and($html)->not->toContain('importmap')
            ->and($src)->toBe($config->getDatastarUrl())
        ;
    });

    test('comes before module scripts added to the head', function (): void {
        $via = createVia((new Config())->withDatastarRocket());
        $via->appendToHead('<script type="module" src="/components/sb-input.mjs"></script>');
        $ctx = new Context(testContextId(), '/', $via);
        $ctx->view(fn () => '<p>hi</p>');

        $html = $via->buildHtmlDocument($ctx);

        expect(strpos($html, '<script type="importmap">'))->toBeLessThan(strpos($html, '<script type="module"'));
    });

    test('escapes the URL for the JSON and the attribute alike', function (): void {
        $via = createVia();
        $ctx = new Context(testContextId(), '/', $via);
        $url = '/x"</script><!--&\'ü/datastar.js?v=1&b=2';

        $html = (new HtmlBuilder())->buildDocument('<p>hi</p>', $ctx, $ctx->getId(), '/x/', $url, importMap: true);
        [$mapped, $src] = shellDatastarUrls($html);
        preg_match('#<script type="importmap">(.*?)</script>#s', $html, $map);

        expect($mapped)->toBe($url)
            ->and($src)->toBe($url)
            ->and($map[1])->not->toContain('<')
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
    });

    afterEach(function (): void {
        foreach ($this->shellFiles as $path) {
            @unlink($path);
        }
    });

    test('warns once when it loads Datastar without {{ datastar_url }}', function (): void {
        $logs = [];
        $builder = new HtmlBuilder(
            ($this->shell)('<head>{{ datastar_import_map }}</head><body>{{ content }}<script type="module" src="{{ base_path }}datastar.js"></script></body>'),
            function (string $level, string $message) use (&$logs): void { $logs[] = [$level, $message]; },
        );
        $ctx = new Context(testContextId(), '/', createVia());

        $builder->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', importMap: true);
        $builder->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', importMap: true);

        expect($logs)->toHaveCount(1)
            ->and($logs[0][0])->toBe('warning')
            ->and($logs[0][1])->toContain('second Datastar engine')
        ;
    });

    test('does not warn when it loads {{ datastar_url }} or the plain bundle runs', function (): void {
        $logs = [];
        $logger = function (string $level, string $message) use (&$logs): void { $logs[] = $message; };
        $matching = new HtmlBuilder(($this->shell)('{{ datastar_import_map }}{{ content }}<script type="module" src="{{ datastar_url }}"></script>'), $logger);
        $plain = new HtmlBuilder(($this->shell)('{{ datastar_import_map }}{{ content }}<script type="module" src="{{ base_path }}datastar.js"></script>'), $logger);
        $ctx = new Context(testContextId(), '/', createVia());

        $matching->buildDocument('<p>a</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1', importMap: true);
        $html = $plain->buildDocument('<p>b</p>', $ctx, $ctx->getId(), '/', '/datastar.js?v=1');

        expect($logs)->toBe([])
            ->and($html)->toBe('<p>b</p><script type="module" src="/datastar.js"></script>')
        ;
    });
});

describe('datastarUrl in Twig templates', function (): void {
    test('follows the config when it changes after new Via()', function (): void {
        $via = createVia();
        $via->getTwig()->setLoader(new ArrayLoader(['layout.html.twig' => '{{ datastarUrl }}']));
        $ctx = new Context(testContextId(), '/', $via);
        $plain = $ctx->render('layout.html.twig');

        $via->getConfig()->withDatastarRocket();

        expect($plain)->toBe((new Config())->getDatastarUrl())
            ->and($ctx->render('layout.html.twig'))->toBe($via->getConfig()->getDatastarUrl())
            ->and($ctx->renderString('{{ datastarUrl }}'))->toBe($via->getConfig()->getDatastarUrl())
            ->and($via->getConfig()->getDatastarUrl())->not->toBe($plain)
        ;
    });
});
