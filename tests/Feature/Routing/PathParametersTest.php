<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

/*
 * Path Parameters Tests
 *
 * Tests route parameter extraction and matching.
 */

describe('Route Matching', function (): void {
    test('exact route matches', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $matchMethod = $reflection->getMethod('isRouteMatch');
        $matchMethod->setAccessible(true);

        $params = [];
        $matches = $matchMethod->invokeArgs($router, ['/exact', '/exact', &$params]);

        expect($matches)->toBeTrue();
        expect($params)->toBe([]);
    });

    test('different routes do not match', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $matchMethod = $reflection->getMethod('isRouteMatch');
        $matchMethod->setAccessible(true);

        $params = [];
        $matches = $matchMethod->invokeArgs($router, ['/users', '/posts', &$params]);

        expect($matches)->toBeFalse();
    });
});

describe('Single Parameter', function (): void {
    test('extracts single parameter', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $matchMethod = $reflection->getMethod('isRouteMatch');
        $matchMethod->setAccessible(true);

        $params = [];
        $matches = $matchMethod->invokeArgs($router, ['/users/{id}', '/users/123', &$params]);

        expect($matches)->toBeTrue();
        expect($params)->toBe(['id' => '123']);
    });

    test('parameter values are strings', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $matchMethod = $reflection->getMethod('isRouteMatch');
        $matchMethod->setAccessible(true);

        $params = [];
        $matchMethod->invokeArgs($router, ['/items/{id}', '/items/999', &$params]);

        expect($params['id'])->toBeString();
        expect($params['id'])->toBe('999');
    });
});

describe('Multiple Parameters', function (): void {
    test('extracts multiple parameters', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $matchMethod = $reflection->getMethod('isRouteMatch');
        $matchMethod->setAccessible(true);

        $params = [];
        $matches = $matchMethod->invokeArgs($router, [
            '/blog/{year}/{month}/{slug}',
            '/blog/2024/12/hello',
            &$params,
        ]);

        expect($matches)->toBeTrue();
        expect($params)->toBe([
            'year' => '2024',
            'month' => '12',
            'slug' => 'hello',
        ]);
    });

    test('parameters with static segments', function (): void {
        $app = createVia();
        $router = $app->getRouter();
        $reflection = new ReflectionClass($router);
        $matchMethod = $reflection->getMethod('isRouteMatch');
        $matchMethod->setAccessible(true);

        $params = [];
        $matchMethod->invokeArgs($router, [
            '/products/{id}/reviews',
            '/products/laptop/reviews',
            &$params,
        ]);

        expect($params)->toBe(['id' => 'laptop']);
    });
});

describe('Context Path Parameters', function (): void {
    test('getPathParam returns injected parameter', function (): void {
        $app = createVia();
        $context = new Context('test', '/test', $app);

        $context->injectRouteParams(['username' => 'alice']);

        expect($context->getPathParam('username'))->toBe('alice');
    });

    test('getPathParam returns empty string for missing param', function (): void {
        $app = createVia();
        $context = new Context('test', '/test', $app);

        $context->injectRouteParams([]);

        expect($context->getPathParam('missing'))->toBe('');
    });
});
