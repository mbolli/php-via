<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

describe('Cookie reading', function (): void {
    test('cookie() returns null when no cookies set', function (): void {
        $context = new Context(testContextId(), '/test', createVia());

        expect($context->cookie('foo'))->toBeNull();
    });

    test('cookie() returns null for missing cookie after setRequestCookies', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->setRequestCookies(['bar' => 'baz']);

        expect($context->cookie('foo'))->toBeNull();
    });

    test('cookie() returns string value for present cookie', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->setRequestCookies(['token' => 'abc123', 'lang' => 'en']);

        expect($context->cookie('token'))->toBe('abc123');
        expect($context->cookie('lang'))->toBe('en');
    });

    test('setRequestCookies replaces previous cookies', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->setRequestCookies(['a' => '1']);
        $context->setRequestCookies(['b' => '2']);

        expect($context->cookie('a'))->toBeNull();
        expect($context->cookie('b'))->toBe('2');
    });
});

describe('Cookie writing', function (): void {
    test('flushPendingCookies returns empty array initially', function (): void {
        $context = new Context(testContextId(), '/test', createVia());

        expect($context->flushPendingCookies())->toBe([]);
    });

    test('setCookie queues a cookie with defaults', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->setCookie('foo', 'bar');

        $cookies = $context->flushPendingCookies();

        expect($cookies)->toHaveCount(1);
        expect($cookies[0]['name'])->toBe('foo');
        expect($cookies[0]['value'])->toBe('bar');
        expect($cookies[0]['expires'])->toBe(0);
        expect($cookies[0]['path'])->toBe('/');
        expect($cookies[0]['domain'])->toBe('');
        expect($cookies[0]['secure'])->toBeTrue();
        expect($cookies[0]['httpOnly'])->toBeTrue();
        expect($cookies[0]['sameSite'])->toBe('Lax');
    });

    test('setCookie queues a cookie with custom options', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $expires = time() + 3600;
        $context->setCookie('token', 'abc', expires: $expires, path: '/app', domain: 'example.com', secure: false, httpOnly: false, sameSite: 'Strict');

        $cookies = $context->flushPendingCookies();

        expect($cookies[0]['expires'])->toBe($expires);
        expect($cookies[0]['path'])->toBe('/app');
        expect($cookies[0]['domain'])->toBe('example.com');
        expect($cookies[0]['secure'])->toBeFalse();
        expect($cookies[0]['httpOnly'])->toBeFalse();
        expect($cookies[0]['sameSite'])->toBe('Strict');
    });

    test('multiple setCookie calls are all queued', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->setCookie('a', '1');
        $context->setCookie('b', '2');
        $context->setCookie('c', '3');

        expect($context->flushPendingCookies())->toHaveCount(3);
    });

    test('flushPendingCookies clears the queue', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->setCookie('foo', 'bar');

        $context->flushPendingCookies(); // first flush
        expect($context->flushPendingCookies())->toBe([]); // second flush is empty
    });

    test('deleteCookie queues expiry=1 with empty value', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->deleteCookie('token');

        $cookies = $context->flushPendingCookies();

        expect($cookies)->toHaveCount(1);
        expect($cookies[0]['name'])->toBe('token');
        expect($cookies[0]['value'])->toBe('');
        expect($cookies[0]['expires'])->toBe(1);
    });

    test('deleteCookie uses custom path when provided', function (): void {
        $context = new Context(testContextId(), '/test', createVia());
        $context->deleteCookie('token', '/app');

        $cookies = $context->flushPendingCookies();

        expect($cookies[0]['path'])->toBe('/app');
    });
});
