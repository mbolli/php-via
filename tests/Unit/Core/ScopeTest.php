<?php

declare(strict_types=1);

use Mbolli\PhpVia\Scope;

/*
 * Scope Class Tests
 *
 * Tests the Scope utility class methods.
 */

describe('Scope Constants', function (): void {
    test('has TAB constant', function (): void {
        expect(Scope::TAB)->toBe('tab');
    });

    test('has ROUTE constant', function (): void {
        expect(Scope::ROUTE)->toBe('route');
    });

    test('has SESSION constant', function (): void {
        expect(Scope::SESSION)->toBe('session');
    });

    test('has GLOBAL constant', function (): void {
        expect(Scope::GLOBAL)->toBe('global');
    });
});

describe('Route Scope Helper', function (): void {
    test('routeScope() generates route-specific scope', function (): void {
        $scope = Scope::routeScope('/game');

        expect($scope)->toContain('route:');
        expect($scope)->toContain('/game');
    });

    test('routeScope() handles different routes', function (): void {
        $scope1 = Scope::routeScope('/game');
        $scope2 = Scope::routeScope('/chat');

        expect($scope1)->not->toBe($scope2);
    });

    test('routeScope() handles root route', function (): void {
        $scope = Scope::routeScope('/');

        expect($scope)->toContain('route:');
    });
});

describe('Scope Parsing', function (): void {
    test('parse() returns array for simple scope', function (): void {
        $parts = Scope::parse('tab');

        expect($parts)->toBeArray();
        expect($parts)->toContain('tab');
    });

    test('parse() handles scopes with colons', function (): void {
        $parts = Scope::parse('room:lobby');

        expect($parts)->toBeArray();
        expect($parts)->toHaveCount(2);
        expect($parts[0])->toBe('room');
        expect($parts[1])->toBe('lobby');
    });

    test('parse() handles hierarchical scopes', function (): void {
        $parts = Scope::parse('topic:stock:AAPL');

        expect($parts)->toBeArray();
        expect($parts)->toHaveCount(3);
        expect($parts[0])->toBe('topic');
        expect($parts[1])->toBe('stock');
        expect($parts[2])->toBe('AAPL');
    });
});

describe('Scope Type Checking', function (): void {
    test('isBuiltIn() returns true for built-in scopes', function (): void {
        expect(Scope::isBuiltIn(Scope::TAB))->toBeTrue();
        expect(Scope::isBuiltIn(Scope::ROUTE))->toBeTrue();
        expect(Scope::isBuiltIn(Scope::SESSION))->toBeTrue();
        expect(Scope::isBuiltIn(Scope::GLOBAL))->toBeTrue();
    });

    test('isBuiltIn() returns false for custom scopes', function (): void {
        expect(Scope::isBuiltIn('room:lobby'))->toBeFalse();
        expect(Scope::isBuiltIn('user:123'))->toBeFalse();
    });

    test('isBuiltIn() returns false for route-specific scopes', function (): void {
        expect(Scope::isBuiltIn('route:/game'))->toBeFalse();
    });
});

describe('Scope Matching', function (): void {
    test('matches() returns true for exact match', function (): void {
        expect(Scope::matches('room:lobby', 'room:lobby'))->toBeTrue();
        expect(Scope::matches(Scope::GLOBAL, Scope::GLOBAL))->toBeTrue();
    });

    test('matches() returns false for non-match', function (): void {
        expect(Scope::matches('room:lobby', 'room:general'))->toBeFalse();
        expect(Scope::matches(Scope::TAB, Scope::GLOBAL))->toBeFalse();
    });

    test('matches() supports wildcard patterns', function (): void {
        expect(Scope::matches('room:lobby', 'room:*'))->toBeTrue();
        expect(Scope::matches('room:general', 'room:*'))->toBeTrue();
        expect(Scope::matches('user:123', 'room:*'))->toBeFalse();
    });
});
