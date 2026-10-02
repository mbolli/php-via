<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use PhpVia\Website\StarbaseComponents;
use PhpVia\Website\Twig\CodeRuntime;

/*
 * The Starbase components the website copies into public/vendor/starbase and registers in php-via's
 * import map, and the copy button of its {% code %} blocks.
 */

$starbaseAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$starbaseReady = false;

if (is_file($starbaseAutoload)) {
    require_once $starbaseAutoload;
    $starbaseReady = class_exists(StarbaseComponents::class);
}

if (!$starbaseReady) {
    test('website Starbase components (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

function starbaseDir(): string {
    return dirname(__DIR__, 2) . '/website/public' . StarbaseComponents::BASE_URL;
}

describe('The vendored Starbase components', function (): void {
    test('every module file is listed, with the hash of its bytes', function (): void {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(starbaseDir(), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && preg_match('/\.m?js$/', $file->getFilename()) === 1) {
                $files[] = substr($file->getPathname(), strlen(starbaseDir()));
            }
        }
        $listed = array_keys(StarbaseComponents::INTEGRITY);
        sort($files);
        sort($listed);

        expect($files)->toBe($listed)
            ->and(array_diff(StarbaseComponents::MODULES, $listed))->toBe([])
        ;
        foreach (StarbaseComponents::INTEGRITY as $file => $hash) {
            expect('sha384-' . base64_encode((string) hash_file('sha384', starbaseDir() . $file, true)))->toBe($hash);
        }
    });

    test('the modules import only datastar and listed files of their own folder', function (): void {
        $relative = [];
        foreach (array_keys(StarbaseComponents::INTEGRITY) as $file) {
            preg_match_all('/(?:\bfrom|\bimport)\s*["\']([^"\']+)["\']/', (string) file_get_contents(starbaseDir() . $file), $m);
            foreach ($m[1] as $specifier) {
                if ($specifier !== 'datastar') {
                    expect($specifier)->toStartWith('./');
                    $relative[] = dirname($file) . substr($specifier, 1);
                }
            }
        }

        // sb-qr-code imports its uqr
        expect($relative)->not->toBe([]);
        foreach ($relative as $file) {
            expect(StarbaseComponents::INTEGRITY)->toHaveKey($file);
        }
    });

    test('the README names every file and hash', function (): void {
        $readme = (string) file_get_contents(starbaseDir() . 'README.md');

        foreach (StarbaseComponents::INTEGRITY as $file => $hash) {
            expect($readme)->toContain('`' . $file . '`')->toContain('`' . $hash . '`');
        }
    });

    test('every file is cached for a year, since its folder name changes with each version', function (): void {
        $dir = (string) realpath(starbaseDir());
        $config = (new Config())->withStaticCacheControl(StarbaseComponents::cacheControl(...));

        foreach (array_keys(StarbaseComponents::INTEGRITY) as $file) {
            expect($config->getStaticCacheControl($dir . '/' . $file, 'application/javascript'))->toBe('public, max-age=31536000, immutable');
        }
        expect($config->getStaticCacheControl($dir . '/README.md', 'text/markdown'))->toBe('public, max-age=3600, must-revalidate')
            ->and($config->getStaticCacheControl(dirname($dir, 2) . '/js/docs-toc.js', 'application/javascript'))->toBe('public, max-age=3600, must-revalidate')
            ->and($config->getStaticCacheControl('/srv/public/datastar.js', 'application/javascript', versioned: true))->toBe('public, max-age=31536000, immutable')
        ;
    });

    test('register() maps each tag to its module and gives every file its hash', function (): void {
        $map = StarbaseComponents::register(new Config())->getImportMap();

        expect($map['imports'])->toBe(['datastar' => $map['imports']['datastar']] + array_map(
            fn (string $file): string => '/vendor/starbase/' . $file,
            StarbaseComponents::MODULES,
        ))
            ->and($map['integrity'] ?? [])->toHaveCount(count(StarbaseComponents::INTEGRITY))
            ->and($map['integrity'] ?? [])->toHaveKey('/vendor/starbase/qr-code@ecc5a99c314a/vendor/uqr.min.mjs')
        ;
    });
});

describe('Code blocks', function (): void {
    test('a block gets a copy button with the code as written', function (): void {
        $html = (new CodeRuntime())->highlight("echo '<b>';\n{-old();-}\n{+new();+}", 'php');

        expect($html)->toStartWith('<div class="code-block"><pre data-lang="php"><code>')
            ->and($html)->toEndWith('</code></pre><sb-copy-button value="echo &#039;&lt;b&gt;&#039;;' . "\n" . 'new();" label="Copy the code"></sb-copy-button></div>')
        ;
    });

    test('a deletion goes with the line it fills, an addition keeps its text, other braces stay', function (string $code, string $plain): void {
        expect(CodeRuntime::plain($code))->toBe($plain);
    })->with([
        'whole lines' => ["a\n{-    b\n    c-}\n{+    d+}\ne", "a\n    d\ne"],
        'inside a line' => ['f({-1-}{+2+})', 'f(2)'],
        'two deletions on a line' => ["{-a-} keep {-b-}\nnext", " keep \nnext"],
        'no marks' => ['$a = [1, -2]; fn() => {$x}', '$a = [1, -2]; fn() => {$x}'],
    ]);
});
