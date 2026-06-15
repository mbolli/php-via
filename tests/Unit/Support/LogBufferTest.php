<?php

declare(strict_types=1);

use Mbolli\PhpVia\Support\LogBuffer;
use Mbolli\PhpVia\Support\Logger;

/*
 * The Dev Bar log buffer + its Logger sink. Confirms the framework Logger tees
 * records into the buffer across all levels, with ring eviction and since()
 * cursor semantics matching the trace store.
 */

describe('LogBuffer', function (): void {
    test('ring buffer evicts oldest beyond capacity', function (): void {
        $buf = new LogBuffer(3);
        foreach (['a', 'b', 'c', 'd', 'e'] as $m) {
            $buf->push('info', $m);
        }

        $recent = $buf->recent();
        expect($recent)->toHaveCount(3);
        expect(array_column($recent, 'message'))->toBe(['e', 'd', 'c']); // newest first
    });

    test('since() returns only records past the cursor', function (): void {
        $buf = new LogBuffer(10);
        $buf->push('info', 'a');
        $cursor = $buf->cursor();
        $buf->push('warn', 'b');
        $buf->push('error', 'c');

        $newer = $buf->since($cursor);
        expect(array_column($newer, 'message'))->toBe(['b', 'c']);
        expect(array_column($newer, 'level'))->toBe(['warn', 'error']);
        expect($buf->since($buf->cursor()))->toBe([]);
    });

    test('clips very long messages', function (): void {
        $buf = new LogBuffer(2);
        $buf->push('error', str_repeat('x', 9000));

        $msg = $buf->recent()[0]['message'];
        expect(strlen($msg))->toBeLessThan(4100);
        expect($msg)->toEndWith('…');
    });
});

describe('Logger sink', function (): void {
    test('tees records into the buffer with context prefix, across levels', function (): void {
        $buf = new LogBuffer(50);
        $logger = new Logger('debug');
        $logger->setBuffer($buf);

        // Suppress the Logger's echo during the test.
        ob_start();
        $logger->info('server up');
        $logger->warn('slow query');
        $logger->error('boom');
        $logger->fatal('crash');
        ob_end_clean();

        $records = $buf->recent();
        expect(array_column($records, 'level'))->toBe(['fatal', 'error', 'warn', 'info']);
        expect($records[1]['message'])->toBe('boom');
    });

    test('respects the configured minimum level', function (): void {
        $buf = new LogBuffer(50);
        $logger = new Logger('warn');
        $logger->setBuffer($buf);

        ob_start();
        $logger->debug('noise');
        $logger->info('noise');
        $logger->warn('kept');
        ob_end_clean();

        expect($buf->recent())->toHaveCount(1);
        expect($buf->recent()[0]['message'])->toBe('kept');
    });
});
