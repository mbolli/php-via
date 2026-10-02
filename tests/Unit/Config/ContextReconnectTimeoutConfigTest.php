<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Config::getContextReconnectTimeoutMs() bounds how long a context an action reached while its tab
 * had no stream waits for the reconnect, counted from the last action.
 */

describe('Config context reconnect timeout', function (): void {
    test('default timeout is 60 seconds, above Datastar\'s 30 second retryMaxWait', function (): void {
        expect((new Config())->getContextReconnectTimeoutMs())->toBe(60_000);
    });

    test('withContextReconnectTimeout() sets a custom timeout', function (): void {
        expect((new Config())->withContextReconnectTimeout(90_000)->getContextReconnectTimeoutMs())->toBe(90_000);
    });

    test('withContextReconnectTimeout() clamps negative values to 0', function (): void {
        expect((new Config())->withContextReconnectTimeout(-1)->getContextReconnectTimeoutMs())->toBe(0);
    });

    test('without a running server an action arms no timer', function (): void {
        $app = createVia();
        $app->armActionDeadline('revived-by-action');

        $timers = (new ReflectionProperty($app->getApp(), 'cleanupTimers'))->getValue($app->getApp());
        expect($timers)->toBe([]);
    });
});
