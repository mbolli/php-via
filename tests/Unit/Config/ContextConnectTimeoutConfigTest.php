<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Config::getContextConnectTimeoutMs() bounds how long a context without an SSE stream on its
 * worker lives: a page whose stream never connects, or a copy an action rebuilt on another worker.
 */

describe('Config context connect timeout', function (): void {
    test('default timeout is 30 seconds', function (): void {
        expect((new Config())->getContextConnectTimeoutMs())->toBe(30_000);
    });

    test('withContextConnectTimeout() sets a custom timeout', function (): void {
        expect((new Config())->withContextConnectTimeout(120_000)->getContextConnectTimeoutMs())->toBe(120_000);
    });

    test('withContextConnectTimeout(0) turns the timeout off', function (): void {
        expect((new Config())->withContextConnectTimeout(0)->getContextConnectTimeoutMs())->toBe(0);
    });

    test('withContextConnectTimeout() clamps negative values to 0', function (): void {
        expect((new Config())->withContextConnectTimeout(-1)->getContextConnectTimeoutMs())->toBe(0);
    });

    test('without a running server no timer is armed', function (): void {
        $app = createVia();
        $app->armConnectDeadline('never-connected');

        $timers = (new ReflectionProperty($app->getApp(), 'cleanupTimers'))->getValue($app->getApp());
        expect($timers)->toBe([]);
    });
});
