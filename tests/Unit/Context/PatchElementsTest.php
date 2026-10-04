<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Context\PatchManager;
use Mbolli\PhpVia\PatchMode;
use starfederation\datastar\enums\ElementPatchMode;

/** @return list<array<string, mixed>> every patch queued for $ctx, oldest first */
function patchElementsQueue(Context $ctx): array {
    $patches = [];
    while (($patch = $ctx->getPatch()) !== null) {
        unset($patch['confirm']);
        $patches[] = $patch;
    }

    return $patches;
}

function patchElementsComponent(Context $parent, string $namespace): Context {
    $parent->component(static fn (Context $c) => $c->view(static fn (): string => '<p>' . $namespace . '</p>'), $namespace);
    $components = $parent->getComponentRegistry();

    return end($components) ?: throw new LogicException('no component');
}

describe('Context::patchElements()', function (): void {
    test('queues an element patch with php-via\'s mode and the selector', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        $ctx->patchElements('<li>1</li>', '#log', PatchMode::Append);
        $ctx->patchElements('<div id="toast">Saved</div>');
        $ctx->patchElements(selector: '#toast', mode: PatchMode::Remove);

        expect(patchElementsQueue($ctx))->toBe([
            ['type' => 'elements', 'content' => '<li>1</li>', 'mode' => PatchMode::Append, 'selector' => '#log'],
            ['type' => 'elements', 'content' => '<div id="toast">Saved</div>', 'mode' => PatchMode::Outer],
            ['type' => 'elements', 'content' => '', 'mode' => PatchMode::Remove, 'selector' => '#toast'],
        ]);
    });

    test('rejects a selector with a line break, which would end the SSE data line', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        expect(fn () => $ctx->patchElements('<b>hi</b>', "#row-1\n\nevent: datastar-patch-signals", PatchMode::Append))
            ->toThrow(InvalidArgumentException::class, 'line break')
            ->and(fn () => $ctx->patchElements('<b>hi</b>', "#row-1\rdata: mode inner", PatchMode::Append))
            ->toThrow(InvalidArgumentException::class, 'line break')
        ;
        expect(patchElementsQueue($ctx))->toBe([]);
    });

    test('rejects a patch with neither HTML nor a selector', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        expect(fn () => $ctx->patchElements())->toThrow(InvalidArgumentException::class, 'needs HTML, a selector, or both')
            ->and(fn () => $ctx->patchElements('', '', PatchMode::Remove))->toThrow(InvalidArgumentException::class)
        ;
    });

    test('rejects a mode that Datastar cannot target without a selector', function (PatchMode $mode): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        expect(fn () => $ctx->patchElements('<li id="x">1</li>', null, $mode))
            ->toThrow(InvalidArgumentException::class, "PatchMode::{$mode->name} needs a selector")
        ;
    })->with([PatchMode::Inner, PatchMode::Prepend, PatchMode::Append, PatchMode::Before, PatchMode::After, PatchMode::Remove]);

    test('Outer and Replace find their target by id', function (PatchMode $mode): void {
        $ctx = new Context(testContextId(), '/test', createVia());

        $ctx->patchElements('<div id="x">1</div>', null, $mode);

        expect(patchElementsQueue($ctx))->toBe([['type' => 'elements', 'content' => '<div id="x">1</div>', 'mode' => $mode]]);
    })->with([PatchMode::Outer, PatchMode::Replace]);

    test('a component patches into its page\'s queue', function (): void {
        $page = new Context(testContextId(), '/test', createVia());
        $component = patchElementsComponent($page, 'chat');

        $component->patchElements('<li>hi</li>', '#messages', PatchMode::Append);

        expect(patchElementsQueue($page))->toBe([
            ['type' => 'elements', 'content' => '<li>hi</li>', 'mode' => PatchMode::Append, 'selector' => '#messages'],
        ]);
    });
});

