<?php

declare(strict_types=1);

use Mbolli\PhpVia\Core\SessionManager;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Tracing\Sanitizer;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

/*
 * The session id is the HttpOnly cookie that ActionHandler and SseHandler check ownership against,
 * so only ids php-via issued are taken, and the id never reaches a log or a trace attribute.
 */

const SESSION_ID_VALID = 'c0ffee00c0ffee00c0ffee00c0ffee00';

/**
 * @param array<string, string> $cookies
 */
function sessionIdFromCookies(array $cookies, bool $secure = false): string {
    $request = new Request();
    $request->cookie = $cookies;

    return (new SessionManager(new Logger('error')))->getOrCreateSessionId($request, $secure);
}

describe('SessionManager::getOrCreateSessionId()', function (): void {
    test('keeps an id in the form php-via issues', function (): void {
        expect(sessionIdFromCookies(['via_session_id' => SESSION_ID_VALID]))->toBe(SESSION_ID_VALID)
            ->and(sessionIdFromCookies(['__Host-via_session_id' => SESSION_ID_VALID], secure: true))->toBe(SESSION_ID_VALID)
        ;
    });

    test('starts a new session for any other cookie value', function (string $planted): void {
        $id = sessionIdFromCookies(['via_session_id' => $planted]);

        expect($id)->not->toBe($planted)
            ->and(SessionManager::isValidSessionId($id))->toBeTrue()
        ;
    })->with([
        'arbitrary' => 'sess_owner',
        'uppercase hex' => strtoupper(SESSION_ID_VALID),
        'too long' => SESSION_ID_VALID . 'a',
        'too short' => substr(SESSION_ID_VALID, 1),
        'trailing newline' => substr(SESSION_ID_VALID, 1) . "\n",
        'empty' => '',
    ]);

    test('ignores the plain cookie under secure cookies', function (): void {
        $id = sessionIdFromCookies(['via_session_id' => SESSION_ID_VALID], secure: true);

        expect($id)->not->toBe(SESSION_ID_VALID)
            ->and(SessionManager::isValidSessionId($id))->toBeTrue()
        ;
    });
});

describe('Session id privacy', function (): void {
    test('setting the session cookie logs no id', function (): void {
        $manager = new SessionManager(new Logger('debug'));

        ob_start();
        // A detached Response warns that it cannot set the cookie; the log line follows regardless.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            $manager->setSessionCookie(new Response(), SESSION_ID_VALID);
        } finally {
            restore_error_handler();
            $out = (string) ob_get_clean();
        }

        expect($out)->toContain('Set session cookie')
            ->and($out)->not->toContain(SESSION_ID_VALID)
        ;
    });

    test('trace attributes named after a session are redacted', function (): void {
        $clean = Sanitizer::sanitizeAttributes(['sessionId' => SESSION_ID_VALID, 'via_session' => SESSION_ID_VALID, 'scope' => 'room:1']);

        expect($clean['sessionId'])->toBe('[redacted]')
            ->and($clean['via_session'])->toBe('[redacted]')
            ->and($clean['scope'])->toBe('room:1')
        ;
    });
});
