<?php

declare(strict_types=1);

test('a context finds the request bound to its coroutine, to the coroutine that started it while that runs, or to the spawn() task', function (): void {
    $out = (string) shell_exec(
        'VIA_TEST_MODE=1 timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/request_scope_coroutines.php') . ' 2>&1'
    );
    preg_match_all('/^([a-z_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);
    $r = [];
    foreach ($m as [, $key, $value]) {
        $r[$key] = $value;
    }

    // before the other coroutine ran > after it > what the page's component reads
    expect($r['interleaved'] ?? null)->toBe('1>1>1,2>2>2', $out)
        ->and($r['other_page'] ?? null)->toBe('NULL')
        ->and($r['after_unbind'] ?? null)->toBe('page')
        // a coroutine the request started reads it while the request runs, the page's query afterwards
        ->and($r['child'] ?? null)->toBe('3>page')
        // a spawn() task keeps the request, without the upload, once it was answered
        ->and($r['task'] ?? null)->toBe('3>NULL')
        ->and($r['queue_after_answer'] ?? null)->toBe('false')
        // outside a coroutine, as TestApp runs requests, a Fiber has a binding of its own
        ->and($r['outside'] ?? null)->toBe('outside>page')
        ->and($r['outside_after'] ?? null)->toBe('page')
    ;
});
