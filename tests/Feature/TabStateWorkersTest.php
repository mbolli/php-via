<?php

declare(strict_types=1);

use OpenSwoole\Coroutine\Http\Client;

/*
 * Tab state and the page query on a real server with four workers: actions that reach every worker
 * run on the one that holds the tab, which reads the tab state each wrote, keeps every concurrent
 * write, and ran the handler with the page's query. The other workers rebuild nothing.
 */

beforeEach(function (): void {
    if (!class_exists(Client::class)) {
        $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
    }
});

test('actions that reach four workers keep one tab state on the worker that holds the tab', function (): void {
    $fixture = dirname(__DIR__) . '/Fixtures/tab_state_workers.php';
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 4 24 40 2>&1');

    $field = static function (string $name) use ($out): string {
        expect($out)->toMatch("/^{$name}=/m", 'fixture output: ' . $out);
        preg_match("/^{$name}=(.*)$/m", $out, $m);

        return trim($m[1]);
    };

    // The bumps take turns over one connection per worker the fixture reached, normally all four.
    expect($field('failed'))->toBe('0', 'fixture output: ' . $out)
        ->and((int) $field('workers'))->toBeGreaterThanOrEqual(2)
        ->and($field('n'))->toBe('24', 'each bump read the count the bump before wrote')
        ->and($field('marks'))->toBe('40', 'no concurrent write of the tab was lost')
        ->and($field('handlers'))->toBe('1', 'only the worker that holds the tab ran its handler')
        ->and($field('queries'))->toBe('hello')
    ;
});
