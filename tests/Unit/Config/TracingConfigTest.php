<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Truth tables for the Dev Bar config gates.
 *
 * The critical invariant: signal *writes* are hard-off whenever devMode is off,
 * even with an explicit withTracingWrites(true) AND VIA_DEVBAR_WRITES set.
 */

afterEach(function (): void {
    putenv('VIA_DEVBAR_WRITES');
});

describe('Config::isTracingEnabled()', function (): void {
    test('defaults to devMode (off)', function (): void {
        expect((new Config())->isTracingEnabled())->toBeFalse();
    });

    test('follows devMode when not overridden', function (): void {
        expect((new Config())->withDevMode()->isTracingEnabled())->toBeTrue();
    });

    test('can be forced on independently of devMode', function (): void {
        expect((new Config())->withTracing(true)->isTracingEnabled())->toBeTrue();
    });

    test('can be forced off even in devMode', function (): void {
        expect((new Config())->withDevMode()->withTracing(false)->isTracingEnabled())->toBeFalse();
    });
});

describe('Config::isTracingWritesEnabled()', function (): void {
    test('off by default', function (): void {
        expect((new Config())->withDevMode()->isTracingWritesEnabled())->toBeFalse();
    });

    test('on with devMode + explicit writes', function (): void {
        $config = (new Config())->withDevMode()->withTracingWrites(true);
        expect($config->isTracingWritesEnabled())->toBeTrue();
    });

    test('on with devMode + VIA_DEVBAR_WRITES env var', function (): void {
        putenv('VIA_DEVBAR_WRITES=1');
        $config = (new Config())->withDevMode()->withTracing(true);
        expect($config->isTracingWritesEnabled())->toBeTrue();
    });

    test('HARD GUARD: stays off in production even with explicit writes + env var', function (): void {
        putenv('VIA_DEVBAR_WRITES=1');
        $config = (new Config())
            ->withDevMode(false)
            ->withTracing(true)
            ->withTracingWrites(true)
        ;

        expect($config->isTracingEnabled())->toBeTrue();
        expect($config->isTracingWritesEnabled())->toBeFalse();
    });

    test('stays off when tracing is disabled', function (): void {
        $config = (new Config())->withDevMode()->withTracing(false)->withTracingWrites(true);
        expect($config->isTracingWritesEnabled())->toBeFalse();
    });
});
