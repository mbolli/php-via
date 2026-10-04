<?php

declare(strict_types=1);

use OpenSwoole\Coroutine\Http\Client;

describe('per-entity scopes with two workers', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
        }
    });

    test('abandoned scopes leave no rows in the shared table, and values still cross workers', function (): void {
        $out = (string) shell_exec(
            'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/scoped_rows_workers.php') . ' 2>&1'
        );
        preg_match_all('/^rows=\d+:(\d+):(\d+)$/m', $out, $rows);
        preg_match_all('/^final=\d+:(-?\d+)$/m', $out, $final);
        preg_match('/^hits=(\d+)$/m', $out, $hits);

        expect($out)->toContain("failed=0\n")
            ->and($out)->toContain("page_workers=2\n")
            ->and($out)->not->toContain('Scoped signal table is full')
            ->and($rows[1])->toBe(['0', '0'], $out)
            ->and($rows[2])->toBe(['0', '0'], $out)
            ->and($out)->toContain("hit_workers=2\n")
            ->and($final[1])->toHaveCount(2, $out)
            ->and(array_unique($final[1]))->toBe([$hits[1] ?? 'none'], $out)
        ;
    });
});
