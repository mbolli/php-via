<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/*
 * In dev mode a broadcast fan-out in which every context of one view rendered the same HTML
 * logs a hint, once per view, that the view could pass shareRender: true.
 */

function hintApp(bool $devMode = true): Via {
    return new Via((new Config())->withDevMode($devMode)->withLogLevel('info')->withBroadcastCoalescing(false));
}

/**
 * @param callable(string): string $html the view's HTML for a context id
 */
function hintRoom(Via $app, string $route, string $id, callable $html, bool $share = false, bool $primary = true): Context {
    $c = new Context($id, $route, $app);
    if ($primary) {
        $c->scope('room:1');
    } else {
        $c->addScope('room:1');
    }
    $c->view(fn (): string => $html($id), shareRender: $share);
    $app->contexts[$id] = $c;

    return $c;
}

function hintBroadcast(Via $app): string {
    ob_start();

    try {
        $app->broadcast('room:1');

        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

describe('identical-render hint', function (): void {
    test('contexts of one view rendering the same HTML get the hint once', function (): void {
        $app = hintApp();
        foreach (['/r_/a', '/r_/b', '/r_/c'] as $id) {
            hintRoom($app, '/r', $id, fn (): string => '<p id="board">same</p>');
        }

        $first = hintBroadcast($app);
        $second = hintBroadcast($app);

        expect($first)->toContain('3 tabs of /r rendered identical HTML in one broadcast of room:1')
            ->and($first)->toContain('pass shareRender: true to view()')
            ->and($second)->not->toContain('identical HTML')
        ;
    });

    test('a TAB-primary view is told to set its shared scope first', function (): void {
        $app = hintApp();
        foreach (['/r_/a', '/r_/b'] as $id) {
            hintRoom($app, '/r', $id, fn (): string => '<p id="board">same</p>', primary: false);
        }

        expect(hintBroadcast($app))->toContain('set its shared scope with $c->scope(...)');
    });

    test('no hint when the HTML differs per context', function (): void {
        $app = hintApp();
        foreach (['/r_/a', '/r_/b', '/r_/c'] as $id) {
            hintRoom($app, '/r', $id, fn (string $id): string => $id === '/r_/c' ? '<p>mine</p>' : '<p>same</p>');
        }

        expect(hintBroadcast($app))->not->toContain('identical HTML');
    });

    test('no hint for empty updates, a single context, a shared render or outside dev mode', function (Via $app, int $contexts, bool $share, string $html): void {
        for ($i = 0; $i < $contexts; ++$i) {
            hintRoom($app, '/r', "/r_/{$i}", fn (): string => $html, $share);
        }

        expect(hintBroadcast($app))->not->toContain('identical HTML');
    })->with([
        'empty updates' => [fn () => hintApp(), 2, false, ''],
        'one context' => [fn () => hintApp(), 1, false, '<p>x</p>'],
        'shared render' => [fn () => hintApp(), 2, true, '<p>x</p>'],
        'production' => [fn () => hintApp(devMode: false), 2, false, '<p>x</p>'],
    ]);

    test('two routes in one broadcast are two views', function (): void {
        $app = hintApp();
        hintRoom($app, '/lobby', '/lobby_/a', fn (): string => '<p>x</p>');
        hintRoom($app, '/side', '/side_/a', fn (): string => '<p>x</p>');

        expect(hintBroadcast($app))->not->toContain('identical HTML');
    });
});
