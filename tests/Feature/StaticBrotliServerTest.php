<?php

declare(strict_types=1);

/*
 * Static files get Brotli at level 11 without withBrotli(), and a worker never compresses at that level itself:
 * files present at start are compressed before the server listens, later ones by a helper process while the
 * worker answers at once. Before, a 700 KB bundle written after start held its worker for over a second on its
 * first Brotli request, and nothing was compressed without withBrotli().
 */

/** @return array<string, string> key => value from the fixture's key=value lines */
function staticBrotliServer(string $mode): array {
    $out = (string) shell_exec(
        'timeout 90 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/static_brotli_server.php')
        . ' ' . escapeshellarg($mode) . ' 2>&1'
    );
    preg_match_all('/^([a-z0-9_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);

    $values = [];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }

    expect($values)->toHaveKey('client_done', '1', 'fixture output: ' . var_export($out, true));

    return $values + ['out' => $out];
}

beforeEach(function (): void {
    if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('brotli_compress')) {
        $this->markTestSkipped('ext-pcntl, ext-posix and ext-brotli required');
    }
});

test('files present at start go out at level 11 from the first request, without withBrotli(), big ones once the helper compressed them after start', function (): void {
    $r = staticBrotliServer('boot');

    expect($r['boot_form'])->toBe('l11', $r['out'])
        ->and((float) $r['boot_ms'])->toBeLessThan(50.0, $r['out'])
        ->and($r['boot_vary'])->toBe('Accept-Encoding')
        ->and($r['datastar_form'])->toBe('l11', $r['out'])
        ->and($r['plain_form'])->toBe('identity')
        ->and($r['out'])->toContain('Brotli level 11: compressed 3 static files')
        ->and($r['out'])->toContain('1 more go to the helper process after start')
        ->and([$r['large_form'], $r['large_cc']])->toBe(['l11', 'public, max-age=3600, must-revalidate'], $r['out'])
    ;
});

test('a file written after start is answered at once, not to be cached, and gets level 11 from the helper', function (string $mode): void {
    $r = staticBrotliServer($mode);

    expect($r['big_first'])->toBe('identity', $r['out'])
        ->and((float) $r['big_first_ms'])->toBeLessThan(500.0, $r['out'])
        ->and($r['small_first'])->toBe('l4', $r['out'])
        ->and((float) $r['small_first_ms'])->toBeLessThan(500.0, $r['out'])
        ->and([$r['big_first_cc'], $r['small_first_cc']])->toBe(['no-store', 'no-store'], $r['out'])
        ->and($r['final_cc'])->toBe('public, max-age=3600, must-revalidate | public, max-age=3600, must-revalidate', $r['out'])
        ->and($r['level11_everywhere'])->toBe('1', $r['out'])
        ->and((float) $r['health_max_ms'])->toBeLessThan(100.0, $r['out'])
    ;
})->with(['one worker' => 'later', 'two workers' => 'workers']);

test('a fresh .br sidecar is sent as it is, and a stale one is ignored', function (): void {
    $r = staticBrotliServer('sidecar');

    expect($r['fresh_sidecar'])->toBe('1', $r['out'])
        ->and($r['stale_form'])->toBe('l11', $r['out'])
    ;
});

test('HEAD answers like GET with no body: same headers, and the length of the body GET sends', function (): void {
    // OpenSwoole 26.2 sends end()'s body and sendfile()'s file on HEAD as well, and HEAD on a static file answered 404.
    $r = staticBrotliServer('head');

    expect($r['head_cases'])->toBe('16', $r['out'])
        ->and($r['head_mismatches'])->toBe('none')
        ->and($r['head_then_get'])->toBe('ok')
        ->and($r['head_304'])->toBe('304 0')
        ->and($r['head_route'])->toBe('200')
        ->and($r['head_missing'])->toBe('404')
    ;
});
