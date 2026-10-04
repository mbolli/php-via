<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Rendering\HtmlBuilder;

/*
 * The shell template was read from disk on every page view. Under SWOOLE_HOOK_FILE each read is
 * several thread-pool handoffs, about half the CPU of a page view. It is now read once per path,
 * and in dev mode again after an edit. HookedStatCacheTest covers the edit under the file hooks.
 */

describe('shell template cache', function (): void {
    $dir = null;

    beforeEach(function () use (&$dir): void {
        $dir = sys_get_temp_dir() . '/via-shell-cache-' . bin2hex(random_bytes(6));
        mkdir($dir);
    });

    afterEach(function () use (&$dir): void {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    });

    $page = function (HtmlBuilder $builder, ?string $shell = null): string {
        $ctx = new Context(testContextId(), '/', createVia());
        if ($shell !== null) {
            $ctx->setShellTemplate($shell);
        }

        return $builder->buildDocument('<p>hi</p>', $ctx, $ctx->getId(), '/');
    };

    test('outside dev mode each shell is read once', function () use (&$dir, $page): void {
        file_put_contents($dir . '/a.html', 'A {{ content }}');
        file_put_contents($dir . '/b.html', 'B {{ content }}');
        $builder = new HtmlBuilder($dir . '/a.html');

        expect($page($builder))->toBe('A <p>hi</p>')
            ->and($page($builder, $dir . '/b.html'))->toBe('B <p>hi</p>')
        ;

        // Gone from disk, both still render: neither is read again.
        unlink($dir . '/a.html');
        unlink($dir . '/b.html');

        expect($page($builder))->toBe('A <p>hi</p>')
            ->and($page($builder, $dir . '/b.html'))->toBe('B <p>hi</p>')
        ;
    });

    test('outside dev mode an edit is not picked up', function () use (&$dir, $page): void {
        file_put_contents($dir . '/shell.html', 'old {{ content }}');
        $builder = new HtmlBuilder($dir . '/shell.html');
        $page($builder);

        file_put_contents($dir . '/shell.html', 'edited {{ content }}');
        touch($dir . '/shell.html', time() + 5);

        expect($page($builder))->toBe('old <p>hi</p>');
    });

    test('in dev mode an edit is read on the next page view', function () use (&$dir, $page): void {
        $path = $dir . '/shell.html';
        file_put_contents($path, 'old {{ content }}');
        $builder = new HtmlBuilder($path, devMode: true);

        expect($page($builder))->toBe('old <p>hi</p>');

        file_put_contents($path, 'edited {{ content }}');
        touch($path, time() + 5);

        expect($page($builder))->toBe('edited <p>hi</p>');
    });

    test('in dev mode an unchanged shell is served from memory', function () use (&$dir, $page): void {
        $path = $dir . '/shell.html';
        file_put_contents($path, 'one {{ content }}');
        $mtime = (int) filemtime($path);
        $builder = new HtmlBuilder($path, devMode: true);
        $page($builder);

        // Same size and mtime: the stat matches, so the cached copy is served, not the new bytes.
        file_put_contents($path, 'two {{ content }}');
        touch($path, $mtime);

        expect($page($builder))->toBe('one <p>hi</p>');
    });

    test('Via passes dev mode to the builder', function () use (&$dir): void {
        $path = $dir . '/shell.html';
        file_put_contents($path, 'old {{ content }}');
        $via = createVia((new Config())->withShellTemplate($path)->withDevMode());
        $ctx = new Context(testContextId(), '/', $via);
        $ctx->view(fn (): string => '<p>hi</p>');
        $via->buildHtmlDocument($ctx);

        file_put_contents($path, 'edited {{ content }}');
        touch($path, time() + 5);

        expect($via->buildHtmlDocument($ctx))->toStartWith('edited ');
    });

    test('a missing shell still throws', function () use (&$dir, $page): void {
        $builder = new HtmlBuilder($dir . '/missing.html');
        set_error_handler(fn (): bool => true);

        try {
            expect(fn () => $page($builder))->toThrow(RuntimeException::class, 'Failed to load shell template');
        } finally {
            restore_error_handler();
        }
    });
});
