<?php

declare(strict_types=1);

use Mbolli\PhpVia\Signal;

describe('Signal::bool()', function (): void {
    test('follows PHP truthiness for a value that is not a string', function (mixed $value, bool $expected): void {
        expect((new Signal('flag', $value))->bool())->toBe($expected);
    })->with([
        'int 2' => [2, true],
        'int -1' => [-1, true],
        'int 0' => [0, false],
        'float 0.5' => [0.5, true],
        'float 0.0' => [0.0, false],
        'null' => [null, false],
        'true' => [true, true],
        'false' => [false, false],
        'empty array' => [[], false],
        'array' => [[0], true],
    ]);

    test('reads a string as a boolean word', function (string $value, bool $expected): void {
        expect((new Signal('flag', $value))->bool())->toBe($expected);
    })->with([
        'true' => ['true', true],
        'TRUE' => ['TRUE', true],
        '1' => ['1', true],
        'yes' => ['yes', true],
        'On' => ['On', true],
        'false' => ['false', false],
        '0' => ['0', false],
        'off' => ['off', false],
        'empty' => ['', false],
        'other text' => ['maybe', false],
    ]);
});
