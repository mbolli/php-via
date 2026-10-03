<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

/*
 * Truth tables for the Dev Bar config gates.
 *
 * The critical invariant: signal *writes* are hard-off whenever devMode is off,
 * even with an explicit withDevBarOptions(writes: true) AND VIA_DEVBAR_WRITES set.
 */

afterEach(function (): void {
    putenv('VIA_DEVBAR_WRITES');
});

describe('Config::withDevBar()', function (): void {
    test('defaults to devMode (off)', function (): void {
        expect((new Config())->freeze()->tracingEnabled)->toBeFalse();
    });

    test('follows devMode when not overridden', function (): void {
        expect((new Config())->withDevMode()->freeze()->tracingEnabled)->toBeTrue();
    });

    test('can be forced on outside devMode', function (): void {
        expect((new Config())->withDevBar(true)->freeze()->tracingEnabled)->toBeTrue();
    });

    test('can be forced off in devMode', function (): void {
        expect((new Config())->withDevMode()->withDevBar(false)->freeze()->tracingEnabled)->toBeFalse();
    });
});

describe('Config::withDevBarOptions()', function (): void {
    test('defaults: 100 traces, 100 ms poll', function (): void {
        $config = new Config();

        expect($config->freeze()->traceBufferSize)->toBe(100)
            ->and($config->freeze()->ssePollIntervalMs)->toBe(100)
        ;
    });

    test('sets traces and poll interval, at least 1', function (): void {
        expect((new Config())->withDevBarOptions(traces: 250, pollMs: 20)->freeze()->traceBufferSize)->toBe(250)
            ->and((new Config())->withDevBarOptions(traces: 250, pollMs: 20)->freeze()->ssePollIntervalMs)->toBe(20)
            ->and((new Config())->withDevBarOptions(traces: 0, pollMs: 0)->freeze()->traceBufferSize)->toBe(1)
            ->and((new Config())->withDevBarOptions(traces: 0, pollMs: 0)->freeze()->ssePollIntervalMs)->toBe(1)
        ;
    });

    test('a second call keeps what it does not name', function (): void {
        $settings = (new Config())->withDevMode()
            ->withDevBarOptions(traces: 300, pollMs: 40)
            ->withDevBarOptions(writes: true)
            ->withDevBarOptions(traces: 50)
            ->freeze()
        ;

        expect($settings->traceBufferSize)->toBe(50)
            ->and($settings->ssePollIntervalMs)->toBe(40)
            ->and($settings->tracingWritesEnabled())->toBeTrue()
        ;
    });
});

describe('Settings::tracingWritesEnabled()', function (): void {
    test('off by default', function (): void {
        expect((new Config())->withDevMode()->freeze()->tracingWritesEnabled())->toBeFalse();
    });

    test('on with devMode + explicit writes', function (): void {
        $config = (new Config())->withDevMode()->withDevBarOptions(writes: true);
        expect($config->freeze()->tracingWritesEnabled())->toBeTrue();
    });

    test('on with devMode + VIA_DEVBAR_WRITES env var', function (): void {
        putenv('VIA_DEVBAR_WRITES=1');
        $config = (new Config())->withDevMode()->withDevBar(true);
        expect($config->freeze()->tracingWritesEnabled())->toBeTrue();
    });

    test('an explicit writes: false wins over the env var', function (): void {
        putenv('VIA_DEVBAR_WRITES=1');
        $config = (new Config())->withDevMode()->withDevBarOptions(writes: false);
        expect($config->freeze()->tracingWritesEnabled())->toBeFalse();
    });

    test('HARD GUARD: a Dev Bar on outside devMode is read-only, even with explicit writes + env var', function (): void {
        putenv('VIA_DEVBAR_WRITES=1');
        $config = (new Config())
            ->withDevMode(false)
            ->withDevBar(true)
            ->withDevBarOptions(writes: true)
        ;

        expect($config->freeze()->tracingEnabled)->toBeTrue();
        expect($config->freeze()->tracingWritesEnabled())->toBeFalse();
    });

    test('stays off when the Dev Bar is off', function (): void {
        $config = (new Config())->withDevMode()->withDevBar(false)->withDevBarOptions(writes: true);
        expect($config->freeze()->tracingWritesEnabled())->toBeFalse();
    });
});
