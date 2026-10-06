<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\PatchMode;

// view(viewTransition:) and patchElements(viewTransition:) ask Datastar to apply a patch inside a view
// transition: true for the document, a selector for a transition scoped to that element.

/** @return list<array<string, mixed>> the element patches queued for $ctx, oldest first */
function viewTransitionQueue(Context $ctx): array {
    $patches = [];
    while (($patch = $ctx->getPatch()) !== null) {
        unset($patch['confirm']);
        if ($patch['type'] === 'elements') {
            $patches[] = $patch;
        }
    }

    return $patches;
}

describe('view(viewTransition:)', function (): void {
    test('an update render carries the transition the view asked for', function (bool|string $viewTransition): void {
        $ctx = new Context(testContextId(), '/test', createVia());
        $ctx->view(static fn (): string => '<main id="page">1</main>', viewTransition: $viewTransition);

        $ctx->sync();

        expect(viewTransitionQueue($ctx))->toBe([
            ['type' => 'elements', 'content' => '<main id="page">1</main>', 'viewTransition' => $viewTransition],
        ]);
    })->with([true, '#page']);

    test('a view without one queues no transition', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());
        $ctx->view(static fn (): string => '<main id="page">1</main>');

        $ctx->sync();

        expect(viewTransitionQueue($ctx))->toBe([['type' => 'elements', 'content' => '<main id="page">1</main>']]);
    });

    test('the render a stream sends when it connects has no transition, for the page and its components', function (): void {
        $page = new Context(testContextId(), '/test', createVia());
        $page->view(static fn (): string => '<main id="page">page</main>', viewTransition: true);
        $page->component(static fn (Context $c) => $c->view(static fn (): string => '<p>chat</p>', viewTransition: '#chat'), 'chat');

        $page->syncWithoutViewTransition();

        $patches = viewTransitionQueue($page);
        expect($patches)->not->toBeEmpty();
        foreach ($patches as $patch) {
            expect($patch)->not->toHaveKey('viewTransition');
        }
    });

    test('a component\'s update render carries its own transition', function (): void {
        $page = new Context(testContextId(), '/test', createVia());
        $page->view(static fn (): string => '<main id="page">page</main>');
        $page->component(static fn (Context $c) => $c->view(static fn (): string => '<p>chat</p>', viewTransition: '#chat'), 'chat');
        $components = $page->getComponentRegistry();
        $component = end($components) ?: throw new LogicException('no component');
        viewTransitionQueue($page);

        $component->sync();

        $patches = viewTransitionQueue($page);
        expect($patches)->toHaveCount(1)
            ->and($patches[0]['content'])->toContain('<p>chat</p>')
            ->and($patches[0]['viewTransition'])->toBe('#chat')
        ;
    });

    test('rejects an empty selector or one with a line break', function (string $selector): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        expect(fn () => $ctx->view(static fn (): string => '<main id="page"></main>', viewTransition: $selector))
            ->toThrow(InvalidArgumentException::class, 'viewTransition')
        ;
    })->with(['', '  ', "#page\ndata: mode inner"]);
});

describe('patchElements(viewTransition:)', function (): void {
    test('queues the transition with the patch', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        $ctx->patchElements('<div id="toast">Saved</div>', viewTransition: true);
        $ctx->patchElements('<li>1</li>', '#log', PatchMode::Append, viewTransition: '#log');

        expect(viewTransitionQueue($ctx))->toBe([
            ['type' => 'elements', 'content' => '<div id="toast">Saved</div>', 'mode' => PatchMode::Outer, 'viewTransition' => true],
            ['type' => 'elements', 'content' => '<li>1</li>', 'mode' => PatchMode::Append, 'selector' => '#log', 'viewTransition' => '#log'],
        ]);
    });

    test('rejects an empty selector or one with a line break', function (string $selector): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        expect(fn () => $ctx->patchElements('<b id="x">hi</b>', viewTransition: $selector))
            ->toThrow(InvalidArgumentException::class, 'viewTransition')
        ;
        expect(viewTransitionQueue($ctx))->toBe([]);
    })->with(['', "#x\r\ndata: selector body"]);

    test('the transition survives the hand-over to the worker a tab moved to', function (): void {
        $via = createVia();
        $from = new Context(testContextId(), '/test', $via);
        $from->patchElements('<div id="toast">Saved</div>', viewTransition: true);
        $from->patchElements('<li>1</li>', '#log', PatchMode::Append, viewTransition: '#log');
        $handed = json_decode((string) json_encode($from->getPatchManager()->takeOneShotPatches()), true);

        $to = new Context(testContextId(), '/test', $via);
        $via->contexts[$to->getId()] = $to;
        $via->queueHandedOverPatches($to->getId(), $handed);

        expect(viewTransitionQueue($to))->toBe([
            ['type' => 'elements', 'content' => '<div id="toast">Saved</div>', 'mode' => PatchMode::Outer, 'viewTransition' => true],
            ['type' => 'elements', 'content' => '<li>1</li>', 'selector' => '#log', 'mode' => PatchMode::Append, 'viewTransition' => '#log'],
        ]);
    });
});
