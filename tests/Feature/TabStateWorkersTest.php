<?php

declare(strict_types=1);

use OpenSwoole\Coroutine\Http\Client;

/*
 * Tab state and the page query on a real server with four workers: every worker's copy of a
 * context reads the tab state the others wrote, concurrent writes of one tab all land, and the
 * handler that rebuilds the context on each worker reads the page's query from the record.
 */

beforeEach(function (): void {
    if (!class_exists(Client::class)) {
        $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
    }
});

test('four workers share a tab\'s state and rebuild its context with the page query', function (): void {
    $fixture = dirname(__DIR__) . '/Fixtures/tab_state_workers.php';
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 4 24 40 2>&1');

    $field = static function (string $name) use ($out): string {
        expect($out)->toMatch("/^{$name}=/m", 'fixture output: ' . $out);
        preg_match("/^{$name}=(.*)$/m", $out, $m);

        return trim($m[1]);
    };

    // The bumps take turns over one connection per worker the fixture reached, normally all four.
    $workers = (int) $field('workers');
    expect($field('failed'))->toBe('0', 'fixture output: ' . $out)
        ->and($workers)->toBeGreaterThanOrEqual(2)
        ->and($field('n'))->toBe('24', 'each bump read the count the bump before wrote, on another worker')
        ->and($field('marks'))->toBe('40', 'no concurrent write of the tab was lost')
        ->and((int) $field('handlers'))->toBe($workers, 'every worker reached ran the handler for the tab')
        ->and($field('queries'))->toBe(implode(',', array_fill(0, $workers, 'hello')))
    ;
});
