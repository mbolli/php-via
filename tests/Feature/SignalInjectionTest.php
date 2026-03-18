<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * Signal Injection / clientWritable Tests
 *
 * Verifies that injectSignals() respects the security boundary between
 * TAB-scoped (always client-writable) and scoped signals (server-authoritative
 * by default, opt-in with clientWritable: true).
 */

describe('TAB Signal Injection', function (): void {
    test('TAB signals accept client injection', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $count = $ctx->signal(0, 'count');

        $ctx->injectSignals([$count->id() => 42]);

        expect($count->getValue())->toBe(42);
    });

    test('TAB signals are always client-writable', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $signal = $ctx->signal('hello', 'greeting');

        expect($signal->isClientWritable())->toBeTrue();
    });
});

describe('Scoped Signal Injection', function (): void {
    test('scoped signals reject injection by default', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $ctx->scope(Scope::ROUTE);
        $count = $ctx->signal(0, 'count'); // inherits ROUTE scope

        $ctx->injectSignals([$count->id() => 99]);

        expect($count->getValue())->toBe(0);
    });

    test('scoped signals accept injection when clientWritable: true', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $ctx->scope(Scope::ROUTE);
        $note = $ctx->signal('', 'note', clientWritable: true);

        $ctx->injectSignals([$note->id() => 'hello']);

        expect($note->getValue())->toBe('hello');
    });

    test('default scoped signal is not client-writable', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $ctx->scope(Scope::ROUTE);
        $signal = $ctx->signal(0, 'count');

        expect($signal->isClientWritable())->toBeFalse();
    });

    test('scoped signal with clientWritable: true is client-writable', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $ctx->scope(Scope::ROUTE);
        $signal = $ctx->signal('', 'note', clientWritable: true);

        expect($signal->isClientWritable())->toBeTrue();
    });

    test('injection of unknown signal IDs is silently ignored', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $count = $ctx->signal(0, 'count');

        // Injecting a completely unknown signal ID should not throw
        $ctx->injectSignals(['nonexistent_signal_99' => 42]);

        // Known signal is unaffected
        expect($count->getValue())->toBe(0);
    });

    test('shared scoped signal from one context rejects injection via other context', function (): void {
        $app = createVia();

        $ctx1 = new Context('ctx1', '/test', $app);
        $ctx1->scope(Scope::ROUTE);
        $shared = $ctx1->signal(0, 'shared');

        $ctx2 = new Context('ctx2', '/test', $app);
        $ctx2->scope(Scope::ROUTE);
        $ctx2->signal(0, 'shared'); // returns same signal object

        // Neither context allows injection for this server-authoritative signal
        $ctx2->injectSignals([$shared->id() => 99]);

        expect($shared->getValue())->toBe(0);
    });
});
