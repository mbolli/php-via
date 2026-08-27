<?php

declare(strict_types=1);

use Mbolli\PhpVia\State\SharedTable;

/*
 * SharedTable limits, measured against a real OpenSwoole\Table (ext-openswoole 26.2.0).
 *
 * Two things about the limits were documented wrongly, both in the permissive direction, so a
 * capacity plan over-delivered and then failed unpredictably:
 *
 * 1. Row capacity is NOT maxRows. getSize() rounds up (power of two, floor 64) and the usable
 *    count runs well past that:
 *
 *      maxRows |  getSize | usable | first rejection
 *           16 |       64 |     80 | (never, within 5x)
 *          100 |      128 |    256 | 253
 *         1024 |     1024 |   1776 | 1621
 *         4096 |     4096 |   8043 | 6635
 *
 *    Rejection is per-key-hash and intermittent — at maxRows=1024 the first failure was at
 *    insert 1621 yet 1776 inserts succeeded in total. Which keys get dropped near capacity is
 *    effectively arbitrary, and no eviction occurs.
 *
 * 2. The usable key length is 63, not 64. At 64 OpenSwoole emits "key is too long" on every
 *    write. It does not truncate — two 64-char keys differing only in the final character stay
 *    distinct — so this was log noise rather than corruption, but it is noise on the hot path.
 *
 * Exhaustion also surfaced as a bare OpenSwoole\Exception (a generic 500 through the request
 * path) rather than the shaped OverflowException the value-size check right above it models.
 */

test('exhausting the row capacity raises a shaped OverflowException', function (): void {
    $table = new SharedTable(maxRows: 64, maxValueBytes: 64);

    $thrown = null;
    // Real capacity exceeds maxRows by a wide margin, so drive well past it.
    for ($i = 0; $i < 2000; ++$i) {
        try {
            $table->set('k' . $i, 'v');
        } catch (Throwable $e) {
            $thrown = $e;

            break;
        }
    }

    expect($thrown)->not->toBeNull('the table must actually have filled');
    expect($thrown)->toBeInstanceOf(OverflowException::class);
    expect($thrown->getMessage())->toContain('withGlobalStateTableSize');
});

test('a 63-character key is accepted and round-trips', function (): void {
    $table = new SharedTable(maxRows: 64, maxValueBytes: 64);
    $key = str_repeat('k', 63);

    $table->set($key, 'value');

    expect($table->get($key))->toBe('value');
});

test('a 64-character key is rejected rather than warned about on every write', function (): void {
    $table = new SharedTable(maxRows: 64, maxValueBytes: 64);

    expect(fn () => $table->set(str_repeat('k', 64), 'x'))->toThrow(InvalidArgumentException::class);
});

test('the value-size guard still fires ahead of the row guard', function (): void {
    $table = new SharedTable(maxRows: 64, maxValueBytes: 64);

    expect(fn () => $table->set('k', str_repeat('y', 200)))->toThrow(OverflowException::class);
});
