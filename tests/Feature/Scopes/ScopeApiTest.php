<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;

/*
 * Scope API Tests
 *
 * Tests the scope system behavior.
 */

describe('Scope Constants', function (): void {
    test('scope constants have correct values', function (): void {
        expect(Scope::TAB)->toBe('tab');
        expect(Scope::ROUTE)->toBe('route');
        expect(Scope::SESSION)->toBe('session');
        expect(Scope::GLOBAL)->toBe('global');
    });
});

describe('Default Scope', function (): void {
    test('default scope is TAB', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        // No explicit scope set
        expect($context->getPrimaryScope())->toBe(Scope::TAB);
    });

    test('signals use TAB scope by default', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->signal('value', 'test');

        expect($context->getPrimaryScope())->toBe(Scope::TAB);
    });
});

describe('Setting Scopes', function (): void {
    test('can set route scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/game', $app);

        $context->scope(Scope::ROUTE);

        expect($context->getPrimaryScope())->toContain('route');
    });

    test('can set global scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/notifications', $app);

        $context->scope(Scope::GLOBAL);

        expect($context->getPrimaryScope())->toBe(Scope::GLOBAL);
    });

    test('can set session scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/profile', $app);

        $context->scope(Scope::SESSION);

        $scopes = $context->getScopes();
        expect($scopes)->toContain(Scope::SESSION);
    });

    test('can set custom scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/chat', $app);

        $context->scope('room:lobby');

        expect($context->getScopes())->toContain('room:lobby');
    });
});

describe('Scope Behavior', function (): void {
    test('scope() replaces existing scopes', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->scope(Scope::ROUTE);
        $context->scope(Scope::GLOBAL);

        // Last scope set replaces previous one
        expect($context->getPrimaryScope())->toBe(Scope::GLOBAL);
        expect($context->getScopes())->toHaveCount(1);
    });

    test('addScope() adds multiple scopes', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/chat', $app);

        $context->scope('room:lobby');
        $context->addScope(Scope::SESSION);

        $scopes = $context->getScopes();
        expect($scopes)->toHaveCount(2);
        expect($scopes)->toContain('room:lobby');
        expect($scopes)->toContain(Scope::SESSION);
    });

    test('addScope() does not add duplicate scopes', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->scope(Scope::GLOBAL);
        $context->addScope(Scope::GLOBAL);

        // Should only have one scope, not two
        expect($context->getScopes())->toHaveCount(1);
    });

    test('scopes are returned as array', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->scope(Scope::ROUTE);

        expect($context->getScopes())->toBeArray();
    });
});

describe('Scope Helpers', function (): void {
    test('hasScope checks if context has specific scope', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->scope(Scope::ROUTE);

        expect($context->getPrimaryScope())->toContain('route');
    });

    test('getScopes returns all scopes', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/test', $app);

        $context->scope('custom:scope');
        $scopes = $context->getScopes();

        expect($scopes)->toBeArray();
        expect($scopes)->toContain('custom:scope');
    });
});

describe('Custom Scopes', function (): void {
    test('supports hierarchical custom scopes', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/stock', $app);

        $context->scope('topic:stock:AAPL');

        expect($context->getScopes())->toContain('topic:stock:AAPL');
    });

    test('supports room-based scopes', function (): void {
        $app = createVia();
        $context = new Context(testContextId(), '/chat', $app);

        $context->scope('room:general');

        expect($context->getScopes())->toContain('room:general');
    });
});
