<?php

declare(strict_types=1);

use OpenSwoole\Coroutine\Http\Client;

/**
 * The fixture's key=value lines and its whole output, from one run for both tests.
 *
 * @return array{array<string, string>, string}
 */
function requestScopeFixture(): array {
    static $result = null;
    if ($result === null) {
        $out = (string) shell_exec(
            'timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/action_request_scope_server.php') . ' 2>&1'
        );
        preg_match_all('/^([a-z_]+)=(.*)$/m', $out, $m, PREG_SET_ORDER);
        $r = [];
        foreach ($m as [, $key, $value]) {
            $r[$key] = $value;
        }
        $result = [$r, $out];
    }

    return $result;
}

describe('the request of an action on a real server', function (): void {
    beforeEach(function (): void {
        if (!class_exists(Client::class)) {
            $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
        }
    });

    test('each action of a tab reads its own request and answers with its own cookies, also while another runs', function (): void {
        [$r, $out] = requestScopeFixture();

        // input v / cookie who / attribute tag / file f, before and after the first action's sleep
        expect($r['first'] ?? null)->toBe('1/alice/tag1/nofile 1/alice/tag1/nofile', $out)
            ->and($r['second'] ?? null)->toBe('2/bob/tag2/file 2/bob/tag2/file')
            ->and($r['first_cookies'] ?? null)->toBe('probe1')
            ->and($r['second_cookies'] ?? null)->toBe('probe2')
        ;
    });

    test('a spawn() task keeps the request of its action after it answered, but not the upload', function (): void {
        [$r, $out] = requestScopeFixture();

        expect($r['task'] ?? null)->toBe('7/carol/tag7/nofile', $out)
            // the cookie the task set after its action answered, then the next action's own
            ->and($r['next_cookies'] ?? null)->toBe('late,probe3')
            // a timer reads the page request, never the last action's
            ->and($r['timer'] ?? null)->toBe('page/')
        ;
    });

    test('a session rotation goes out with the response of the action that asked for it, also while another runs', function (): void {
        [$r, $out] = requestScopeFixture();

        expect($r['login_cookies'] ?? null)->toBe('via_session_id', $out)
            ->and($r['during_login_cookies'] ?? null)->toBe('probe4')
            // a task that rotates after its action answered, with the tab's next action response
            ->and($r['task_login_cookies'] ?? null)->toBe('')
            ->and($r['after_task_login_cookies'] ?? null)->toBe('probe5,via_session_id')
        ;
    });
});
