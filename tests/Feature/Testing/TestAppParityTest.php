<?php

declare(strict_types=1);

/*
 * Testing\TestApp sends what a real server sends: the same page, connect and actions through the
 * harness and over HTTP to a server (Fixtures/test_app_parity.php).
 */

test('the harness and a real server send the same patches for the connect and each action', function (): void {
    $out = (string) shell_exec(
        'timeout 90 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/test_app_parity.php') . ' 2>&1'
    );
    $harness = $server = [];
    foreach (explode("\n", trim($out)) as $line) {
        if (str_starts_with($line, 'harness ')) {
            $harness[] = substr($line, 8);
        } elseif (str_starts_with($line, 'server ')) {
            $server[] = substr($line, 7);
        }
    }

    expect($out)->not->toContain('fixture_error')
        ->and($harness)->toContain(
            'connect elements outer - <main id="parity"><p>Count: 0</p><ul id="log"></ul></main>',
            'add elements append #log <li>tea</li>',
            'add elements append body <script data-effect="el.remove()">console.log("added")</script>',
            'add signals {"title":""}',
            'increment2 signals {"count":2}',
        )
        ->and($server)->toBe($harness, $out)
    ;
});
