<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * addScope() and the shared render
 *
 * addScope() joins a broadcast channel but never changes the primary scope, so a context that
 * calls addScope(...) without scope(...) stays TAB-primary and renders per context.
 */

describe('addScope() leaves the primary scope TAB', function (): void {
    test('a TAB-primary context that added a scope still renders per context', function (): void {
        $app = createVia();
        $renders = 0;

        $make = function (string $id) use ($app, &$renders): Context {
            $c = new Context($id, '/room', $app);
            $c->addScope('room:lobby');
            $c->view(function () use ($id, &$renders): string {
                ++$renders;

                return '<div>' . $id . '</div>';
            });

            return $c;
        };

        $a = $make('ctx-a');
        $b = $make('ctx-b');

        $htmlA = $a->renderView(isUpdate: true);
        $htmlB = $b->renderView(isUpdate: true);

        // Two renders, two distinct outputs — no cache entry is shared between them.
        expect($renders)->toBe(2, 'addScope() must not enable cross-context view sharing');
        expect($htmlA)->not->toBe($htmlB);
    });

    test('primary scope stays TAB after addScope()', function (): void {
        $app = createVia();
        $c = new Context('ctx1', '/room', $app);
        $c->addScope('room:lobby');

        expect($c->getPrimaryScope())->toBe(Scope::TAB);
        expect($c->getScopes())->toBe([Scope::TAB, 'room:lobby']);
    });
});
