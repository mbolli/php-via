<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

/*
 * Parameter Injection Tests
 *
 * Tests automatic parameter injection into action handlers.
 */

describe('Basic Injection', function (): void {
    test('single parameter is injected', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $invokeMethod = $reflection->getMethod('invokeHandler');
        $invokeMethod->setAccessible(true);

        $captured = null;
        $handler = function (Context $c, string $username) use (&$captured): void {
            $captured = $username;
        };

        $context = new Context('test', '/test', $app);
        $params = ['username' => 'alice'];

        $invokeMethod->invokeArgs($router, [$handler, $context, $params]);

        expect($captured)->toBe('alice');
    });

    test('multiple parameters are injected', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $invokeMethod = $reflection->getMethod('invokeHandler');
        $invokeMethod->setAccessible(true);

        $captured = [];
        $handler = function (Context $c, string $year, string $month) use (&$captured): void {
            $captured = compact('year', 'month');
        };

        $context = new Context('test', '/test', $app);
        $params = ['year' => '2024', 'month' => '12'];

        $invokeMethod->invokeArgs($router, [$handler, $context, $params]);

        expect($captured)->toBe(['year' => '2024', 'month' => '12']);
    });

    test('context is always first parameter', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $invokeMethod = $reflection->getMethod('invokeHandler');
        $invokeMethod->setAccessible(true);

        $capturedContext = null;
        $handler = function (Context $c) use (&$capturedContext): void {
            $capturedContext = $c;
        };

        $context = new Context('test', '/test', $app);
        $params = [];

        $invokeMethod->invokeArgs($router, [$handler, $context, $params]);

        expect($capturedContext)->toBe($context);
    });
});

describe('Type Casting', function (): void {
    test('casts to int', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $invokeMethod = $reflection->getMethod('invokeHandler');
        $invokeMethod->setAccessible(true);

        $captured = null;
        $handler = function (Context $c, int $year) use (&$captured): void {
            $captured = $year;
        };

        $context = new Context('test', '/test', $app);
        $params = ['year' => '2024'];

        $invokeMethod->invokeArgs($router, [$handler, $context, $params]);

        expect($captured)->toBe(2024);
        expect($captured)->toBeInt();
    });

    test('casts to float', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $invokeMethod = $reflection->getMethod('invokeHandler');
        $invokeMethod->setAccessible(true);

        $captured = null;
        $handler = function (Context $c, float $price) use (&$captured): void {
            $captured = $price;
        };

        $context = new Context('test', '/test', $app);
        $params = ['price' => '19.99'];

        $invokeMethod->invokeArgs($router, [$handler, $context, $params]);

        expect($captured)->toBe(19.99);
        expect($captured)->toBeFloat();
    });
});

describe('Missing Parameters', function (): void {
    test('missing parameter gets empty string', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $invokeMethod = $reflection->getMethod('invokeHandler');
        $invokeMethod->setAccessible(true);

        $captured = null;
        $handler = function (Context $c, string $missing) use (&$captured): void {
            $captured = $missing;
        };

        $context = new Context('test', '/test', $app);
        $params = [];

        $invokeMethod->invokeArgs($router, [$handler, $context, $params]);

        expect($captured)->toBe('');
    });

    test('nullable parameter gets null', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $invokeMethod = $reflection->getMethod('invokeHandler');
        $invokeMethod->setAccessible(true);

        $captured = 'not-null';
        $handler = function (Context $c, ?string $missing) use (&$captured): void {
            $captured = $missing;
        };

        $context = new Context('test', '/test', $app);
        $params = [];

        $invokeMethod->invokeArgs($router, [$handler, $context, $params]);

        expect($captured)->toBeNull();
    });
});
