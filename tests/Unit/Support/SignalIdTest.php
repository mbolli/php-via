<?php

declare(strict_types=1);

use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Support\SignalId;

/** @return list<string> every string of up to two bytes over a small alphabet with each kind of byte */
function signalIdSamples(): array {
    $alphabet = ['a', '1', '_', ':', '/', '-', '.', "\n", "\xc3"];
    $out = [''];
    foreach ($alphabet as $x) {
        $out[] = $x;
        foreach ($alphabet as $y) {
            $out[] = $x . $y;
        }
    }

    return $out;
}

describe('SignalId', function (): void {
    test('no two inputs share an id, across scoped, namespaced and TAB signals', function (): void {
        $samples = signalIdSamples();
        // Plain shapes (a:a, route paths), names that equal a joiner code, and via + ctx.
        $scopes = [...$samples, 'a:a', 'a_a', 'route', 'route:/', 'route:/a', 'via'];
        $names = [...$samples, 'k', 'n', 't', 'ctx'];
        $seen = array_fill_keys(SignalId::RESERVED, 'reserved');
        $claim = function (string $id, string $input) use (&$seen): void {
            expect($seen[$id] ?? $input)->toBe($input, "{$id} claimed twice");
            $seen[$id] = $input;
        };

        foreach ($scopes as $scope) {
            foreach ($names as $name) {
                foreach ([null, 'a', 'a_', 'a-', ''] as $ns) {
                    $claim(SignalId::scoped($scope, $ns, $name), serialize(['s', $scope, $ns, $name]));
                }
            }
        }
        foreach ($names as $name) {
            foreach ($samples as $ns) {
                $claim(SignalId::tab($ns, $name, 'unused'), serialize(['n', $ns, $name]));
            }
            foreach (['/f_/a1b2', 'ctx'] as $contextId) {
                $claim(SignalId::tab(null, $name, $contextId), serialize(['t', $contextId, $name]));
            }
        }
    });

    test('a trailing newline does not make a name or scope plain', function (): void {
        expect(SignalId::scoped(Scope::GLOBAL, null, "count\n"))->toBe('global_count_____kx0a')
            ->and(SignalId::scoped("route:/\n", null, 'x'))->toBe('route____x____csx0ak')
        ;
    });

    test('ids are valid Datastar signal names and start with the old sanitised id', function (): void {
        foreach (signalIdSamples() as $raw) {
            $old = (string) preg_replace('/[^a-zA-Z0-9_]/', '_', 'room:' . $raw . ':x');
            $id = SignalId::scoped('room:' . $raw, null, 'x');

            expect($id)->toMatch('/^[A-Za-z0-9_]+$/')->toStartWith($old);
        }
    });
});
