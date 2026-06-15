<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;

/*
 * Regression: a client posting signals with a numeric top-level key (e.g. a
 * JSON array) must not crash the worker. nestedToFlat() previously passed an
 * int as its string $prefix argument and threw a fatal TypeError.
 */

describe('SignalFactory::injectSignals() with numeric keys', function (): void {
    test('does not throw when injected data has integer top-level keys', function (): void {
        $app = createVia();
        $ctx = new Context('num_/1', '/demo', $app);

        // Simulates the corrupted client payload: a list nested as an object.
        $ctx->injectSignals([0 => ['a' => 1], 2 => ['b' => 2]]);

        // Reaching here without a TypeError is the assertion.
        expect(true)->toBeTrue();
    });

    test('still injects a normally-named signal', function (): void {
        $app = createVia();
        $ctx = new Context('num_/2', '/demo', $app);
        $signal = $ctx->signal('a', 'name');

        // The client posts signals keyed by their full id, not the name.
        $ctx->injectSignals([$signal->id() => 'b']);

        expect($signal->string())->toBe('b');
    });
});
