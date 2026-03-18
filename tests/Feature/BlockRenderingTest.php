<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Twig\Loader\ArrayLoader;

/*
 * Block Rendering Tests
 *
 * Tests the block: parameter on view(), which tells the framework to render
 * only the named Twig block on SSE updates while rendering the full template
 * on the initial page load.
 */

beforeEach(function (): void {
    $this->app = createVia();

    // Register a simple test template with a named block
    $this->app->getTwig()->setLoader(new ArrayLoader([
        'page.html.twig' => '<page>{% block main %}<block-content/>{% endblock %}</page>',
        'multi.html.twig' => '<outer>{% block top %}<top/>{% endblock %}{% block body %}<body-content/>{% endblock %}</outer>',
    ]));
});

describe('Initial Render (isUpdate=false)', function (): void {
    test('returns full template on initial load regardless of block:', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('page.html.twig', [], block: 'main');

        $html = $ctx->renderView(isUpdate: false);

        expect($html)->toBe('<page><block-content/></page>');
    });

    test('returns full template without block: on initial load', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('page.html.twig', []);

        $html = $ctx->renderView(isUpdate: false);

        expect($html)->toBe('<page><block-content/></page>');
    });
});

describe('SSE Update Render (isUpdate=true)', function (): void {
    test('renders only named block on SSE update', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('page.html.twig', [], block: 'main');

        $html = $ctx->renderView(isUpdate: true);

        expect($html)->toBe('<block-content/>');
    });

    test('renders full template on SSE update when no block: set', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('page.html.twig', []);

        $html = $ctx->renderView(isUpdate: true);

        expect($html)->toBe('<page><block-content/></page>');
    });

    test('renders correct block from multi-block template', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('multi.html.twig', [], block: 'body');

        $html = $ctx->renderView(isUpdate: true);

        expect($html)->toBe('<body-content/>');
    });
});

describe('Callable View with block:', function (): void {
    test('callable view with block: renders block on update', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view(fn () => $ctx->render('page.html.twig'), block: 'main');

        $full = $ctx->renderView(isUpdate: false);
        $block = $ctx->renderView(isUpdate: true);

        expect($full)->toBe('<page><block-content/></page>');
        expect($block)->toBe('<block-content/>');
    });

    test('plain HTML callable ignores block: (no Twig template involved)', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        // Callable returns raw HTML — block: has no effect on non-Twig callables
        $ctx->view(fn () => '<div id="counter">0</div>', block: 'main');

        $full = $ctx->renderView(isUpdate: false);
        $update = $ctx->renderView(isUpdate: true);

        // Both return the same HTML — block: only applies when $c->render() is called
        expect($full)->toBe('<div id="counter">0</div>');
        expect($update)->toBe('<div id="counter">0</div>');
    });
});

describe('Block: does not bleed between renders', function (): void {
    test('isUpdating flag is reset after render', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('page.html.twig', [], block: 'main');

        // Simulate update render followed by initial render
        $ctx->renderView(isUpdate: true);
        $html = $ctx->renderView(isUpdate: false);

        expect($html)->toBe('<page><block-content/></page>');
    });
});
