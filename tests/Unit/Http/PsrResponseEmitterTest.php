<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\Adapter\PsrResponseEmitter;
use Nyholm\Psr7\Response;
use Psr\Http\Message\StreamInterface;

/*
 * PsrResponseEmitter Tests
 *
 * Tests the PSR-7 → OpenSwoole response emitter.
 */

/**
 * A body of unknown size that reads $parts in turn, throwing the ones that are throwables.
 *
 * @param list<string|Throwable> $parts
 */
function emitterBody(array $parts): StreamInterface {
    return new class($parts) implements StreamInterface {
        /** @param list<string|Throwable> $parts */
        public function __construct(private array $parts) {}

        public function __toString(): string {
            return '';
        }

        public function close(): void {}

        public function detach() {
            return null;
        }

        public function getSize(): ?int {
            return null;
        }

        public function tell(): int {
            return 0;
        }

        public function eof(): bool {
            return $this->parts === [];
        }

        public function isSeekable(): bool {
            return false;
        }

        public function seek(int $offset, int $whence = SEEK_SET): void {}

        public function rewind(): void {}

        public function isWritable(): bool {
            return false;
        }

        public function write(string $string): int {
            return 0;
        }

        public function isReadable(): bool {
            return true;
        }

        public function read(int $length): string {
            $part = array_shift($this->parts);
            if ($part instanceof Throwable) {
                throw $part;
            }

            return (string) $part;
        }

        public function getContents(): string {
            return '';
        }

        public function getMetadata(?string $key = null): mixed {
            return null;
        }
    };
}

/** A response that records every call made on it. */
function emitterRecorder(): object {
    return new class {
        /** @var list<string> */
        public array $calls = [];

        public function status(int $code): void {
            $this->calls[] = "status {$code}";
        }

        public function header(string $name, string $value): void {
            $this->calls[] = "header {$name}";
        }

        public function write(string $chunk): bool {
            $this->calls[] = "write {$chunk}";

            return true;
        }

        public function end(?string $body = null): void {
            $this->calls[] = 'end';
        }

        public function close(): void {
            $this->calls[] = 'close';
        }
    };
}

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

    test('writes a body of unknown size chunk by chunk as it reads it, and stops when the client leaves', function (): void {
        $chunks = new ArrayIterator(['a', 'b', 'c']);
        $body = new class($chunks) implements StreamInterface {
            /** @param ArrayIterator<int, string> $chunks */
            public function __construct(private ArrayIterator $chunks) {}

            public function __toString(): string {
                return 'whole';
            }

            public function close(): void {}

            public function detach() {
                return null;
            }

            public function getSize(): ?int {
                return null;
            }

            public function tell(): int {
                return 0;
            }

            public function eof(): bool {
                return !$this->chunks->valid();
            }

            public function isSeekable(): bool {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void {}

            public function rewind(): void {}

            public function isWritable(): bool {
                return false;
            }

            public function write(string $string): int {
                return 0;
            }

            public function isReadable(): bool {
                return true;
            }

            public function read(int $length): string {
                $chunk = (string) $this->chunks->current();
                $this->chunks->next();

                return $chunk;
            }

            public function getContents(): string {
                return '';
            }

            public function getMetadata(?string $key = null): mixed {
                return null;
            }
        };
        $swooleResponse = new class {
            /** @var list<string> */
            public array $writes = [];

            public ?string $ended = null;

            public int $acceptWrites = PHP_INT_MAX;

            public function status(int $code): void {}

            public function header(string $name, string $value): void {}

            public function write(string $chunk): bool {
                if (count($this->writes) >= $this->acceptWrites) {
                    return false;
                }
                $this->writes[] = $chunk;

                return true;
            }

            public function end(string $body = ''): void {
                $this->ended = $body;
            }
        };

        // @phpstan-ignore argument.type
        (new PsrResponseEmitter())->emit((new Response(200))->withBody($body), $swooleResponse);

        expect($swooleResponse->writes)->toBe(['a', 'b', 'c'])
            ->and($swooleResponse->ended)->toBe('')
        ;

        $chunks->rewind();
        $leaving = clone $swooleResponse;
        $leaving->writes = [];
        $leaving->ended = null;
        $leaving->acceptWrites = 1;

        // @phpstan-ignore argument.type
        (new PsrResponseEmitter())->emit((new Response(200))->withBody($body), $leaving);

        expect($leaving->writes)->toBe(['a'])->and($leaving->ended)->toBeNull();
    });

    test('a body that throws before its first chunk leaves the response untouched, and the throw goes on', function (): void {
        $response = emitterRecorder();
        $psr = (new Response(200, ['Content-Type' => 'text/event-stream']))->withBody(emitterBody(['', new RuntimeException('upstream down')]));

        // @phpstan-ignore argument.type
        expect(fn () => (new PsrResponseEmitter())->emit($psr, $response))->toThrow(RuntimeException::class, 'upstream down')
            ->and($response->calls)->toBe([])
        ;
    });

    test('a body that throws after a chunk closes the connection, and the throw goes on', function (): void {
        $response = emitterRecorder();
        $psr = (new Response(200, ['Content-Type' => 'text/event-stream']))->withBody(emitterBody(["data: 1\n\n", new RuntimeException('upstream failed mid-stream')]));

        // @phpstan-ignore argument.type
        expect(fn () => (new PsrResponseEmitter())->emit($psr, $response))->toThrow(RuntimeException::class, 'upstream failed mid-stream')
            ->and($response->calls)->toBe(['status 200', 'header Content-Type', "write data: 1\n\n", 'close'])
        ;
    });

    test('sends a HEAD response\'s headers with the body\'s length and no body', function (): void {
        $swooleResponse = new class {
            /** @var array<string, string> */
            public array $headers = [];

            public ?string $ended = null;

            public function status(int $code): void {}

            public function header(string $name, string $value): void {
                $this->headers[$name] = $value;
            }

            public function end(?string $body = null): void {
                $this->ended = $body ?? '';
            }
        };

        // @phpstan-ignore argument.type
        (new PsrResponseEmitter())->emit(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'), $swooleResponse, withoutBody: true);

        expect($swooleResponse->headers)->toBe(['Content-Type' => 'application/json', 'Content-Length' => '11'])
            ->and($swooleResponse->ended)->toBe('')
        ;
    });
});
