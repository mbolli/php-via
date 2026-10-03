<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

// Methods removed or renamed in 0.14 stay one release as stubs that throw and name the replacement.

dataset('removed config methods', [
    'withTracing' => [fn (Config $c) => $c->withTracing(true), 'withDevBar(true)'],
    'withTracingWrites' => [fn (Config $c) => $c->withTracingWrites(true), 'withDevBarOptions(writes: true)'],
    'withTraceBufferSize' => [fn (Config $c) => $c->withTraceBufferSize(200), 'withDevBarOptions(traces: $traces)'],
    'withSsePollIntervalMs' => [fn (Config $c) => $c->withSsePollIntervalMs(50), 'withDevBarOptions(pollMs: $ms)'],
    'getDevMode' => [fn (Config $c) => $c->getDevMode(), 'isDevMode()'],
    'withGcInterval' => [fn (Config $c) => $c->withGcInterval(1000), 'withGcIntervalMs($ms)'],
    'withContextCleanupDelay' => [fn (Config $c) => $c->withContextCleanupDelay(1), 'withContextTimeouts(cleanupDelayMs: $ms)'],
    'withContextConnectTimeout' => [fn (Config $c) => $c->withContextConnectTimeout(1), 'withContextTimeouts(connectMs: $ms)'],
    'withContextReconnectTimeout' => [fn (Config $c) => $c->withContextReconnectTimeout(1), 'withContextTimeouts(reconnectMs: $ms)'],
    'withContextRevivalWindow' => [fn (Config $c) => $c->withContextRevivalWindow(1), 'withContextTimeouts(revivalWindowMs: $ms)'],
]);

test('a removed Config method throws and names its replacement', function (Closure $call, string $replacement): void {
    expect(fn () => $call(new Config()))->toThrow(BadMethodCallException::class, $replacement);
})->with('removed config methods');

