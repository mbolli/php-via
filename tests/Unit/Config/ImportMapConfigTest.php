<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Support\DatastarBundle;

/**
 * A valid Subresource Integrity value for $content.
 */
function sri(string $content, string $algo = 'sha384'): string {
    return $algo . '-' . base64_encode(hash($algo, $content, true));
}

/**
 * The JSON inside getImportMapTag(), decoded, or null when there is no tag.
 *
 * @return null|array<string, array<string, string>>
 */
function importMapFromTag(string $tag): ?array {
    if ($tag === '') {
        return null;
    }
    expect($tag)->toStartWith('<script type="importmap">')->toEndWith('</script>');

    return json_decode(substr($tag, strlen('<script type="importmap">'), -strlen('</script>')), true, flags: JSON_THROW_ON_ERROR);
}

describe('Config::withImportMap()', function (): void {
    test('getImportMap() starts with datastar and has no integrity by default', function (): void {
        $config = new Config();

        expect($config->getImportMap())->toBe(['imports' => ['datastar' => $config->getDatastarUrl()]]);
    });

    test('merges imports and integrity across calls, a later entry replacing an earlier one', function (): void {
        $config = (new Config())
            ->withImportMap(['chart' => '/js/chart.js', 'lib/' => 'https://cdn.example.com/lib/'], ['/js/chart.js' => sri('a')])
            ->withImportMap(['chart' => '/js/chart-2.js', 'icons' => '//cdn.example.com/icons.js'], ['/js/chart-2.js' => sri('b', 'sha512')])
            ->withImportMap([], ['/js/chart.js' => sri('c', 'sha256')])
        ;

        expect($config->getImportMap())->toBe([
            'imports' => [
                'datastar' => $config->getDatastarUrl(),
                'chart' => '/js/chart-2.js',
                'lib/' => 'https://cdn.example.com/lib/',
                'icons' => '//cdn.example.com/icons.js',
            ],
            'integrity' => [
                '/js/chart.js' => sri('c', 'sha256'),
                '/js/chart-2.js' => sri('b', 'sha512'),
            ],
        ]);
    });

    test('integrity alone, such as a Starbase catalog importmap.json, is merged as is', function (): void {
        $catalog = json_decode('{"integrity": {"https://starbase.example/c/@abc/autoloader.js": "' . sri('x') . '"}}', true);
        $config = (new Config())->withImportMap([], $catalog['integrity']);

        expect($config->getImportMap()['integrity'])->toBe($catalog['integrity']);
    });

    test('accepts several space-separated hashes for one URL', function (): void {
        $config = (new Config())->withImportMap([], ['/a.js' => sri('a') . ' ' . sri('a', 'sha512')]);

        expect($config->getImportMap()['integrity'])->toBe(['/a.js' => sri('a') . ' ' . sri('a', 'sha512')]);
    });

    test('reserves the datastar specifier', function (): void {
        expect(fn () => (new Config())->withImportMap(['datastar' => '/my-datastar.js']))
            ->toThrow(InvalidArgumentException::class, "'datastar' is reserved")
        ;
    });

    test('rejects entries the browser would ignore', function (array $imports, array $integrity): void {
        expect(fn () => (new Config())->withImportMap($imports, $integrity))->toThrow(InvalidArgumentException::class);
    })->with([
        'a list instead of pairs' => [['/js/chart.js'], []],
        'an empty specifier' => [['' => '/js/chart.js'], []],
        'a URL that is not a string' => [['chart' => 42], []],
        'an empty URL' => [['chart' => ''], []],
        'a bare URL' => [['chart' => 'chart.js'], []],
        // The same map goes into every page, and these resolve against each page's URL
        'a URL relative to the page' => [['chart' => './chart.js'], []],
        'a URL relative to the page\'s parent' => [['chart' => '../js/chart.js'], []],
        'integrity for a URL relative to the page' => [[], ['./chart.js' => sri('a')]],
        'a URL that is not UTF-8' => [['chart' => "/js/\xff.js"], []],
        'a prefix specifier without a prefix URL' => [['lib/' => '/js/lib.js'], []],
        'integrity for a bare URL' => [[], ['chart.js' => sri('a')]],
        'integrity as a list' => [[], [sri('a')]],
        'integrity that is not a string' => [[], ['/a.js' => null]],
        'an unknown algorithm' => [[], ['/a.js' => sri('a', 'sha1')]],
        'a hash without its algorithm' => [[], ['/a.js' => base64_encode(hash('sha384', 'a', true))]],
        'a digest of the wrong length' => [[], ['/a.js' => 'sha384-' . base64_encode(hash('sha256', 'a', true))]],
        'a digest that is not base64' => [[], ['/a.js' => 'sha256-' . str_repeat('!', 43) . '=']],
        'a digest without its padding' => [[], ['/a.js' => rtrim(sri('a', 'sha256'), '=')]],
        'a double space between hashes' => [[], ['/a.js' => sri('a') . '  ' . sri('b')]],
    ]);

    test('a rejected call adds none of its entries', function (): void {
        $config = (new Config())->withImportMap(['chart' => '/js/chart.js']);

        try {
            $config->withImportMap(['icons' => '/js/icons.js', 'bad' => 'bad.js']);
        } catch (InvalidArgumentException) {
        }

        expect($config->getImportMap()['imports'])->toBe(['datastar' => $config->getDatastarUrl(), 'chart' => '/js/chart.js']);
    });
});

