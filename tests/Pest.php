<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| This file is used to define the global test case and custom expectations
| for Pest PHP. It's the configuration file for all tests.
|
*/

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOneOf', fn (array $values) => $this->toBeIn($values));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

// Set environment variable to indicate we're in test mode (no Swoole)
putenv('VIA_TEST_MODE=1');

/**
 * Create a Via instance for testing (doesn't start HTTP server).
 */
function createVia(?Config $config = null): Via {
    $config ??= new Config();
    $config = $config->withLogLevel('error');

    return new Via($config);
}

/**
 * Generate a unique test context ID.
 */
function testContextId(): string {
    return 'test_' . bin2hex(random_bytes(8));
}

/**
 * Create a counter function for tracking render calls.
 */
function renderCounter(): Closure {
    $count = 0;

    return function () use (&$count): string {
        ++$count;

        return '<div>Render ' . $count . '</div>';
    };
}
