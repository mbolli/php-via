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

    $signal2 = $context->signal(200.0, 'price');
    expect($signal2->getValue())->toBe(200.0);
    expect($signal1->getValue())->toBe(200.0); // Same signal instance should update
    expect($signal1->id())->toBe($signal2->id());
});

test('scoped signals are shared across contexts and update consistently', function (): void {
    $context1 = new Context('ctx1', '/test', $this->app);
    $context1->scope(Scope::build('stock', 'AAPL'));

    $context2 = new Context('ctx2', '/test', $this->app);
    $context2->scope(Scope::build('stock', 'AAPL'));

    // Create signal in first context
    $signal1 = $context1->signal(100.0, 'price');
    expect($signal1->getValue())->toBe(100.0);

    // Access from second context
    $signal2 = $context2->signal(150.0, 'price');
    expect($signal2->getValue())->toBe(150.0);

    // Both should be the same signal and have updated value
    expect($signal1->id())->toBe($signal2->id());
    expect($signal1->getValue())->toBe(150.0);
    expect($signal2->getValue())->toBe(150.0);
});

test('signal updates with complex values like arrays', function (): void {
    $context = new Context('ctx1', '/test', $this->app);
    $context->scope(Scope::build('stock', 'AAPL'));

    $signal1 = $context->signal(['a', 'b'], 'data');
    // Signal stores complex values as JSON, so we compare the JSON representation
    expect(json_decode($signal1->getValue(), true))->toEqual(['a', 'b']);

    $signal2 = $context->signal(['c', 'd', 'e'], 'data');
    expect(json_decode($signal2->getValue(), true))->toEqual(['c', 'd', 'e']);
    expect(json_decode($signal1->getValue(), true))->toEqual(['c', 'd', 'e']);
});