describe('Config::getImportMapTag()', function (): void {
    test('is empty with the plain bundle and no entries', function (): void {
        expect((new Config())->getImportMapTag())->toBe('');
    });

    test('maps datastar to the versioned URL with the Rocket build', function (): void {
        $config = (new Config())->withDatastarRocket()->withBasePath('/app');

        expect(importMapFromTag($config->getImportMapTag()))->toBe(['imports' => ['datastar' => $config->getDatastarUrl()]])
            ->and($config->getDatastarUrl())->toMatch('#^/app/datastar\.js\?v=[0-9a-f]{10}$#')
        ;
    });

    test('is written with the plain bundle once the app adds entries', function (array $imports, array $integrity): void {
        $config = (new Config())->withImportMap($imports, $integrity);

        expect(importMapFromTag($config->getImportMapTag()))->toBe($config->getImportMap())
            ->and($config->getImportMap()['imports']['datastar'])->toBe($config->getDatastarUrl())
        ;
    })->with([
        'imports' => [['chart' => '/js/chart.js'], []],
        'integrity only' => [[], ['/js/chart.js' => sri('a')]],
    ]);

    test('follows Rocket, the base path and later entries after a first call', function (): void {
        $config = new Config();
        $first = $config->withImportMap(['chart' => '/js/chart.js'])->getImportMapTag();
        $rocket = $config->withDatastarRocket()->getImportMapTag();
        $based = $config->withBasePath('/app')->getImportMapTag();
        $more = $config->withImportMap(['icons' => '/js/icons.js'])->getImportMapTag();

        expect(importMapFromTag($first)['imports']['datastar'])->toBe((new Config())->getDatastarUrl())
            ->and(importMapFromTag($rocket)['imports']['datastar'])->toBe((new Config())->withDatastarRocket()->getDatastarUrl())
            ->and(importMapFromTag($based)['imports']['datastar'])->toStartWith('/app/datastar.js?v=')
            ->and(importMapFromTag($more))->toBe($config->getImportMap())
            ->and(array_keys(importMapFromTag($more)['imports']))->toBe(['datastar', 'chart', 'icons'])
        ;
    });

    test('holds the JSON the CSP docs tell apps to hash', function (): void {
        $config = (new Config())->withDatastarRocket()->withImportMap(['chart' => 'https://cdn.example.com/chart.js?v=2&min=1']);

        expect($config->getImportMapTag())->toBe(
            '<script type="importmap">' . json_encode($config->getImportMap(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>'
        );
    });

    test('keeps the JSON from closing the script or opening a comment', function (): void {
        $url = '/x"</script><!--<script>&\'ü.js';
        $config = (new Config())->withImportMap(['x' => $url], [$url => sri('a')]);
        $tag = $config->getImportMapTag();
        $json = substr($tag, strlen('<script type="importmap">'), -strlen('</script>'));

        expect($json)->not->toContain('<')
            ->and($json)->not->toContain('>')
            ->and($json)->not->toContain('&')
            ->and($json)->toContain('"datastar":"/datastar.js?v=')
            ->and(importMapFromTag($tag))->toBe(['imports' => ['datastar' => $config->getDatastarUrl(), 'x' => $url], 'integrity' => [$url => sri('a')]])
        ;
    });
});

describe('Config::getDatastarIntegrity()', function (): void {
    test('is the sha384 of the bundle /datastar.js serves', function (bool $rocket): void {
        $config = (new Config())->withDatastarRocket($rocket);
        $bytes = (string) file_get_contents(dirname(__DIR__, 3) . '/public/' . ($rocket ? 'datastar-rocket.js' : 'datastar.js'));

        expect($config->getDatastarIntegrity())->toBe('sha384-' . base64_encode(hash('sha384', $bytes, true)))
            ->and($config->getDatastarIntegrity())->toMatch('#^sha384-[A-Za-z0-9+/]{64}$#')
        ;
    })->with(['plain' => false, 'Rocket' => true]);

    test('can pin Datastar in the import map', function (): void {
        $config = (new Config())->withDatastarRocket();
        $config->withImportMap([], [$config->getDatastarUrl() => $config->getDatastarIntegrity()]);

        expect(importMapFromTag($config->getImportMapTag())['integrity'])->toBe([$config->getDatastarUrl() => $config->getDatastarIntegrity()]);
    });

    test('a bundle that cannot be read has neither version nor integrity', function (): void {
        expect(DatastarBundle::fingerprint(sys_get_temp_dir() . '/via-ds-missing.js'))->toBe(['version' => null, 'integrity' => null]);
    });
});
