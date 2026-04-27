<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Via;

/*
 * Action & SSE Authorization Tests
 *
 * Verifies that the session-ownership check correctly allows or denies
 * access to context-bound endpoints.
 *
 * The isSessionAuthorized() method is private in both ActionHandler and
 * SseHandler; we reach it via reflection to avoid requiring OpenSwoole
 * Request/Response objects (final C-extension classes, not mockable).
 */

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Call ActionHandler::isSessionAuthorized() via reflection.
 */
function actionIsSessionAuthorized(Via $via, string $contextId, ?string $callerSessionId): bool {
    $handler = new ActionHandler($via);
    $method = new ReflectionMethod(ActionHandler::class, 'isSessionAuthorized');

    return $method->invoke($handler, $contextId, $callerSessionId);
}

/**
 * Call SseHandler::isSessionAuthorized() via reflection.
 */
function sseIsSessionAuthorized(Via $via, string $contextId, ?string $callerSessionId): bool {
    $handler = new SseHandler($via);
    $method = new ReflectionMethod(SseHandler::class, 'isSessionAuthorized');

    return $method->invoke($handler, $contextId, $callerSessionId);
}

/**
 * Register a context in Via with the given session binding.
 */
function registerContextWithSession(Via $via, string $contextId, string $sessionId): Context {
    $ctx = new Context($contextId, '/test', $via, null, $sessionId);
    $via->contexts[$contextId] = $ctx;
    $via->contextSessions[$contextId] = $sessionId;

    return $ctx;
}

// ── Via: context-session mapping infrastructure ───────────────────────────────

describe('Via: context-session mapping', function (): void {
    test('getContextSessionId returns stored session', function (): void {
        $via = createVia();
        $via->contextSessions['ctx1'] = 'sess_abc';

        expect($via->getContextSessionId('ctx1'))->toBe('sess_abc');
    });

    test('getContextSessionId returns null for unknown context', function (): void {
        $via = createVia();

        expect($via->getContextSessionId('nonexistent'))->toBeNull();
    });

    test('Context constructor stores session on Via', function (): void {
        $via = createVia();
        $via->contextSessions['ctx/abc'] = 'sess_xyz';

        expect($via->getContextSessionId('ctx/abc'))->toBe('sess_xyz');
    });
});

// ── ActionHandler: session ownership ─────────────────────────────────────────

describe('ActionHandler: session ownership', function (): void {
    test('correct session is authorized', function (): void {
        $via = createVia();
        registerContextWithSession($via, 'ctx1', 'sess_owner');

        expect(actionIsSessionAuthorized($via, 'ctx1', 'sess_owner'))->toBeTrue();
    });

    test('wrong session is denied', function (): void {
        $via = createVia();
        registerContextWithSession($via, 'ctx1', 'sess_owner');

        expect(actionIsSessionAuthorized($via, 'ctx1', 'sess_attacker'))->toBeFalse();
    });

    test('null caller session (no cookie) is denied when context has a session binding', function (): void {
        $via = createVia();
        registerContextWithSession($via, 'ctx1', 'sess_owner');

        expect(actionIsSessionAuthorized($via, 'ctx1', null))->toBeFalse();
    });

    test('context with no session binding is accessible by any caller', function (): void {
        $via = createVia();
        // Context registered in Via but no session entry → no binding
        $via->contexts['ctx_unbound'] = new Context('ctx_unbound', '/test', $via);

        expect(actionIsSessionAuthorized($via, 'ctx_unbound', 'any_session'))->toBeTrue();
        expect(actionIsSessionAuthorized($via, 'ctx_unbound', null))->toBeTrue();
    });

    test('session IDs are compared exactly (no prefix match)', function (): void {
        $via = createVia();
        registerContextWithSession($via, 'ctx1', 'sess_abc');

        expect(actionIsSessionAuthorized($via, 'ctx1', 'sess_abc_extra'))->toBeFalse();
        expect(actionIsSessionAuthorized($via, 'ctx1', 'sess_ab'))->toBeFalse();
    });
});

// ── SseHandler: session ownership ────────────────────────────────────────────

describe('SseHandler: session ownership', function (): void {
    test('correct session is authorized', function (): void {
        $via = createVia();
        registerContextWithSession($via, 'ctx1', 'sess_owner');

        expect(sseIsSessionAuthorized($via, 'ctx1', 'sess_owner'))->toBeTrue();
    });

    test('wrong session is denied', function (): void {
        $via = createVia();
        registerContextWithSession($via, 'ctx1', 'sess_owner');

        expect(sseIsSessionAuthorized($via, 'ctx1', 'sess_attacker'))->toBeFalse();
    });

    test('null caller session (no cookie) is denied when context has a session binding', function (): void {
        $via = createVia();
        registerContextWithSession($via, 'ctx1', 'sess_owner');

        expect(sseIsSessionAuthorized($via, 'ctx1', null))->toBeFalse();
    });

    test('context with no session binding is accessible by any caller', function (): void {
        $via = createVia();
        $via->contexts['ctx_unbound'] = new Context('ctx_unbound', '/test', $via);

        expect(sseIsSessionAuthorized($via, 'ctx_unbound', 'any_session'))->toBeTrue();
        expect(sseIsSessionAuthorized($via, 'ctx_unbound', null))->toBeTrue();
    });
});
