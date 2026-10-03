<?php

declare(strict_types=1);

/*
 * Context::patchElements() through the real SSE loop: the frames it writes, and which element patches
 * survive a client that has fallen behind (Fixtures/patch_elements_stream.php).
 */

/** @return array<string, string> key => value from the fixture's key=value lines, plus 'out' */
function patchElementsStream(string $case): array {
    $out = (string) shell_exec(
        'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/patch_elements_stream.php')
        . ' ' . escapeshellarg($case) . ' 2>&1'
    );
    preg_match_all('/^([a-z0-9_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);

    $values = ['out' => $out];
    foreach ($m as [, $key, $value]) {
        $values[$key] = $value;
    }

    return $values;
}

test('each patch goes out as a Datastar frame with its selector and mode', function (): void {
    $r = patchElementsStream('wire');

    expect($r['frames'] ?? null)->toBe('5', $r['out'])
        ->and($r['frame0'])->toBe('data: selector #log|data: mode append|data: elements <li>chunk 1</li>')
        ->and($r['frame1'])->toBe('data: selector #modal|data: mode inner|data: elements <p>modal</p>')
        ->and($r['frame2'])->toBe('data: selector #toast|data: mode remove|data: elements ')
        ->and($r['frame3'])->toBe('data: selector #log|data: mode prepend|data: elements <li>chunk 2</li>')
        ->and($r['frame4'])->toBe('data: selector #log|data: mode after|data: elements <li>chunk 3</li>', 'a datastar-php mode queued by hand still works')
    ;
});

test('a client that has fallen behind loses morphs and removals, never an inserted chunk', function (): void {
    $r = patchElementsStream('backlog');

    expect($r['frames'] ?? null)->toBe('3', $r['out'])
        ->and($r['frame0'])->toContain('<li>chunk 1</li>')
        ->and($r['frame1'])->toContain('<li>chunk 2</li>')
        ->and($r['frame2'])->toContain('<li>chunk 3</li>')
        ->and($r['page_sent'])->toBe('0', 'the page frame is dropped as before')
    ;
});