describe('Eviction from a full queue', function (): void {
    test('an Append chunk outlives the element patches queued after it', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());
        $ctx->patchElements('<li>chunk 0</li>', '#export', PatchMode::Append);
        for ($i = 1; $i <= 50; ++$i) {
            $ctx->getPatchManager()->queuePatch(['type' => 'elements', 'content' => "<main id=\"page\">{$i}</main>"]);
        }

        $patches = patchElementsQueue($ctx);

        expect($patches)->toHaveCount(50)
            ->and($patches[0]['content'])->toBe('<li>chunk 0</li>')
            ->and($patches[1]['content'])->toBe('<main id="page">2</main>')
        ;
    });

    test('patchElements() patches are evicted after view frames and signal patches, then oldest first', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());
        $pm = $ctx->getPatchManager();
        for ($i = 0; $i < 47; ++$i) {
            $ctx->patchElements("<li>{$i}</li>", '#log', PatchMode::Append);
        }
        $pm->queuePatch(['type' => 'signals', 'content' => ['a' => 1]]);
        $pm->queuePatch(['type' => 'elements', 'content' => '<main id="page">1</main>']);
        $ctx->patchElements('<p>open</p>', '#modal', PatchMode::Inner);

        $ctx->patchElements('<li>47</li>', '#log', PatchMode::Prepend);
        $ctx->patchElements('<li>48</li>', '#log', PatchMode::Before);
        $ctx->patchElements('<li>49</li>', '#log', PatchMode::After);

        $contents = array_map(static fn (array $p): mixed => $p['content'], patchElementsQueue($ctx));

        expect($contents)->toHaveCount(50)
            ->and($contents)->not->toContain('<main id="page">1</main>')
            ->and($contents)->not->toContain(['a' => 1])
            ->and($contents)->toContain('<p>open</p>')
            ->and($contents[0])->toBe('<li>1</li>', 'the oldest chunk goes once nothing else is left')
            ->and(array_slice($contents, -3))->toBe(['<li>47</li>', '<li>48</li>', '<li>49</li>'])
        ;
    });

    test('a removal outside the view outlives the view frames queued after it', function (): void {
        $ctx = new Context(testContextId(), '/test', createVia());
        $ctx->patchElements('<div id="toast">Saved</div>', '#toasts', PatchMode::Append);
        $ctx->patchElements(selector: '#toast', mode: PatchMode::Remove);
        $ctx->patchElements('<div id="modal" hidden></div>');
        for ($i = 0; $i < 60; ++$i) {
            $ctx->getPatchManager()->queuePatch(['type' => 'elements', 'content' => "<main id=\"page\">{$i}</main>"]);
        }

        $modes = array_map(static fn (array $p): mixed => $p['mode'] ?? null, array_slice(patchElementsQueue($ctx), 0, 4));

        expect($modes)->toBe([PatchMode::Append, PatchMode::Remove, PatchMode::Outer, null]);
    });

    test('an Append queued with datastar-php\'s enum or a string is not evicted either', function (mixed $mode): void {
        $ctx = new Context(testContextId(), '/test', createVia());
        $pm = $ctx->getPatchManager();
        $pm->queuePatch(['type' => 'elements', 'content' => '<li>chunk</li>', 'selector' => '#log', 'mode' => $mode]);
        for ($i = 0; $i < 50; ++$i) {
            $pm->queuePatch(['type' => 'elements', 'content' => "<main id=\"page\">{$i}</main>"]);
        }

        expect(patchElementsQueue($ctx)[0]['content'])->toBe('<li>chunk</li>');
    })->with([
        'ElementPatchMode' => [ElementPatchMode::Append],
        'string' => ['append'],
    ]);
});

describe('PatchManager::isOneShot()', function (): void {
    test('names scripts and element patches that no render sends again', function (array $patch, bool $expected): void {
        expect(PatchManager::isOneShot($patch))->toBe($expected);
    })->with([
        'script' => [['type' => 'script', 'content' => 'x'], true],
        'signals' => [['type' => 'signals', 'content' => []], false],
        'page frame' => [['type' => 'elements', 'content' => '<main></main>'], false],
        'component frame' => [['type' => 'elements', 'content' => '<div id="c-x"></div>', 'selector' => '#c-x'], false],
        'Outer' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::Outer], true],
        'Inner' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::Inner], true],
        'Replace' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::Replace], true],
        'Remove' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::Remove], true],
        'datastar-php Remove' => [['type' => 'elements', 'content' => '', 'mode' => ElementPatchMode::Remove], true],
        'Append' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::Append], true],
        'Prepend' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::Prepend], true],
        'Before' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::Before], true],
        'After' => [['type' => 'elements', 'content' => '', 'mode' => PatchMode::After], true],
        'datastar-php Append' => [['type' => 'elements', 'content' => '', 'mode' => ElementPatchMode::Append], true],
        'string after' => [['type' => 'elements', 'content' => '', 'mode' => 'after'], true],
    ]);
});
