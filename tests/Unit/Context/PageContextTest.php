<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

function pageContextComponent(Context $parent, string $namespace): Context {
    $parent->component(static fn (Context $c) => $c->view(static fn (): string => '<p>' . $namespace . '</p>'), $namespace);
    $components = $parent->getComponentRegistry();

    return end($components) ?: throw new LogicException('no component');
}

describe('Context::isConnected() and getPageContext()', function (): void {
    test('a tab is connected while its stream is open on this worker, and its components with it', function (): void {
        $app = createVia();
        $page = new Context(testContextId(), '/test', $app);
        $component = pageContextComponent($page, 'side');

        expect($page->isConnected())->toBeFalse()
            ->and($component->isConnected())->toBeFalse()
        ;

        $app->activeSseCount[$page->getId()] = 1;

        expect($page->isConnected())->toBeTrue()
            ->and($component->isConnected())->toBeTrue()
        ;
    });

    test('the page context of a page is the page, and of a nested component the page it sits on', function (): void {
        $page = new Context(testContextId(), '/test', createVia());
        $outer = pageContextComponent($page, 'outer');
        $inner = pageContextComponent($outer, 'inner');

        expect($page->getPageContext())->toBe($page)
            ->and($outer->getPageContext())->toBe($page)
            ->and($inner->getPageContext())->toBe($page)
        ;
    });
});
