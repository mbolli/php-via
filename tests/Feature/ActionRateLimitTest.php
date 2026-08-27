<?php

declare(strict_types=1);

/*
 * Action rate limiting must hold across workers.
 *
 * `ActionHandler::$rateLimitBuckets` was a plain instance property. The handler is built in Via's
 * constructor — master process, before $server->start() forks — so every worker inherited its own
 * copy-on-write bucket and enforced the configured limit independently. The effective limit was
 * `limit x worker_num`, silently, with nothing in the configuration to suggest it.
 *
 * Measured against the pre-fix code with withActionRateLimit(5, 60) and one client IP:
 *
 *   workers=1  allowed=5
 *   workers=2  allowed=10
 *   workers=4  allowed=20
 *   workers=8  allowed=40
 *
 * Security-relevant and invisible: the limit an operator configures is not the limit they get, and
 * OpenSwoole's default fd-based dispatch spreads a client's connections across workers on its own.
 */

/** Run the forked hammer and return how many requests were allowed in total. */
function rateLimitForkAllowed(int $workers, int $limit = 5, int $attempts = 20): int {
    $fixture = dirname(__DIR__) . '/Fixtures/rate_limit_fork.php';
    $out = (string) shell_exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
        . ' ' . $workers . ' ' . $limit . ' ' . $attempts . ' 2>&1'
    );

    expect($out)->toMatch('/allowed=\d+/', 'fixture output: ' . var_export($out, true));
    preg_match('/allowed=(\d+)/', $out, $m);

    return (int) $m[1];
}

beforeEach(function (): void {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl required to reproduce the worker fork');
    }
});

test('the configured limit is enforced across all workers, not per worker', function (): void {
    expect(rateLimitForkAllowed(4, limit: 5))->toBe(5, '4 workers must share one budget of 5');
});

test('the shared limit does not scale with worker count', function (): void {
    $four = rateLimitForkAllowed(4, limit: 5);
    $eight = rateLimitForkAllowed(8, limit: 5);

    expect($eight)->toBe($four, 'adding workers must not raise the effective limit');
});

test('a single worker still enforces exactly the configured limit', function (): void {
    expect(rateLimitForkAllowed(1, limit: 5))->toBe(5);
});
