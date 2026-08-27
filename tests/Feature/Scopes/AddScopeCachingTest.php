<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * addScope() and the view cache
 *
 * `ViewRenderer::renderView()` gates caching on `$scope !== Scope::TAB`, where `$scope` is the
 * context's PRIMARY scope. `addScope()` joins a broadcast channel but never changes the primary
 * scope, so a context that calls `addScope(...)` without `scope(...)` stays TAB-primary and opts
 * out of the whole view-cache mechanism — invisibly.
 *
 * That opt-out is CORRECT for every current caller: their views embed per-context data (TAB signal
 * ids, per-user names, per-context viewports), so a shared cache entry would serve one client's
 * HTML to another. `b0b8dda` already fixed exactly that leak once, for the scope-comparison demo.
 *
 * What is wrong is that the opt-out is silent: nothing tells an author that joining a broadcast
 * channel left every member of it re-rendering. These tests pin the behaviour and require the
 * diagnostic.
 */

/** Render an update and return everything the logger echoed while doing it. */
function captureRenderLog(Context $context): string {
    ob_start();

    try {
        $context->renderView(isUpdate: true);

        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

function viaWithWarnings(): Via {
    return new Via((new Config())->withLogLevel('warn'));
}

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

describe('the silent cache opt-out is reported', function (): void {
    test('an update render warns when a signal-less cacheable view is TAB-primary in a shared scope', function (): void {
        $app = viaWithWarnings();
        $c = new Context('ctx1', '/room', $app);
        $c->addScope('room:lobby');
        $c->view(fn (): string => '<div>hi</div>');

        $log = captureRenderLog($c);

        expect($log)->toContain('room:lobby');
        expect($log)->toContain('/room');
        expect(mb_strtolower($log))->toContain('cacheupdates');
    });

    test('no warning when the context declares a TAB signal', function (): void {
        // A TAB signal is near-proof the render differs per client, which is the shape of every
        // current caller (chat-room, spreadsheet, composition, …). Warning there would be noise.
        $app = viaWithWarnings();
        $c = new Context('ctx1', '/pertab', $app);
        $c->addScope('room:lobby');
        $c->signal('', 'messageInput', Scope::TAB);
        $c->view(fn (): string => '<div>hi</div>');

        expect(captureRenderLog($c))->toBe('');
    });

    test('the warning is emitted once per route, not once per render', function (): void {
        $app = viaWithWarnings();

        $first = new Context('ctx1', '/room', $app);
        $first->addScope('room:lobby');
        $first->view(fn (): string => '<div>hi</div>');

        $second = new Context('ctx2', '/room', $app);
        $second->addScope('room:lobby');
        $second->view(fn (): string => '<div>hi</div>');

        expect(captureRenderLog($first))->toContain('room:lobby');
        expect(captureRenderLog($first))->toBe('');
        expect(captureRenderLog($second))->toBe('');
    });

    test('no warning when the view declares cacheUpdates: false', function (): void {
        $app = viaWithWarnings();
        $c = new Context('ctx1', '/optout', $app);
        $c->addScope('room:lobby');
        $c->view(fn (): string => '<div>hi</div>', cacheUpdates: false);

        expect(captureRenderLog($c))->toBe('');
    });

    test('no warning when the context has a real primary scope', function (): void {
        $app = viaWithWarnings();
        $c = new Context('ctx1', '/scoped', $app);
        $c->scope('room:lobby');
        $c->view(fn (): string => '<div>hi</div>');

        expect(captureRenderLog($c))->toBe('');
    });

    test('no warning for a plain TAB context that never joined a shared scope', function (): void {
        $app = viaWithWarnings();
        $c = new Context('ctx1', '/plain', $app);
        $c->view(fn (): string => '<div>hi</div>');

        expect(captureRenderLog($c))->toBe('');
    });

    test('no warning on an initial page load', function (): void {
        $app = viaWithWarnings();
        $c = new Context('ctx1', '/room', $app);
        $c->addScope('room:lobby');
        $c->view(fn (): string => '<div>hi</div>');

        ob_start();

        try {
            $c->renderView(isUpdate: false);
            $log = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        expect($log)->toBe('');
    });
});
