<?php

declare(strict_types=1);

use Mbolli\PhpVia\State\SharedTable;

/*
 * SharedTable Unit Tests
 *
 * All tests run in VIA_TEST_MODE which activates the PHP-array fallback
 * path. The behaviour is identical to the OpenSwoole\Table path except
 * the memory is not actually shared across processes (irrelevant for unit
 * tests — that property is verified at integration/manual level).
 */

describe('SharedTable (test mode / array fallback)', function (): void {
    test('set and get round-trips a scalar', function (): void {
        $table = new SharedTable(testMode: true);
        $table->set('counter', 42);
        expect($table->get('counter'))->toBe(42);
    });

    test('set and get round-trips an array', function (): void {
        $table = new SharedTable(testMode: true);
        $table->set('data', ['a' => 1, 'b' => [2, 3]]);
        expect($table->get('data'))->toBe(['a' => 1, 'b' => [2, 3]]);
    });

    test('set and get round-trips a boolean', function (): void {
        $table = new SharedTable(testMode: true);
        $table->set('flag', false);
        expect($table->get('flag'))->toBeFalse();
    });

    test('set and get round-trips null', function (): void {
        $table = new SharedTable(testMode: true);
        $table->set('nothing', null);
        // null is a valid stored value, distinct from "key not found"
        expect($table->get('nothing', 'sentinel'))->toBeNull();
    });

    test('get returns default when key is missing', function (): void {
        $table = new SharedTable(testMode: true);
        expect($table->get('missing'))->toBeNull();
        expect($table->get('missing', 99))->toBe(99);
    });

    test('delete removes a key', function (): void {
        $table = new SharedTable(testMode: true);
        $table->set('key', 'value');
        $table->delete('key');
        expect($table->get('key', 'gone'))->toBe('gone');
    });

    test('delete is a no-op for missing key', function (): void {
        $table = new SharedTable(testMode: true);
        // Should not throw
        $table->delete('nonexistent');
        expect(true)->toBeTrue();
    });

    test('overwriting a key replaces the value', function (): void {
        $table = new SharedTable(testMode: true);
        $table->set('x', 'first');
        $table->set('x', 'second');
        expect($table->get('x'))->toBe('second');
    });

    test('multiple keys are independent', function (): void {
        $table = new SharedTable(testMode: true);
        $table->set('a', 1);
        $table->set('b', 2);
        expect($table->get('a'))->toBe(1);
        expect($table->get('b'))->toBe(2);
    });

    test('set and get round-trips a plain object', function (): void {
        $table = new SharedTable(testMode: true);
        $obj = new stdClass();
        $obj->name = 'test';
        $obj->value = 99;
        $table->set('obj', $obj);
        $retrieved = $table->get('obj');
        expect($retrieved)->toBeInstanceOf(stdClass::class);
        expect($retrieved->name)->toBe('test');
        expect($retrieved->value)->toBe(99);
    });

    test('throws InvalidArgumentException for keys exceeding 64 chars', function (): void {
        $table = new SharedTable(testMode: true);
        $longKey = str_repeat('a', 65);
        expect(fn () => $table->set($longKey, 'x'))->toThrow(InvalidArgumentException::class);
    });

    test('accepts keys of exactly 64 chars', function (): void {
        $table = new SharedTable(testMode: true);
        $key = str_repeat('k', 64);
        $table->set($key, 'value');
        expect($table->get($key))->toBe('value');
    });

    test('overflow guard triggers when value exceeds maxValueBytes', function (): void {
        $table = new SharedTable(maxValueBytes: 32, testMode: true);
        // serialize('x') is small but a long string will exceed 32 bytes
        $bigValue = str_repeat('y', 100);
        expect(fn () => $table->set('key', $bigValue))->toThrow(OverflowException::class);
    });
});
