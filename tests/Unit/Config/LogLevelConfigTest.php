<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;

test('withLogLevel() takes the canonical, PSR-3 and syslog names in any case', function (string $level, string $canonical): void {
    expect((new Config())->withLogLevel($level)->freeze()->logLevel)->toBe($canonical);
})->with([
    ['debug', 'debug'],
    ['INFO', 'info'],
    ['Warn', 'warn'],
    ['error', 'error'],
    ['warning', 'warn'],
    ['notice', 'info'],
    ['critical', 'error'],
    ['alert', 'error'],
    ['emergency', 'error'],
    ['err', 'error'],
    ['crit', 'error'],
    ['EMERG', 'error'],
]);

test('withLogLevel() rejects a level it does not know instead of logging at info', function (string $level): void {
    expect(fn () => (new Config())->withLogLevel($level))
        ->toThrow(InvalidArgumentException::class, "Unknown log level '{$level}': use debug, info, warn or error")
    ;
})->with(['verbose', 'trace', 'warnings', '', ' info']);
