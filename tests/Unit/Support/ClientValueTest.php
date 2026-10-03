<?php

declare(strict_types=1);

use Mbolli\PhpVia\Support\ClientValue;

// ClientValue: the types a signal takes from the browser, and which values pass, as what.

final class ClientValueTypesFixture {
    public int $count = 0;
    public float $ratio = 0.0;
    public ?bool $flag = null;
    public string $name = '';
    public array $items = [];
    public int|string $either = 0;
    public mixed $anything = null;
    public ?ArrayObject $object = null;
    public $untyped = 1;
}

describe('ClientValue::typesOf()', function (): void {
    test('takes the type family of the initial value', function (mixed $initial, ?array $types): void {
        expect(ClientValue::typesOf($initial))->toBe($types);
    })->with([
        'int' => [0, ['int', 'float']],
        'float' => [0.5, ['int', 'float']],
        'bool' => [false, ['bool']],
        'string' => ['', ['string']],
        'list' => [[], ['array']],
        'map' => [['a' => 1], ['array']],
        'null' => [null, null],
        'object' => [new ArrayObject(), null],
    ]);
});

describe('ClientValue::typesOfProperty()', function (): void {
    test('takes the declared type of a property', function (string $property, array|false|null $types): void {
        expect(ClientValue::typesOfProperty(new ReflectionProperty(ClientValueTypesFixture::class, $property)))->toBe($types);
    })->with([
        'int' => ['count', ['int']],
        'float' => ['ratio', ['int', 'float']],
        'nullable bool' => ['flag', ['bool', 'null']],
        'string' => ['name', ['string']],
        'array' => ['items', ['array']],
        'union' => ['either', ['string', 'int']],
        'mixed' => ['anything', null],
        'class' => ['object', ['null']],
        'untyped' => ['untyped', false],
    ]);
});

describe('ClientValue::coerce()', function (): void {
    test('passes a value of the type, or a lossless form of it, as that type', function (array $types, mixed $value, mixed $as): void {
        expect(ClientValue::coerce($value, $types))->toBe([$as]);
    })->with([
        'number from int' => [['int', 'float'], 5, 5],
        'number from float' => [['int', 'float'], 1.5, 1.5],
        'number from integer string' => [['int', 'float'], '5', 5],
        'number from decimal string' => [['int', 'float'], '1.5', 1.5],
        'number from a checkbox' => [['int', 'float'], true, 1],
        'int from integral float' => [['int'], 3.0, 3],
        'int from padded string' => [['int'], ' 07 ', 7],
        'float from int' => [['float'], 2, 2.0],
        'bool from a radio string' => [['bool'], 'false', false],
        'bool from yes' => [['bool'], 'yes', true],
        'bool from 1' => [['bool'], 1, true],
        'string from number' => [['string'], 42, '42'],
        'array' => [['array'], ['a' => 1], ['a' => 1]],
        'null where declared' => [['int', 'null'], null, null],
    ]);

    test('refuses a value that is no form of the type', function (array $types, mixed $value): void {
        expect(ClientValue::coerce($value, $types))->toBeNull();
    })->with([
        'number from text' => [['int', 'float'], 'abc'],
        'number from null (NaN)' => [['int', 'float'], null],
        'number from array' => [['int', 'float'], [1]],
        'int from fraction' => [['int'], 1.5],
        'int from fraction string' => [['int'], '1.5'],
        'bool from 2' => [['bool'], 2],
        'bool from text' => [['bool'], 'maybe'],
        'bool from null' => [['bool'], null],
        'string from bool' => [['string'], true],
        'string from array' => [['string'], ['x']],
        'string from null' => [['string'], null],
        'array from string' => [['array'], 'a,b'],
        'anything for a class type' => [[], 'x'],
    ]);
});
