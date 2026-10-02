<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Rendering\HtmlBuilder;

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
 * The import map's 'datastar' URL, the Datastar script's src (both decoded) and their offsets.
 *
 * @return array{0: string, 1: string, 2: int, 3: int}
 */
function shellDatastarUrls(string $html): array {
    preg_match('#<script type="importmap">(.*?)</script>#s', $html, $map, PREG_OFFSET_CAPTURE);
    preg_match('#<script type="module" src="([^"]*)"></script>\s*</body>#', $html, $src, PREG_OFFSET_CAPTURE);
    $imports = json_decode($map[1][0], true, flags: JSON_THROW_ON_ERROR)['imports'];

    return [$imports['datastar'], html_entity_decode($src[1][0], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $map[0][1], $src[0][1]];
}

describe('the Datastar import map in the default shell', function (): void {
    test('maps datastar to exactly the versioned URL the page loads Datastar from', function (Config $config): void {
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
        'plain bundle' => fn () => new Config(),
        'Rocket bundle under a base path' => fn () => (new Config())->withDatastarRocket()->withBasePath('/app'),
    ]);

    test('comes before module scripts added to the head', function (): void {
        $via = createVia();
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

        $html = (new HtmlBuilder())->buildDocument('<p>hi</p>', $ctx, $ctx->getId(), '/x/', $url);
        [$mapped, $src] = shellDatastarUrls($html);
        preg_match('#<script type="importmap">(.*?)</script>#s', $html, $map);

        expect($mapped)->toBe($url)
            ->and($src)->toBe($url)
            ->and($map[1])->not->toContain('<')
        ;
    });
});
