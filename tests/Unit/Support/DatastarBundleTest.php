<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Support\DatastarBundle;

/**
 * The sha256 public/DATASTAR.md records under the heading of $file.
 */
function datastarNoteHash(string $file): ?string {
    $note = (string) file_get_contents(dirname(__DIR__, 3) . '/public/DATASTAR.md');
    $pattern = '/^## ' . preg_quote($file, '/') . '$.*?^- sha256: `([0-9a-f]{64})`/ms';

    return preg_match($pattern, $note, $m) === 1 ? $m[1] : null;
}

describe('bundled Datastar files', function (): void {
    test('each bundle hashes to the sha256 in public/DATASTAR.md', function (string $file): void {
        $hash = datastarNoteHash($file);

        expect($hash)->not->toBeNull()
            ->and(hash_file('sha256', dirname(__DIR__, 3) . '/public/' . $file))->toBe($hash)
        ;
    })->with(['datastar.js', 'datastar-rocket.js']);

    test('path() picks the plain bundle or the Rocket build', function (): void {
        $plain = DatastarBundle::path(false);
        $rocket = DatastarBundle::path(true);

        expect(realpath($plain))->toBe(realpath(dirname(__DIR__, 3) . '/public/datastar.js'))
            ->and(realpath($rocket))->toBe(realpath(dirname(__DIR__, 3) . '/public/datastar-rocket.js'))
            ->and(strtok((string) file_get_contents($plain), "\n"))->toBe('// Datastar v1.0.4')
            ->and(strtok((string) file_get_contents($rocket), "\n"))->toBe('// Datastar v1.0.4 + Rocket beta.2 (patched: patches/rocket)')
        ;
    });
});

describe('DatastarBundle versions', function (): void {
    test('the version is the first 10 hex digits of the sha256 and follows the content', function (): void {
        $file = sys_get_temp_dir() . '/via-ds-' . bin2hex(random_bytes(6)) . '.js';
        file_put_contents($file, 'export const a = 1');
        $first = DatastarBundle::version($file);
        file_put_contents($file, 'export const a = 2');
        $second = DatastarBundle::version($file);
        @unlink($file);

        expect($first)->toBe(substr(hash('sha256', 'export const a = 1'), 0, 10))
            ->and($second)->toBe(substr(hash('sha256', 'export const a = 2'), 0, 10))
            ->and($second)->not->toBe($first)
            ->and(DatastarBundle::url('/app/', $second))->toBe('/app/datastar.js?v=' . $second)
        ;
    });

    test('a missing file has no version and an unversioned URL', function (): void {
        expect(DatastarBundle::version(sys_get_temp_dir() . '/via-ds-missing.js'))->toBeNull()
            ->and(DatastarBundle::url('/', null))->toBe('/datastar.js')
        ;
    });
});

describe('Config Datastar options', function (): void {
    test('Rocket is off by default and toggled by withDatastarRocket()', function (): void {
        expect((new Config())->isDatastarRocketEnabled())->toBeFalse()
            ->and((new Config())->withDatastarRocket()->isDatastarRocketEnabled())->toBeTrue()
            ->and((new Config())->withDatastarRocket()->withDatastarRocket(false)->isDatastarRocketEnabled())->toBeFalse()
        ;
    });

    test('getDatastarUrl() carries the base path and the served bundle\'s version', function (): void {
        $plain = substr((string) hash_file('sha256', DatastarBundle::path(false)), 0, 10);
        $rocket = substr((string) hash_file('sha256', DatastarBundle::path(true)), 0, 10);

        expect((new Config())->getDatastarUrl())->toBe('/datastar.js?v=' . $plain)
            ->and((new Config())->withBasePath('/app')->getDatastarUrl())->toBe('/app/datastar.js?v=' . $plain)
            ->and((new Config())->withDatastarRocket()->getDatastarUrl())->toBe('/datastar.js?v=' . $rocket)
            ->and($rocket)->not->toBe($plain)
        ;
    });

    test('switching the option after a first call switches the URL', function (): void {
        $config = new Config();
        $plain = $config->getDatastarUrl();
        $rocket = $config->withDatastarRocket()->getDatastarUrl();

        expect($rocket)->not->toBe($plain)
            ->and($config->withDatastarRocket(false)->getDatastarUrl())->toBe($plain)
        ;
    });
});
