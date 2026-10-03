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
    test('a callable view with block: throws and names the template form', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);

        expect(fn () => $ctx->view(fn () => $ctx->render('page.html.twig'), block: 'main'))
            ->toThrow(InvalidArgumentException::class, "view('template.html.twig', fn () => [...], block: 'main')")
        ;
    });

    test('a template view with a data callable renders the block on update', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('page.html.twig', fn (): array => [], block: 'main');

        expect($ctx->renderView(isUpdate: false))->toBe('<page><block-content/></page>');
        expect($ctx->renderView(isUpdate: true))->toBe('<block-content/>');
    });

    test('render() inside a callable view renders the whole template on update', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view(fn () => $ctx->render('page.html.twig'));

        expect($ctx->renderView(isUpdate: true))->toBe('<page><block-content/></page>');
    });
});

describe('Block: does not bleed between renders', function (): void {
    test('an initial render after an update renders the whole template', function (): void {
        $ctx = new Context('ctx1', '/test', $this->app);
        $ctx->view('page.html.twig', [], block: 'main');

        // Simulate update render followed by initial render
        $ctx->renderView(isUpdate: true);
        $html = $ctx->renderView(isUpdate: false);

        expect($html)->toBe('<page><block-content/></page>');
    });
});
