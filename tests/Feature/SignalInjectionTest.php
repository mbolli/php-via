<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * Signal Injection / clientWritable Tests
 *
 * Verifies that injectSignals() respects the security boundary between
 * TAB-scoped (client-writable by default) and scoped signals (server-authoritative
 * by default, opt-in with clientWritable: true), and reaches component signals.
 */

describe('TAB Signal Injection', function (): void {
    test('TAB signals accept client injection', function (): void {
        $app = createVia();
        $ctx = new Context('ctx1', '/test', $app);
        $count = $ctx->signal(0, 'count');

        $ctx->injectSignals([$count->id() => 42]);

        expect($count->getValue())->toBe(42);
    });

    test('TAB signals are client-writable by default', function (): void {
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

describe('Component Signal Injection', function (): void {
    test('a component TAB signal receives the client value before its action runs', function (): void {
        $page = new Context('page1', '/p', createVia());
        $seen = null;
        $page->component(function (Context $c) use (&$seen): void {
            $x = $c->signal('initial', 'x');
            $c->action(function () use ($x, &$seen): void {
                $seen = $x->getValue();
            }, 'read');
            $c->view(fn () => '<input ' . $x->bind() . '>');
        }, 'cmp');
        $component = array_values($page->getComponentManager()->getComponents())[0];

        $page->injectSignals([$component->getSignal('x')->id() => 'typed by user']);
        $page->executeAction('cmp-read');

        expect($seen)->toBe('typed by user');
    });

    test('a nested component TAB signal receives the client value', function (): void {
        $page = new Context('page1', '/p', createVia());
        $inner = null;
        $page->component(function (Context $outer) use (&$inner): void {
            $outer->component(function (Context $c) use (&$inner): void {
                $inner = $c->signal('initial', 'y');
                $c->view(fn () => '');
            }, 'inner');
            $outer->view(fn () => '');
        }, 'outer');

        $page->injectSignals([$inner->id() => 'typed']);

        expect($inner->getValue())->toBe('typed');
    });

    test('a component signal declared clientWritable: false ignores client values', function (): void {
        $page = new Context('page1', '/p', createVia());
        $owned = null;
        $free = null;
        $page->component(function (Context $c) use (&$owned, &$free): void {
            $owned = $c->signal('server', 'owned', clientWritable: false);
            $free = $c->signal('server', 'free');
            $c->view(fn () => '');
        }, 'cmp');

        $page->injectSignals([$owned->id() => 'client', $free->id() => 'client']);

        expect($owned->getValue())->toBe('server')
            ->and($free->getValue())->toBe('client')
        ;
    });
});
