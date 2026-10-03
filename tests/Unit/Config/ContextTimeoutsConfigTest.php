<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

describe('Config::withContextTimeouts()', function (): void {
    test('defaults: 5 s cleanup delay, 30 s connect, 60 s reconnect (above Datastar\'s 30 s retryMaxWait), 10 min revival', function (): void {
        $config = new Config();

        expect($config->freeze()->contextCleanupDelayMs)->toBe(5000)
            ->and($config->freeze()->contextConnectTimeoutMs)->toBe(30_000)
            ->and($config->freeze()->contextReconnectTimeoutMs)->toBe(60_000)
            ->and($config->getContextRevivalWindowMs())->toBe(600_000)
        ;
    });

    test('sets each timer it is given', function (): void {
        $config = (new Config())->withContextTimeouts(cleanupDelayMs: 1000, connectMs: 2000, reconnectMs: 3000, revivalWindowMs: 4000);

        expect($config->freeze()->contextCleanupDelayMs)->toBe(1000)
            ->and($config->freeze()->contextConnectTimeoutMs)->toBe(2000)
            ->and($config->freeze()->contextReconnectTimeoutMs)->toBe(3000)
            ->and($config->getContextRevivalWindowMs())->toBe(4000)
        ;
    });

    test('keeps the timers it is not given, so a second call changes only what it names', function (): void {
        $config = (new Config())->withContextTimeouts(connectMs: 90_000)->withContextTimeouts(revivalWindowMs: 0);

        expect($config->freeze()->contextConnectTimeoutMs)->toBe(90_000)
            ->and($config->getContextRevivalWindowMs())->toBe(0)
            ->and($config->freeze()->contextCleanupDelayMs)->toBe(5000)
            ->and($config->freeze()->contextReconnectTimeoutMs)->toBe(60_000)
        ;
    });

    test('clamps negative values to 0', function (): void {
        $config = (new Config())->withContextTimeouts(-1, -1, -1, -1);

        expect($config->freeze()->contextCleanupDelayMs)->toBe(0)
            ->and($config->freeze()->contextConnectTimeoutMs)->toBe(0)
            ->and($config->freeze()->contextReconnectTimeoutMs)->toBe(0)
            ->and($config->getContextRevivalWindowMs())->toBe(0)
        ;
    });

    test('is fluent', function (): void {
        $config = new Config();

        expect($config->withContextTimeouts(cleanupDelayMs: 10))->toBe($config);
    });

    test('without a running server neither a page nor an action arms a timer', function (): void {
        $app = createVia();
        $app->armConnectDeadline('never-connected');
        $app->armActionDeadline('revived-by-action');

        $timers = (new ReflectionProperty($app->getApp(), 'cleanupTimers'))->getValue($app->getApp());
        expect($timers)->toBe([]);
    });
});
