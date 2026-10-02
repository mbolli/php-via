<?php

declare(strict_types=1);

/*
 * Under the file hooks, stat() results stay cached across writes, so a dev-mode cache keyed by
 * mtime and size missed edits unless it cleared the stat cache first. Coroutine::run turns the
 * hooks on, so each case runs in Fixtures/hooked_stat_cache_cases.php in a process of its own.
 */

/** @return array<string, string> */
function hookedStatCase(string $case): array {
    $fixture = dirname(__DIR__) . '/Fixtures/hooked_stat_cache_cases.php';
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($case) . ' 2>&1');
    expect($out)->toContain('hook_flags=');
    preg_match_all('/^(\w+)=(.*)$/m', $out, $m);

    return array_combine($m[1], $m[2]);
}

describe('file caches under the file hooks', function (): void {
    test('a dev-mode shell edit is read on the next render, a production shell stays cached', function (): void {
        $result = hookedStatCase('shell');

        expect((int) $result['hook_flags'] & SWOOLE_HOOK_FILE)->toBe(SWOOLE_HOOK_FILE)
            ->and($result['dev'])->toBe('edited')
            ->and($result['prod'])->toBe('old')
        ;
    });

    test('a dev-mode static file edit yields a new ETag and body, with and without Brotli', function (): void {
        $result = hookedStatCase('static');

        expect($result['br_etag_changed'])->toBe('yes')
            ->and($result['br_body'])->toBe('body { color: rebeccapurple; }')
            ->and($result['identity_etag_changed'])->toBe('yes')
            ->and($result['identity_body'])->toBe('body { color: rebeccapurple; }')
        ;
    });
});
