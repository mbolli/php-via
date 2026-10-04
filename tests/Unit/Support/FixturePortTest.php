<?php

declare(strict_types=1);

use Tests\Support\FixturePort;

/** Run $fn with VIA_TEST_PORT_BASE and VIA_TEST_PORT_COUNT set to $base and $count (null unsets), then restore them. */
function withFixturePortEnv(?string $base, ?string $count, Closure $fn): mixed {
    $saved = ['VIA_TEST_PORT_BASE' => getenv('VIA_TEST_PORT_BASE'), 'VIA_TEST_PORT_COUNT' => getenv('VIA_TEST_PORT_COUNT')];
    putenv($base === null ? 'VIA_TEST_PORT_BASE' : "VIA_TEST_PORT_BASE={$base}");
    putenv($count === null ? 'VIA_TEST_PORT_COUNT' : "VIA_TEST_PORT_COUNT={$count}");

    try {
        return $fn();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
}

describe('FixturePort', function (): void {
    test('a fixture keeps its own window unless VIA_TEST_PORT_BASE is set', function (): void {
        expect(withFixturePortEnv(null, null, static fn () => FixturePort::window(3400, 150)))->toBe([3400, 150])
            ->and(withFixturePortEnv('4350', null, static fn () => FixturePort::window(3400, 150)))->toBe([4350, FixturePort::DEFAULT_COUNT])
            ->and(withFixturePortEnv('4350', '40', static fn () => FixturePort::window(3400, 150)))->toBe([4350, 40])
            ->and(fn () => withFixturePortEnv('43x', null, static fn () => FixturePort::window(3400, 150)))->toThrow(RuntimeException::class, 'VIA_TEST_PORT_BASE')
        ;
    });

    test('pick() takes a port inside the configured window and skips one that is listening', function (): void {
        // The suite's own window, so this binds only where the fixtures may.
        $port = FixturePort::pick(4330, 20);
        $holder = stream_socket_server("tcp://127.0.0.1:{$port}");
        expect($holder)->not->toBeFalse();

        try {
            expect(fn () => withFixturePortEnv((string) $port, '1', static fn () => FixturePort::pick(3400, 150)))
                ->toThrow(RuntimeException::class, "No free port in {$port}-{$port}")
            ;
        } finally {
            fclose($holder);
        }

        expect(withFixturePortEnv((string) $port, '1', static fn () => FixturePort::pick(3400, 150)))->toBe($port);
    });
});
