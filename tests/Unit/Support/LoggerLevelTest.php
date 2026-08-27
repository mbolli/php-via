<?php

declare(strict_types=1);

use Mbolli\PhpVia\Support\LogBuffer;
use Mbolli\PhpVia\Support\Logger;

/*
 * Regression: log('warning', ...) was silently demoted to info severity.
 *
 * Logger::LEVELS only defined 'warn'. Any other string fell through
 * `self::LEVELS[$level] ?? self::LEVELS['info']`, so 'warning' was compared against the
 * threshold as INFO while still printing the "[WARNING]" label — the message looked like a
 * warning and was filtered like an info line.
 *
 * The consequence is inverted from what an operator expects: setting withLogLevel('warn') to
 * see warnings and nothing else SUPPRESSED every one of them. Eight call sites used the
 * 'warning' spelling, including the invalid-wire-scope rejection, context authorisation
 * failures and rate-limit store exhaustion.
 */

/** Capture what the logger emits for one call. */
function logAt(string $minLevel, string $level, string $message = 'MSG'): string {
    $logger = new Logger($minLevel);
    ob_start();

    try {
        $logger->log($level, $message);

        return trim((string) ob_get_contents());
    } finally {
        ob_end_clean();
    }
}

test('a warning is emitted at the warn threshold whichever spelling is used', function (): void {
    expect(logAt('warn', 'warn'))->toContain('MSG');
    expect(logAt('warn', 'warning'))->toContain('MSG');
});

test('both spellings rank above info and below error', function (): void {
    foreach (['warn', 'warning'] as $level) {
        expect(logAt('info', $level))->toContain('MSG');
        expect(logAt('warn', $level))->toContain('MSG');
        expect(logAt('error', $level))->toBe('', "'{$level}' must not survive an error-only threshold");
    }
});

test('warning is normalised to the canonical level name', function (): void {
    // The label and the buffered level must agree with Logger::warn(), or the Dev Bar's log
    // panel shows two different severities for the same thing.
    expect(logAt('debug', 'warning'))->toContain('[WARN]');

    $buffer = new LogBuffer();
    $logger = new Logger('debug');
    $logger->setBuffer($buffer);

    ob_start();

    try {
        $logger->log('warning', 'buffered');
        $logger->warn('direct');
    } finally {
        ob_end_clean();
    }

    $levels = array_column($buffer->recent(10), 'level');
    expect($levels)->toBe(['warn', 'warn']);
});

test('withLogLevel accepts the warning spelling as a threshold', function (): void {
    // 'warning' as the MINIMUM level previously fell back to info, so an operator asking for
    // warnings-and-above quietly got info-and-above.
    expect(logAt('warning', 'info'))->toBe('', 'info must be below a warning threshold');
    expect(logAt('warning', 'warn'))->toContain('MSG');
});
