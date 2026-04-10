<?php

declare(strict_types=1);

use Mbolli\PhpVia\Via;

/*
 * Via::parseSignals() tests.
 *
 * Covers all three signal source paths without requiring an OpenSwoole Request
 * instance (which is a final extension class and cannot be mocked).
 */
describe('Via::parseSignals()', function (): void {
    // ── 1. GET ?datastar=<json> ──────────────────────────────────────────────

    it('reads signals from GET datastar param', function (): void {
        $signals = Via::parseSignals(
            get: ['datastar' => json_encode(['count' => 5, 'via_ctx' => 'abc123'])],
            post: [],
            body: false,
        );

        expect($signals)->toBe(['count' => 5, 'via_ctx' => 'abc123']);
    });

    it('returns empty array for malformed GET datastar param', function (): void {
        $signals = Via::parseSignals(
            get: ['datastar' => 'not-json'],
            post: [],
            body: false,
        );

        expect($signals)->toBe([]);
    });

    it('GET datastar takes priority over JSON body', function (): void {
        $signals = Via::parseSignals(
            get: ['datastar' => json_encode(['source' => 'get'])],
            post: [],
            body: json_encode(['source' => 'body']),
        );

        expect($signals['source'])->toBe('get');
    });

    // ── 2. Raw JSON body (standard Datastar POST/PATCH) ──────────────────────

    it('reads signals from JSON body', function (): void {
        $signals = Via::parseSignals(
            get: [],
            post: [],
            body: json_encode(['name' => 'Ada', 'via_ctx' => 'ctx-1']),
        );

        expect($signals)->toBe(['name' => 'Ada', 'via_ctx' => 'ctx-1']);
    });

    it('falls through to POST field when body is not valid JSON', function (): void {
        $signals = Via::parseSignals(
            get: [],
            post: ['datastar' => json_encode(['via_ctx' => 'ctx-multipart'])],
            body: '--boundary\r\nContent-Disposition: form-data; name="file"\r\n\r\nbinary',
        );

        expect($signals['via_ctx'])->toBe('ctx-multipart');
    });

    // ── 3. POST datastar=<json> field (multipart / urlencoded form) ──────────

    it('reads signals from POST datastar field for multipart submissions', function (): void {
        $signals = Via::parseSignals(
            get: [],
            post: ['datastar' => json_encode(['via_ctx' => 'ctx-xyz', 'step' => 2])],
            body: false,
        );

        expect($signals)->toBe(['via_ctx' => 'ctx-xyz', 'step' => 2]);
    });

    it('reads via_ctx from POST datastar field', function (): void {
        $signals = Via::parseSignals(
            get: [],
            post: ['datastar' => json_encode(['via_ctx' => 'my-context-id'])],
            body: false,
        );

        expect($signals['via_ctx'])->toBe('my-context-id');
    });

    it('returns empty array when POST datastar field is malformed JSON', function (): void {
        $signals = Via::parseSignals(
            get: [],
            post: ['datastar' => 'not-json-either'],
            body: false,
        );

        expect($signals)->toBe([]);
    });

    // ── 4. Nothing present ───────────────────────────────────────────────────

    it('returns empty array when no signal source is present', function (): void {
        $signals = Via::parseSignals(get: [], post: [], body: false);

        expect($signals)->toBe([]);
    });

    it('returns empty array for empty body string', function (): void {
        $signals = Via::parseSignals(get: [], post: [], body: '');

        expect($signals)->toBe([]);
    });
});
