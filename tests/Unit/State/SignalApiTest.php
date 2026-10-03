<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Via;

/** A Via that records the scopes its signals broadcast instead of rendering them. */
function broadcastRecorder(): Via {
    return new class((new Config())->withLogLevel('error')) extends Via {
        /** @var list<string> */
        public array $broadcasts = [];

        public function broadcast(string $scope): void {
            $this->broadcasts[] = $scope;
        }
    };
}

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

describe('Signal::ref()', function (): void {
    test('is the Datastar reference to the signal', function (): void {
        $signal = new Signal('room_lobby_count', 0, 'room:lobby');

        expect($signal->ref())->toBe('$room_lobby_count');
    });

    test('text() is a tombstone that names ref()', function (): void {
        expect(fn () => (new Signal('count', 1))->text())
            ->toThrow(BadMethodCallException::class, 'Signal::text() was removed in php-via 0.14. Use <span data-text="{$signal->ref()}">')
        ;
    });
});

describe('Signal writes without flags', function (): void {
    test('a flag passed to a write throws and names the fix, before anything is written', function (Closure $write, string $message): void {
        $signal = new Signal('count', 1);
        $signal->markSynced();

        expect(fn () => $write($signal))->toThrow(ArgumentCountError::class, $message);
        expect($signal->getValue())->toBe(1)
            ->and($signal->hasChanged())->toBeFalse()
        ;
    })->with([
        'setValue positional' => [static fn (Signal $s) => $s->setValue(2, false), 'Signal::setValue() takes only the value since php-via 0.14, but got 1 more positional argument. Delete the broadcast: and markChanged: arguments'],
        'setValue two positional' => [static fn (Signal $s) => $s->setValue(2, true, false), 'but got 2 more positional arguments.'],
        'setValue broadcast:' => [static fn (Signal $s) => $s->setValue(2, broadcast: false), 'Signal::setValue() takes only the value since php-via 0.14, but got broadcast:.'],
        'setValue markChanged:' => [static fn (Signal $s) => $s->setValue(2, markChanged: true, broadcast: false), 'but got markChanged: and broadcast:.'],
        'increment positional' => [static fn (Signal $s) => $s->increment(1, false), 'Signal::increment() takes only $by since php-via 0.14, but got 1 more positional argument.'],
        'increment broadcast:' => [static fn (Signal $s) => $s->increment(broadcast: false), 'Signal::increment() takes only $by since php-via 0.14, but got broadcast:.'],
        'mutate positional' => [static fn (Signal $s) => $s->mutate(static fn (int $v): int => $v + 1, false), 'Signal::mutate() takes only the mutator since php-via 0.14'],
        'mutate broadcast:' => [static fn (Signal $s) => $s->mutate(static fn (int $v): int => $v + 1, broadcast: false), 'but got broadcast:.'],
    ]);

    test('every write to a scoped signal broadcasts its scope unless it was declared without auto-broadcast', function (): void {
        $app = broadcastRecorder();
        $shared = new Signal('room_count', 0, 'room:a', true, null, $app);
        $quiet = new Signal('room_quiet', 0, 'room:b', false, null, $app);

        $shared->setValue(1);
        $shared->increment();
        $shared->mutate(static fn (int $v): int => $v + 1);
        $quiet->setValue(1);
        $quiet->increment();
        $quiet->mutate(static fn (int $v): int => $v + 1);

        expect($app->broadcasts)->toBe(['room:a', 'room:a', 'room:a'])
            ->and($shared->int())->toBe(3)
            ->and($quiet->int())->toBe(3)
        ;
    });

    test('injectValue() stores a client value without queuing, counting or broadcasting it', function (): void {
        $app = broadcastRecorder();
        $signal = new Signal('room_count', 0, 'room:a', true, true, $app);
        $signal->markSynced();

        $signal->injectValue(5);

        expect($signal->getValue())->toBe(5)
            ->and($signal->hasChanged())->toBeFalse()
            ->and($signal->writeCount())->toBe(0)
            ->and($app->broadcasts)->toBe([])
        ;
    });

    test('injectValue() leaves a pending patch pending', function (): void {
        $signal = new Signal('draft', '');

        $signal->injectValue('typed');

        expect($signal->hasChanged())->toBeTrue();
    });
});
