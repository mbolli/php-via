<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Signal;

/*
 * Signal Tests
 *
 * Tests the Signal class behavior.
 */

describe('Signal Creation', function (): void {
    test('signal has initial value', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('hello', 'greeting');

        expect($signal->getValue())->toBe('hello');
    });

    test('signal can store different types', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $string = $context->signal('text', 'str');
        $int = $context->signal(42, 'num');
        $array = $context->signal([1, 2, 3], 'arr');
        $bool = $context->signal(true, 'flag');

        expect($string->getValue())->toBe('text');
        expect($int->getValue())->toBe(42);
        expect($array->getValue())->toBeJson();
        expect($bool->getValue())->toBe(true);
    });
});

describe('Signal Updates', function (): void {
    test('can update signal value', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal(0, 'counter');

        expect($signal->getValue())->toBe(0);

        $signal->setValue(5);

        expect($signal->getValue())->toBe(5);
    });

    test('multiple updates work', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('a', 'letter');

        $signal->setValue('b');
        expect($signal->getValue())->toBe('b');

        $signal->setValue('c');
        expect($signal->getValue())->toBe('c');
    });
});

describe('Signal Names', function (): void {
    test('signal has a name', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('value', 'mySignal');

        expect($signal->id())->toContain('mySignal');
    });

    test('signal generates name if not provided', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('value');

        expect($signal->id())->not->toBeEmpty();
    });
});

describe('Signal Scopes', function (): void {
    test('signals default to TAB scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('value', 'test');

        // TAB scope means it's context-specific, not shared
        expect($signal)->toBeInstanceOf(Signal::class);
    });

    test('can create signal with custom scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        // Create a signal with a custom scope
        $signal = $context->signal('shared', 'sharedSignal', 'room:lobby');

        expect($signal)->toBeInstanceOf(Signal::class);
    });
});

describe('Signal in Views', function (): void {
    test('signal value accessible in view', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $name = $context->signal('World', 'name');

        $context->view(fn () => '<div>Hello ' . $name->getValue() . '</div>');

        $html = $context->renderView();

        expect($html)->toContain('Hello World');
    });

    test('updated signal reflects in view', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $count = $context->signal(0, 'count');

        $context->view(fn () => '<div>Count: ' . $count->getValue() . '</div>');

        $html1 = $context->renderView();
        expect($html1)->toContain('Count: 0');

        $count->setValue(5);

        $html2 = $context->renderView();
        expect($html2)->toContain('Count: 5');
    });
});

describe('Signal ID', function (): void {
    test('signal ID can be retrieved', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('test', 'mySignal');

        expect($signal->id())->toContain('mySignal');
    });
});

describe('Signal Name Validation', function (): void {
    test('signal names with invalid characters are sanitized', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        // Create signals with various invalid characters
        $signal1 = $context->signal('value', 'signal:with:colons');
        $signal2 = $context->signal('value', 'signal-with-dashes');
        $signal3 = $context->signal('value', 'signal.with.dots');
        $signal4 = $context->signal('value', 'signal with spaces');
        $signal5 = $context->signal('value', 'signal@special#chars');

        // All invalid characters should be replaced with underscores
        expect($signal1->id())->not->toContain(':');
        expect($signal2->id())->not->toContain('-');
        expect($signal3->id())->not->toContain('.');
        expect($signal4->id())->not->toContain(' ');
        expect($signal5->id())->not->toContain('@');
        expect($signal5->id())->not->toContain('#');

        // Valid characters (alphanumeric and underscore) should remain
        expect($signal1->id())->toMatch('/^[a-zA-Z0-9_]+$/');
    });

    test('scoped signal IDs sanitize scope names with invalid characters', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        // Create a signal with a scope containing colons (like 'stock:NFLX')
        $signal = $context->signal('value', 'price', 'stock:NFLX');

        // The signal ID should have colons replaced with underscores
        $signalId = $signal->id();
        expect($signalId)->not->toContain(':');
        expect($signalId)->toMatch('/^[a-zA-Z0-9_]+$/');
    });

    test('signal names can contain alphanumeric and underscores', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $signal = $context->signal('value', 'valid_signal_name_123');

        expect($signal->id())->toContain('valid_signal_name_123');
    });
});
