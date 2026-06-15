<?php

declare(strict_types=1);

use Mbolli\PhpVia\Tracing\Sanitizer;
use Mbolli\PhpVia\Tracing\Span;
use Mbolli\PhpVia\Tracing\Tracer;
use Mbolli\PhpVia\Tracing\TraceStore;

/*
 * Tests for the Dev Bar tracing substrate (VIA_TEST_MODE, no OpenSwoole).
 *
 * Covers: Span duration/offset math, Tracer nesting + ambient current(),
 * startTrace no-op when a trace is already active, TraceStore ring eviction
 * and since()/recent() cursors, and Sanitizer redaction/truncation.
 */

describe('Span', function (): void {
    test('duration and offset are computed from hrtime nanoseconds', function (): void {
        $traceStart = 1_000_000_000; // 1s in ns
        $span = new Span('s1', 't1', null, 'db.query', $traceStart + 5_000_000, 'db');
        $span->end($traceStart + 8_000_000);

        expect($span->durationMs())->toBe(3.0);
        expect($span->offsetMsFrom($traceStart))->toBe(5.0);

        $arr = $span->toArray($traceStart);
        expect($arr['offsetMs'])->toBe(5.0);
        expect($arr['durationMs'])->toBe(3.0);
        expect($arr['category'])->toBe('db');
        expect($arr['status'])->toBe('ok');
    });

    test('end() is idempotent', function (): void {
        $span = new Span('s1', 't1', null, 'x', 0, 'app');
        $span->end(1_000_000);
        $span->end(9_000_000);

        expect($span->durationMs())->toBe(1.0);
    });
});

describe('Tracer', function (): void {
    beforeEach(function (): void {
        $this->store = new TraceStore(50);
        $this->tracer = new Tracer($this->store, testMode: true);
    });

    test('nesting produces parent/child spans under one trace', function (): void {
        expect($this->tracer->startTrace('GET /', 'request'))->toBeTrue();

        $this->tracer->span('render.regions', function (): void {
            $this->tracer->span('db.list', fn () => null, ['rows' => 3]);
        });

        $this->tracer->endTrace();

        $recent = $this->store->recent();
        expect($recent)->toHaveCount(1);

        $trace = $recent[0];
        // root + render.regions + db.list
        expect($trace['spanCount'])->toBe(3);
        expect($trace['label'])->toBe('GET /');

        $names = array_column($trace['spans'], 'name');
        expect($names)->toBe(['GET /', 'render.regions', 'db.list']);

        // db.list's parent is render.regions, render.regions' parent is root
        [$root, $render, $db] = $trace['spans'];
        expect($root['parentId'])->toBeNull();
        expect($render['parentId'])->toBe($root['id']);
        expect($db['parentId'])->toBe($render['id']);
        expect($db['attributes']['rows'])->toBe(3);
    });

    test('startTrace is a no-op while a trace is already active', function (): void {
        expect($this->tracer->startTrace('POST x'))->toBeTrue();
        // Simulates broadcast() called synchronously inside an action.
        expect($this->tracer->startTrace('broadcast route:/'))->toBeFalse();

        $this->tracer->span('render.regions', fn () => null);
        $this->tracer->endTrace();

        expect($this->store->recent())->toHaveCount(1);
        expect($this->store->recent()[0]['label'])->toBe('POST x');
    });

    test('span() records errors and rethrows', function (): void {
        $this->tracer->startTrace('GET /');

        $threw = false;

        try {
            $this->tracer->span('db.boom', function (): void {
                throw new RuntimeException('kaboom');
            });
        } catch (RuntimeException) {
            $threw = true;
        }
        $this->tracer->endTrace();

        expect($threw)->toBeTrue();
        $trace = $this->store->recent()[0];
        expect($trace['status'])->toBe('error');
        $boom = $trace['spans'][1];
        expect($boom['status'])->toBe('error');
        expect($boom['attributes']['error.message'])->toBe('kaboom');
    });

    test('startSpan returns null when no trace is active (zero-overhead path)', function (): void {
        expect($this->tracer->startSpan('orphan'))->toBeNull();
        expect($this->store->recent())->toHaveCount(0);
    });

    test('ambient current() round-trips', function (): void {
        Tracer::setCurrent($this->tracer);
        expect(Tracer::current())->toBe($this->tracer);
        Tracer::setCurrent(null);
        expect(Tracer::current())->toBeNull();
    });
});

describe('TraceStore', function (): void {
    test('ring buffer evicts oldest beyond capacity', function (): void {
        $store = new TraceStore(3);
        $tracer = new Tracer($store, testMode: true);

        foreach (['a', 'b', 'c', 'd', 'e'] as $label) {
            $tracer->startTrace($label);
            $tracer->endTrace();
        }

        $recent = $store->recent();
        expect($recent)->toHaveCount(3);
        // newest first
        expect(array_column($recent, 'label'))->toBe(['e', 'd', 'c']);
    });

    test('since() returns only traces past the cursor', function (): void {
        $store = new TraceStore(10);
        $tracer = new Tracer($store, testMode: true);

        $tracer->startTrace('a');
        $tracer->endTrace();
        $cursorAfterA = $store->cursor();

        $tracer->startTrace('b');
        $tracer->endTrace();
        $tracer->startTrace('c');
        $tracer->endTrace();

        $newer = $store->since($cursorAfterA);
        expect(array_column($newer, 'label'))->toBe(['b', 'c']);
        expect($store->since($store->cursor()))->toBe([]);
    });
});

describe('Sanitizer', function (): void {
    test('redacts credential-like keys', function (): void {
        $clean = Sanitizer::sanitizeAttributes([
            'password' => 'hunter2',
            'api_key' => 'abc',
            'authorization' => 'Bearer x',
            'session_token' => 'xyz',
            'rows' => 42,
            'route' => '/safe',
        ]);

        expect($clean['password'])->toBe('[redacted]');
        expect($clean['api_key'])->toBe('[redacted]');
        expect($clean['authorization'])->toBe('[redacted]');
        expect($clean['session_token'])->toBe('[redacted]');
        expect($clean['rows'])->toBe(42);
        expect($clean['route'])->toBe('/safe');
    });

    test('truncates oversized string values', function (): void {
        $long = str_repeat('x', 1000);
        $clean = Sanitizer::sanitizeAttributes(['body' => $long]);

        expect(strlen($clean['body']))->toBeLessThan(560);
        expect($clean['body'])->toContain('(1000B)');
    });
});
