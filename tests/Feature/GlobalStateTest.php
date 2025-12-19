<?php

declare(strict_types=1);

/**
 * Global State Tests.
 *
 * Tests global state management across the app.
 */
describe('Global State Management', function (): void {
    test('can set and get global state', function (): void {
        $app = createVia();

        $app->setGlobalState('counter', 42);

        expect($app->globalState('counter'))->toBe(42);
    });

    test('returns default value for missing keys', function (): void {
        $app = createVia();

        $value = $app->globalState('missing', 'default');

        expect($value)->toBe('default');
    });

    test('can overwrite global state', function (): void {
        $app = createVia();

        $app->setGlobalState('value', 100);
        expect($app->globalState('value'))->toBe(100);

        $app->setGlobalState('value', 200);
        expect($app->globalState('value'))->toBe(200);
    });

    test('supports different data types', function (): void {
        $app = createVia();

        $app->setGlobalState('string', 'hello');
        $app->setGlobalState('int', 42);
        $app->setGlobalState('float', 3.14);
        $app->setGlobalState('bool', true);
        $app->setGlobalState('array', [1, 2, 3]);

        expect($app->globalState('string'))->toBe('hello');
        expect($app->globalState('int'))->toBe(42);
        expect($app->globalState('float'))->toBe(3.14);
        expect($app->globalState('bool'))->toBe(true);
        expect($app->globalState('array'))->toBe([1, 2, 3]);
    });
});
