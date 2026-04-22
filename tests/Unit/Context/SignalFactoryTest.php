<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

beforeEach(function (): void {
    $this->config = new Config();
    $this->app = new Via($this->config);
});

test('signal updates value when called multiple times with same name in TAB scope', function (): void {
    $context = new Context('ctx1', '/test', $this->app);

    $signal1 = $context->signal(100, 'price');
    expect($signal1->getValue())->toBe(100);

    $signal2 = $context->signal(200, 'price');
    expect($signal2->getValue())->toBe(200);
    expect($signal1->getValue())->toBe(200); // Same signal instance should update
    expect($signal1->id())->toBe($signal2->id());
});

test('signal updates value when called multiple times with same name in scoped context', function (): void {
    $context = new Context('ctx1', '/test', $this->app);
    $context->scope(Scope::build('stock', 'AAPL'));

    $signal1 = $context->signal(100.0, 'price');
    expect($signal1->getValue())->toBe(100.0);

    // Scoped signals are NOT overwritten by re-registration — the initial value
    // is only used on first creation. This prevents re-renders on one context
    // from resetting live shared state (e.g. a game board) to the initial value.
    $signal2 = $context->signal(200.0, 'price');
    expect($signal2->getValue())->toBe(100.0); // unchanged — returns existing signal as-is
    expect($signal1->getValue())->toBe(100.0);
    expect($signal1->id())->toBe($signal2->id()); // same instance
});

test('scoped signals are shared across contexts and update consistently', function (): void {
    $context1 = new Context('ctx1', '/test', $this->app);
    $context1->scope(Scope::build('stock', 'AAPL'));

    $context2 = new Context('ctx2', '/test', $this->app);
    $context2->scope(Scope::build('stock', 'AAPL'));

    // Create signal in first context
    $signal1 = $context1->signal(100.0, 'price');
    expect($signal1->getValue())->toBe(100.0);

    // Second context joins the scope — gets the existing signal, initial value ignored
    $signal2 = $context2->signal(150.0, 'price');
    expect($signal2->getValue())->toBe(100.0); // not 150 — live value preserved

    // Both reference the exact same signal instance
    expect($signal1->id())->toBe($signal2->id());

    // Explicit setValue() is the correct way to mutate a scoped signal
    $signal1->setValue(200.0);
    expect($signal1->getValue())->toBe(200.0);
    expect($signal2->getValue())->toBe(200.0); // same instance, reflects the update
});

test('signal updates with complex values like arrays', function (): void {
    $context = new Context('ctx1', '/test', $this->app);
    $context->scope(Scope::build('stock', 'AAPL'));

    $signal1 = $context->signal(['a', 'b'], 'data');
    // Signal stores complex values natively
    expect($signal1->getValue())->toEqual(['a', 'b']);

    // Re-registration returns same signal, initial value ignored
    $signal2 = $context->signal(['c', 'd', 'e'], 'data');
    expect($signal1->id())->toBe($signal2->id());
    expect($signal1->getValue())->toEqual(['a', 'b']); // unchanged

    // Explicit update works correctly
    $signal1->setValue(['c', 'd', 'e']);
    expect($signal1->getValue())->toEqual(['c', 'd', 'e']);
    expect($signal2->getValue())->toEqual(['c', 'd', 'e']);
});
