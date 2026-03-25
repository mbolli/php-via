<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

/*
 * Session Data Tests.
 *
 * Tests per-session key-value storage across Via (facade), Application (storage),
 * and Context (convenience methods).
 */
describe('Session Data Management', function (): void {
    describe('Via API', function (): void {
        test('can set and get session data', function (): void {
            $app = createVia();
            $app->setSessionData('ses_abc', 'cart', ['item1', 'item2']);

            expect($app->getSessionData('ses_abc', 'cart'))->toBe(['item1', 'item2']);
        });

        test('returns null default for missing key', function (): void {
            $app = createVia();

            expect($app->getSessionData('ses_abc', 'missing'))->toBeNull();
        });

        test('returns custom default for missing key', function (): void {
            $app = createVia();

            expect($app->getSessionData('ses_abc', 'missing', 'fallback'))->toBe('fallback');
        });

        test('can overwrite session data', function (): void {
            $app = createVia();
            $app->setSessionData('ses_abc', 'step', 1);
            $app->setSessionData('ses_abc', 'step', 2);

            expect($app->getSessionData('ses_abc', 'step'))->toBe(2);
        });

        test('sessions are isolated from each other', function (): void {
            $app = createVia();
            $app->setSessionData('ses_aaa', 'counter', 10);
            $app->setSessionData('ses_bbb', 'counter', 99);

            expect($app->getSessionData('ses_aaa', 'counter'))->toBe(10);
            expect($app->getSessionData('ses_bbb', 'counter'))->toBe(99);
        });

        test('clearSessionData with key removes only that key', function (): void {
            $app = createVia();
            $app->setSessionData('ses_abc', 'a', 1);
            $app->setSessionData('ses_abc', 'b', 2);
            $app->clearSessionData('ses_abc', 'a');

            expect($app->getSessionData('ses_abc', 'a'))->toBeNull();
            expect($app->getSessionData('ses_abc', 'b'))->toBe(2);
        });

        test('clearSessionData with null removes entire session bucket', function (): void {
            $app = createVia();
            $app->setSessionData('ses_abc', 'a', 1);
            $app->setSessionData('ses_abc', 'b', 2);
            $app->clearSessionData('ses_abc');

            expect($app->getSessionData('ses_abc', 'a'))->toBeNull();
            expect($app->getSessionData('ses_abc', 'b'))->toBeNull();
        });

        test('supports different value types', function (): void {
            $app = createVia();
            $app->setSessionData('ses_abc', 'string', 'hello');
            $app->setSessionData('ses_abc', 'int', 42);
            $app->setSessionData('ses_abc', 'float', 3.14);
            $app->setSessionData('ses_abc', 'bool', true);
            $app->setSessionData('ses_abc', 'array', ['x' => 1]);

            expect($app->getSessionData('ses_abc', 'string'))->toBe('hello');
            expect($app->getSessionData('ses_abc', 'int'))->toBe(42);
            expect($app->getSessionData('ses_abc', 'float'))->toBe(3.14);
            expect($app->getSessionData('ses_abc', 'bool'))->toBe(true);
            expect($app->getSessionData('ses_abc', 'array'))->toBe(['x' => 1]);
        });
    });

    describe('Context convenience API', function (): void {
        test('context with session can set and read session data', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, 'ses_test');
            $context->setSessionData('wizard', ['step' => 2]);

            expect($context->sessionData('wizard'))->toBe(['step' => 2]);
        });

        test('context without session returns default from sessionData()', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, null);

            expect($context->sessionData('key', 'fallback'))->toBe('fallback');
        });

        test('context without session returns null default from sessionData()', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, null);

            expect($context->sessionData('key'))->toBeNull();
        });

        test('context without session setSessionData is a no-op', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, null);
            $context->setSessionData('key', 'value');

            expect($context->sessionData('key'))->toBeNull();
        });

        test('context without session clearSessionData is a no-op', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, null);

            // Must not throw
            $context->clearSessionData('key');
            $context->clearSessionData();

            expect(true)->toBeTrue();
        });

        test('clearSessionData removes specific key only', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, 'ses_xyz');
            $context->setSessionData('cart', ['item1']);
            $context->setSessionData('prefs', ['theme' => 'dark']);
            $context->clearSessionData('cart');

            expect($context->sessionData('cart'))->toBeNull();
            expect($context->sessionData('prefs'))->toBe(['theme' => 'dark']);
        });

        test('clearSessionData with null clears all session data', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, 'ses_xyz');
            $context->setSessionData('cart', ['item1']);
            $context->setSessionData('prefs', ['theme' => 'dark']);
            $context->clearSessionData();

            expect($context->sessionData('cart'))->toBeNull();
            expect($context->sessionData('prefs'))->toBeNull();
        });

        test('two contexts sharing same session see the same data', function (): void {
            $app = createVia();
            $c1 = new Context(testContextId(), '/test', $app, null, 'shared_ses');
            $c2 = new Context(testContextId(), '/test', $app, null, 'shared_ses');
            $c1->setSessionData('shared_key', 'shared_value');

            expect($c2->sessionData('shared_key'))->toBe('shared_value');
        });

        test('contexts with different sessions are isolated', function (): void {
            $app = createVia();
            $c1 = new Context(testContextId(), '/test', $app, null, 'ses_1');
            $c2 = new Context(testContextId(), '/test', $app, null, 'ses_2');
            $c1->setSessionData('key', 'value1');

            expect($c2->sessionData('key'))->toBeNull();
        });

        test('data written via Via facade is readable via Context', function (): void {
            $app = createVia();
            $context = new Context(testContextId(), '/test', $app, null, 'ses_shared');
            $app->setSessionData('ses_shared', 'token', 'abc123');

            expect($context->sessionData('token'))->toBe('abc123');
        });
    });
});
