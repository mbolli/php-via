<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * Regression tests for: explicit Scope::ROUTE was never expanded to route:<route>.
 *
 * createSignal() resolved Scope::SESSION to "session:<id>" but left an explicit
 * Scope::ROUTE as the literal string "route". The signal was then registered in a
 * "route" bucket that no context ever belongs to, so syncScopedSignals() — which
 * iterates the context's own scopes — never found it and no signal patch was ever
 * emitted. The literal bucket was also shared across every route, so two routes
 * using this form collided on signal ids.
 */

beforeEach(function (): void {
    $this->app = new Via(new Config());
});

test('explicit Scope::ROUTE resolves to the route-qualified scope', function (): void {
    $context = new Context('ctx1', '/dashboard', $this->app);
    $context->scope(Scope::ROUTE);

    $signal = $context->signal(0, 'hits', Scope::ROUTE);

    // Must land in the same bucket the context belongs to, not the literal "route".
    expect($this->app->getScopedSignal(Scope::routeScope('/dashboard'), $signal->id()))
        ->not->toBeNull()
    ;
    expect($this->app->getScopedSignal(Scope::ROUTE, $signal->id()))
        ->toBeNull()
    ;
});

test('explicit Scope::ROUTE signal is reachable from the context scopes', function (): void {
    $context = new Context('ctx1', '/dashboard', $this->app);
    $context->scope(Scope::ROUTE);

    $signal = $context->signal(7, 'hits', Scope::ROUTE);

    // This is what syncScopedSignals() does: walk the context's scopes and collect
    // their signals. Before the fix this yielded nothing, so no patch was emitted.
    $found = [];
    foreach ($context->getScopes() as $scope) {
        foreach ($this->app->getScopedSignals($scope) as $id => $scopedSignal) {
            $found[$id] = $scopedSignal->getValue();
        }
    }

    expect($found)->toHaveKey($signal->id());
    expect($found[$signal->id()])->toBe(7);
});

test('explicit Scope::ROUTE does not collide across different routes', function (): void {
    $a = new Context('ctxA', '/alpha', $this->app);
    $a->scope(Scope::ROUTE);
    $signalA = $a->signal(1, 'hits', Scope::ROUTE);

    $b = new Context('ctxB', '/beta', $this->app);
    $b->scope(Scope::ROUTE);
    $signalB = $b->signal(2, 'hits', Scope::ROUTE);

    expect($signalA->id())->not->toBe($signalB->id());

    $signalA->setValue(99);
    expect($signalB->getValue())->toBe(2);
});

test('explicit Scope::ROUTE matches the implicit inherited form', function (): void {
    $explicit = new Context('ctxE', '/same', $this->app);
    $explicit->scope(Scope::ROUTE);
    $explicitSignal = $explicit->signal(5, 'hits', Scope::ROUTE);

    // Inheriting from the context's primary scope is the idiomatic form and already
    // worked; both spellings must produce the same shared signal.
    $implicit = new Context('ctxI', '/same', $this->app);
    $implicit->scope(Scope::ROUTE);
    $implicitSignal = $implicit->signal(5, 'hits');

    expect($explicitSignal->id())->toBe($implicitSignal->id());
    expect($explicitSignal)->toBe($implicitSignal);
});
