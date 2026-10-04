<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;

/*
 * Regression: multi-worker set dispatch_mode = 7 with a dispatch_func.
 *
 * SW_DISPATCH_USERFUNC is 6; 7 is stream mode, which ignores dispatch_func
 * entirely. Session affinity therefore never executed, and mode 7 scatters
 * per REQUEST where OpenSwoole's default is sticky per CONNECTION — so the
 * feature performed measurably WORSE than not having it (56.5% vs 100% OK for
 * a keep-alive client at 16 workers).
 *
 * Correcting 7 -> 6 is not shippable either: on PHP 8.3+ a dispatch_func runs on
 * the master reactor thread, where the stack-limit check mis-detects the stack
 * base and fatals on EVERY dispatch:
 *
 *   Uncaught Error: Maximum call stack size of 8306688 bytes
 *   (zend.max_allowed_stack_size - zend.reserved_stack_size) reached.
 *
 * Only `zend.max_allowed_stack_size=-1` clears it, and that ini is not settable
 * at runtime (ini_set() returns false) — so the library cannot fix it from PHP.
 * PHP 8.4 is this project's minimum, so a dispatch_func is broken for every
 * supported version.
 *
 * The wiring is therefore removed: OpenSwoole's default dispatch is sticky per
 * connection, which is what browser clients actually need, and cross-connection
 * session affinity belongs at the L7 proxy (as the deployment docs already say).
 *
 * SessionManager::workerForRequest() itself was always correct and stays covered
 * by SessionDispatchTest — the function was tested; its wiring never was, which
 * is exactly how this survived.
 */

test('multi-worker settings do not install a PHP dispatch callback', function (): void {
    $settings = Via::serverSettings((new Config())->withWorkerNum(8)->freeze());

    expect($settings)->not->toHaveKey('dispatch_func');
});

test('multi-worker settings do not pin a dispatch_mode', function (): void {
    // Leaving it unset keeps OpenSwoole's connection-sticky default. Pinning any
    // mode here would need the same empirical justification 7 never had.
    $settings = Via::serverSettings((new Config())->withWorkerNum(8)->freeze());

    expect($settings)->not->toHaveKey('dispatch_mode');
});

test('single-worker settings are unaffected', function (): void {
    $settings = Via::serverSettings((new Config())->freeze());

    expect($settings)->not->toHaveKey('dispatch_func');
    expect($settings['worker_num'])->toBe(1);
});

test('worker_num reaches the server settings', function (): void {
    expect(Via::serverSettings((new Config())->withWorkerNum(6)->freeze())['worker_num'])->toBe(6);
});

test('caller overrides win over defaults', function (): void {
    $config = (new Config())
        ->withWorkerNum(4)
        ->withSwooleSettings(['backlog' => 128, 'dispatch_mode' => 2])
    ;

    $settings = Via::serverSettings($config->freeze());

    // An operator who has set zend.max_allowed_stack_size can still opt in to a
    // custom dispatch via withSwooleSettings(); nothing here blocks that.
    expect($settings['backlog'])->toBe(128);
    expect($settings['dispatch_mode'])->toBe(2);
});
