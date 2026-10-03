<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;

/*
 * new Via() warns when an import map integrity entry names php-via's Datastar bundle at another URL
 * than getDatastarUrl(): the browser then checks no hash for the bundle pages load.
 */

/** What new Via() logged at warn level for $config. */
function staleDatastarPinLog(Config $config): string {
    ob_start();

    try {
        new Via($config->withLogLevel('warn'));
    } finally {
        $log = (string) ob_get_clean();
    }

    return $log;
}

function stalePinHash(): string {
    return 'sha384-' . base64_encode(str_repeat("\1", 48));
}

describe('the stale Datastar pin warning', function (): void {
    test('a pin of getDatastarUrl() made after the settings it depends on is quiet', function (): void {
        $config = (new Config())->withDatastarRocket()->withBasePath('/app/');
        $config->withImportMap([], [$config->getDatastarUrl() => $config->getDatastarIntegrity()]);

        expect(staleDatastarPinLog($config))->not->toContain('Config::withImportMap() pins');
    });

    test('a pin made before withBasePath() warns with both URLs', function (): void {
        $config = new Config();
        $config->withImportMap([], [$config->getDatastarUrl() => $config->getDatastarIntegrity()]);
        $config->withBasePath('/app/');

        $log = staleDatastarPinLog($config);

        expect($log)->toContain("Config::withImportMap() pins '/datastar.js?v=")
            ->and($log)->toContain("pages load Datastar from '/app/datastar.js?v=")
        ;
    });

    test('a pin made before withDatastarRocket() warns, since the bundle and its version differ', function (): void {
        $config = new Config();
        $config->withImportMap([], [$config->getDatastarUrl() => $config->getDatastarIntegrity()]);
        $config->withDatastarRocket();

        expect(staleDatastarPinLog($config))->toContain('checks no hash for it');
    });

    test('a version copied from an earlier build warns, and so does the unversioned URL', function (string $pinned): void {
        $config = (new Config())->withImportMap([], [$pinned => stalePinHash()]);

        expect(staleDatastarPinLog($config))->toContain("pins '{$pinned}'");
    })->with([
        'old version' => ['/datastar.js?v=0000000000'],
        'unversioned' => ['/datastar.js'],
    ]);

    test('pins of other modules, and of a Datastar on another host, are quiet', function (): void {
        $config = (new Config())->withImportMap([], [
            '/js/chart.js' => stalePinHash(),
            'https://cdn.example.com/datastar.js' => stalePinHash(),
        ]);

        expect(staleDatastarPinLog($config))->not->toContain('Config::withImportMap() pins');
    });
});
