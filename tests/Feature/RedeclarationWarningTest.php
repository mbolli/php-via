<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Via;

// Re-declaring a TAB signal keeps last-wins behaviour but must be visible at warning level.

function redeclarationOutput(Context $ctx, callable $fn): string {
    ob_start();

    try {
        $fn($ctx);
    } finally {
        $out = (string) ob_get_clean();
    }

    return $out;
}

function warnContext(): Context {
    return new Context('ctx1', '/t', new Via((new Config())->withLogLevel('warn')));
}

test('re-declaring a TAB signal with a different initial value logs a warning', function (): void {
    $ctx = warnContext();

    $out = redeclarationOutput($ctx, function (Context $ctx): void {
        $ctx->signal(1, 'shared');
        $ctx->signal(2, 'shared');
    });

    expect($out)->toContain('[WARN]')->toContain("'shared'")->toContain('initial value (int)')->toContain('live value (int)')
        ->and($ctx->getSignal('shared')->getValue())->toBe(2)
    ;
});

test('the warning names the value type and size but never the content', function (): void {
    $ctx = warnContext();

    $out = redeclarationOutput($ctx, function (Context $ctx): void {
        $ctx->signal('', 'search')->setValue('hunter2-secret');
        $ctx->signal('', 'search');
    });

    expect($out)->toContain('[WARN]')->toContain('string of 14 chars')->not->toContain('hunter2');
});

test('the warning compares against the live value, not the first initial value', function (): void {
    $ctx = warnContext();

    $out = redeclarationOutput($ctx, function (Context $ctx): void {
        $ctx->signal(1, 'live')->setValue(5);
        $ctx->signal(1, 'live');
    });

    expect($out)->toContain('[WARN]')->toContain("'live'")->toContain('live value (int)')
        ->and($ctx->getSignal('live')->getValue())->toBe(1)
    ;
});

test('re-declaring a TAB signal with the same initial value stays quiet', function (): void {
    $ctx = warnContext();

    $out = redeclarationOutput($ctx, function (Context $ctx): void {
        $ctx->signal(1, 'same');
        $ctx->signal(1, 'same');
    });

    expect($out)->toBe('');
});

test('the value warning is logged once per signal name', function (): void {
    $ctx = warnContext();

    $out = redeclarationOutput($ctx, function (Context $ctx): void {
        $ctx->signal(1, 'noisy');
        $ctx->signal(2, 'noisy');
        $ctx->signal(3, 'noisy');
    });

    expect(substr_count($out, '[WARN]'))->toBe(1);
});

test('a re-declaration with a different clientWritable warns and keeps the first', function (): void {
    $ctx = warnContext();

    $out = redeclarationOutput($ctx, function (Context $ctx): void {
        $ctx->signal('v', 'owned', clientWritable: false);
        $ctx->signal('v', 'owned', clientWritable: true);
    });

    expect($out)->toContain('[WARN]')->toContain('clientWritable')
        ->and($ctx->getSignal('owned')->isClientWritable())->toBeFalse()
    ;
});

test('a re-declaration without clientWritable does not warn about writability', function (): void {
    $ctx = warnContext();

    $out = redeclarationOutput($ctx, function (Context $ctx): void {
        $ctx->signal('v', 'owned', clientWritable: false);
        $ctx->signal('v', 'owned');
    });

    expect($out)->toBe('')
        ->and($ctx->getSignal('owned')->isClientWritable())->toBeFalse()
    ;
});
