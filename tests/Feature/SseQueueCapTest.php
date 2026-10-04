<?php

declare(strict_types=1);

// start() warns when Config::withSseMaxQueuedBytes() is above half of socket_buffer_size, which caps it there.

function sseQueueCapStart(string $maxQueued, string $socketBuffer = 'default'): string {
    return (string) shell_exec(
        'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/sse_queue_cap_server.php')
        . ' ' . escapeshellarg($maxQueued) . ' ' . escapeshellarg($socketBuffer) . ' 2>&1'
    );
}

describe('start() with an SSE queue threshold', function (): void {
    test('warns once when the threshold is above half of socket_buffer_size', function (): void {
        $out = sseQueueCapStart((string) (4 * 1024 * 1024));

        expect($out)->toContain("started\n")
            ->and(substr_count($out, 'withSseMaxQueuedBytes(4194304) is above half of socket_buffer_size (2097152), so a slow client\'s element frames are dropped from a backlog of 1048576 bytes'))->toBe(1)
            ->and($out)->toContain('Raise socket_buffer_size with withSwooleSettings() to 8388608')
        ;
    });

    test('says nothing with the defaults, with dropping off, or with a buffer twice the threshold', function (): void {
        foreach ([['default', 'default'], ['0', 'default'], [(string) (4 * 1024 * 1024), (string) (8 * 1024 * 1024)]] as [$maxQueued, $buffer]) {
            $out = sseQueueCapStart($maxQueued, $buffer);

            expect($out)->toContain("started\n")->not->toContain('withSseMaxQueuedBytes(');
        }
    });
});
