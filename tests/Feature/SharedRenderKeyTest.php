<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

/*
 * The shared update render is keyed by scope, route pattern and component namespace. Keyed by
 * scope alone, a /dashboard sidebar in 'room:lobby' received the /lobby page's HTML.
 */

function sharedKeyApp(): Via {
    return createVia((new Config())->withBroadcastCoalescing(false));
}

/** @return list<string> */
function sharedKeyElements(Context $c): array {
    $out = [];
    while (($p = $c->getPatch()) !== null) {
        if ($p['type'] === 'elements') {
            $out[] = (string) $p['content'];
        }
    }

    return $out;
}

describe('shared render key', function (): void {
    test('two routes in one primary scope keep their own update render', function (): void {
        $app = sharedKeyApp();

        $page = new Context('/lobby_/a', '/lobby', $app);
        $page->scope('room:lobby');
        $page->view(fn (): string => '<div id="lobby">LOBBY PAGE</div>');

        $side = new Context('/dashboard_/b', '/dashboard', $app);
        $side->scope('room:lobby');
        $side->view(fn (): string => '<div id="sidebar">SIDEBAR</div>');

        $app->contexts[$page->getId()] = $page;
        $app->contexts[$side->getId()] = $side;

        $app->broadcast('room:lobby');

        expect(sharedKeyElements($page))->toBe(['<div id="lobby">LOBBY PAGE</div>']);
        expect(sharedKeyElements($side))->toBe(['<div id="sidebar">SIDEBAR</div>']);
    });

    test('two components in one primary scope keep their own update render', function (): void {
        $app = sharedKeyApp();
        $page = new Context('/room_/a', '/room', $app);
        $page->component(function (Context $c): void {
            $c->scope('room:lobby');
            $c->view(fn (): string => '<p>MEMBERS</p>');
        }, 'members');
        $page->component(function (Context $c): void {
            $c->scope('room:lobby');
            $c->view(fn (): string => '<p>MESSAGES</p>');
        }, 'messages');

        [$members, $messages] = array_values($page->getComponentRegistry());

        expect($members->renderView(isUpdate: true))->toBe('<p>MEMBERS</p>');
        expect($messages->renderView(isUpdate: true))->toBe('<p>MESSAGES</p>');
    });

    test('contexts of one route still share the render', function (): void {
        $app = sharedKeyApp();
        $renders = 0;
        $make = function (string $id) use ($app, &$renders): Context {
            $c = new Context($id, '/lobby', $app);
            $c->scope('room:lobby');
            $c->view(function () use (&$renders): string {
                ++$renders;

                return '<div id="lobby">LOBBY</div>';
            });

            return $c;
        };

        $make('/lobby_/a')->renderView(isUpdate: true);
        $make('/lobby_/b')->renderView(isUpdate: true);

        expect($renders)->toBe(1);
    });
});
