<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\Adapter\PsrResponseEmitter;
use Nyholm\Psr7\Response;

/*
 * PsrResponseEmitter Tests
 *
 * Tests the PSR-7 → OpenSwoole response emitter.
 */

describe('PsrResponseEmitter', function (): void {
    test('emits status code', function (): void {
        $emitter = new PsrResponseEmitter();
        $psrResponse = new Response(403, [], 'Forbidden');

        $swooleResponse = new class {
            public int $statusCode = 200;

            /** @var array<string, string> */
            public array $headers = [];

            public string $body = '';

            public function status(int $code): void {
                $this->statusCode = $code;
            }

            public function header(string $name, string $value): void {
                $this->headers[$name] = $value;
            }

            public function end(string $body = ''): void {
                $this->body = $body;
            }
        };

        // @phpstan-ignore argument.type
        $emitter->emit($psrResponse, $swooleResponse);

        expect($swooleResponse->statusCode)->toBe(403);
        expect($swooleResponse->body)->toBe('Forbidden');
    });

    test('emits headers', function (): void {
        $emitter = new PsrResponseEmitter();
        $psrResponse = new Response(200, [
            'Content-Type' => 'application/json',
            'X-Custom' => 'test-value',
        ], '{"ok":true}');

        $swooleResponse = new class {
            public int $statusCode = 200;

            /** @var array<string, string> */
            public array $headers = [];

            public string $body = '';

            public function status(int $code): void {
                $this->statusCode = $code;
            }

            public function header(string $name, string $value): void {
                $this->headers[$name] = $value;
            }

            public function end(string $body = ''): void {
                $this->body = $body;
            }
        };

        // @phpstan-ignore argument.type
        $emitter->emit($psrResponse, $swooleResponse);

        expect($swooleResponse->headers)->toHaveKey('Content-Type', 'application/json');
        expect($swooleResponse->headers)->toHaveKey('X-Custom', 'test-value');
        expect($swooleResponse->body)->toBe('{"ok":true}');
    });

    test('emits empty body for 204 response', function (): void {
        $emitter = new PsrResponseEmitter();
        $psrResponse = new Response(204);

        $swooleResponse = new class {
            public int $statusCode = 200;

            /** @var array<string, string> */
            public array $headers = [];

            public string $body = '';

            public function status(int $code): void {
                $this->statusCode = $code;
            }

            public function header(string $name, string $value): void {
                $this->headers[$name] = $value;
            }

            public function end(string $body = ''): void {
                $this->body = $body;
            }
        };

        // @phpstan-ignore argument.type
        $emitter->emit($psrResponse, $swooleResponse);

        expect($swooleResponse->statusCode)->toBe(204);
        expect($swooleResponse->body)->toBe('');
    });
});
