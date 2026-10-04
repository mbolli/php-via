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
use Mbolli\PhpVia\Twig\TwigEngine;
use Mbolli\PhpVia\Via;
use Twig\Loader\ArrayLoader;

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

// Set environment variable to indicate we're in test mode (no OpenSwoole)
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
 * A TwigEngine that loads its templates from $templates, name => source, instead of a directory.
 *
 * @param array<string, string> $templates
 */
function arrayTwig(array $templates): TwigEngine {
    $engine = new TwigEngine(__DIR__);
    $engine->environment()->setLoader(new ArrayLoader($templates));

    return $engine;
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
