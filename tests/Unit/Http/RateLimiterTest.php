<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\RateLimiter;

/*
 * RateLimiter semantics.
 *
 * The cross-process behaviour — the reason this class exists — is covered by
 * tests/Feature/ActionRateLimitTest.php, which forks real workers. These pin the
 * single-process semantics that the shared and unshared backends must both satisfy.
 */

/** @return array{allowed: int, denied: int} */
function hammer(RateLimiter $rl, string $ip, int $times, int $limit = 3, int $window = 60): array {
    $allowed = 0;
    for ($i = 0; $i < $times; ++$i) {
        if ($rl->allow($ip, $limit, $window)) {
            ++$allowed;
        }
    }

    return ['allowed' => $allowed, 'denied' => $times - $allowed];
}

dataset('limiters', [
    'per-process' => [fn (): RateLimiter => new RateLimiter(shared: false)],
    'shared table' => [fn (): RateLimiter => new RateLimiter(shared: true, maxRows: 128)],
]);

test('allows exactly the configured number of requests', function (Closure $make): void {
    $result = hammer($make(), '198.51.100.1', 10, limit: 3);

    expect($result['allowed'])->toBe(3);
    expect($result['denied'])->toBe(7);
})->with('limiters');

test('a denied request does not extend the lockout', function (Closure $make): void {
    $rl = $make();
    hammer($rl, '198.51.100.2', 3, limit: 3);

    // 50 rejected attempts must leave the recorded count at the limit, not at 53 —
    // otherwise a client hammering a closed door never recovers within the window.
    hammer($rl, '198.51.100.2', 50, limit: 3);

    // Raising the limit must immediately free exactly the extra headroom.
    expect(hammer($rl, '198.51.100.2', 10, limit: 5)['allowed'])->toBe(2);
})->with('limiters');

test('each IP gets its own budget', function (Closure $make): void {
    $rl = $make();

    expect(hammer($rl, '198.51.100.3', 5, limit: 3)['allowed'])->toBe(3);
    expect(hammer($rl, '198.51.100.4', 5, limit: 3)['allowed'])->toBe(3);
})->with('limiters');

test('the budget refills after the window passes', function (Closure $make): void {
    $rl = $make();

    expect(hammer($rl, '198.51.100.5', 5, limit: 2, window: 1)['allowed'])->toBe(2);

    // Two full windows: the current bucket is empty and the previous one has decayed away.
    usleep(2_100_000);

    expect(hammer($rl, '198.51.100.5', 5, limit: 2, window: 1)['allowed'])->toBe(2);
})->with('limiters');

test('a non-positive limit or window disables limiting', function (Closure $make): void {
    $rl = $make();

    expect(hammer($rl, '198.51.100.6', 50, limit: 0)['allowed'])->toBe(50);
    expect(hammer($rl, '198.51.100.7', 50, limit: -1)['allowed'])->toBe(50);
    expect(hammer($rl, '198.51.100.8', 50, limit: 3, window: 0)['allowed'])->toBe(50);
})->with('limiters');

test('the shared backend is only allocated when asked for', function (): void {
    expect((new RateLimiter(shared: false))->isShared())->toBeFalse();
    expect((new RateLimiter(shared: true, maxRows: 64))->isShared())->toBeTrue();
});

test('exhausting the store fails open rather than denying everyone', function (): void {
    // 64 rows requested; OpenSwoole rounds up and over-delivers, so drive well past it.
    $rl = new RateLimiter(shared: true, maxRows: 64);

    // Filling the store makes OpenSwoole emit "unable to allocate memory" as a PHP warning.
    // That is the handled condition under test, not a defect, so swallow it here.
    set_error_handler(static fn (): bool => true);

    try {
        $allowed = 0;
        for ($i = 0; $i < 4000; ++$i) {
            if ($rl->allow('203.0.113.' . $i, 1, 60)) {
                ++$allowed;
            }
        }
    } finally {
        restore_error_handler();
    }

    expect($rl->hasOverflowed())->toBeTrue('the store must actually have filled for this to test anything');
    expect($allowed)->toBe(4000, 'every distinct IP is on its first request, so none may be denied');
});
