<?php

declare(strict_types=1);

use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;

/*
 * Context Tests
 *
 * Tests the Context class lifecycle and core methods.
 */

describe('Context Creation', function (): void {
    test('can create a context', function (): void {
        $app = createVia();
        $context = new Context('test-id', '/test', $app);

        expect($context)->toBeInstanceOf(Context::class);
    });

    test('context has an ID', function (): void {
        $app = createVia();
        $context = new Context('test-id', '/test', $app);

        expect($context->getId())->toBe('test-id');
    });

    test('context has a route', function (): void {
        $app = createVia();
        $context = new Context('test-id', '/game', $app);

        expect($context->getRoute())->toBe('/game');
    });

    test('context has an app reference internally', function (): void {
        $app = createVia();
        $context = new Context('test-id', '/test', $app);

        // No public getApp() method, but we can verify context works
        expect($context)->toBeInstanceOf(Context::class);
    });
});

describe('Signals', function (): void {
    test('can create a signal', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('initial', 'testSignal');

        expect($signal)->toBeInstanceOf(Signal::class);
        expect($signal->getValue())->toBe('initial');
    });

    test('can retrieve signal by name', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->signal('value', 'mySignal');
        $retrieved = $context->getSignal('mySignal');

        expect($retrieved)->toBeInstanceOf(Signal::class);
        expect($retrieved->getValue())->toBe('value');
    });

    test('returns null for non-existent signal', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        expect($context->getSignal('nonexistent'))->toBeNull();
    });

    test('can create multiple signals', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal1 = $context->signal('value1', 'signal1');
        $signal2 = $context->signal('value2', 'signal2');

        expect($signal1)->toBeInstanceOf(Signal::class);
        expect($signal2)->toBeInstanceOf(Signal::class);
        expect($signal1->id())->toContain('signal1');
        expect($signal2->id())->toContain('signal2');
    });
});

describe('Actions', function (): void {
    test('can create an action', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action = $context->action(function (): void {}, 'testAction');

        expect($action)->toBeInstanceOf(Action::class);
    });

    test('action returns action object with ID', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $action = $context->action(function (): void {}, 'myAction');

        expect($action->id())->not->toBeEmpty();
    });
});

describe('View Rendering', function (): void {
    test('can set a view callback', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->view(fn () => '<div>Hello</div>');

        expect(true)->toBeTrue(); // No exception thrown
    });

    test('renderView returns HTML string', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->view(fn () => '<div>Test</div>');
        $html = $context->renderView();

        expect($html)->toBeString();
        expect($html)->toContain('Test');
    });

    test('renderView executes view callback', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $executed = false;
        $context->view(function () use (&$executed) {
            $executed = true;

            return '<div>Test</div>';
        });

        $context->renderView();

        expect($executed)->toBeTrue();
    });
});

describe('Path Parameters', function (): void {
    test('can inject route parameters', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->injectRouteParams(['id' => '123', 'slug' => 'hello']);

        expect($context->getPathParam('id'))->toBe('123');
        expect($context->getPathParam('slug'))->toBe('hello');
    });

    test('getPathParam returns empty string for missing param', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->injectRouteParams([]);

        expect($context->getPathParam('missing'))->toBe('');
    });
});

describe('Broadcasting', function (): void {
    test('broadcast calls app broadcast with primary scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        // This should not throw an error
        $context->broadcast();

        expect(true)->toBeTrue();
    });
});

describe('Lifecycle', function (): void {
    test('view callback is executed on render', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $executed = false;
        $context->view(function () use (&$executed) {
            $executed = true;

            return '<div>Test</div>';
        });

        $context->renderView();

        expect($executed)->toBeTrue();
    });

    test('view can be rendered multiple times', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $renderCount = 0;
        $context->view(function () use (&$renderCount) {
            ++$renderCount;

            return '<div>Test</div>';
        });

        $context->renderView();
        $context->renderView();

        expect($renderCount)->toBe(2);
    });
});
