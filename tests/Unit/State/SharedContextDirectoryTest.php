<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\State\SharedContextDirectory;

/** @return array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int} */
function directoryRecord(int $expiresAt): array {
    return ['route' => '/docs', 'params' => [], 'sessionId' => 's', 'expiresAt' => $expiresAt];
}

/** Fill a directory with live records until it refuses one, and return how many it took. */
function fillDirectory(SharedContextDirectory $directory): int {
    for ($i = 0; $i < 100_000; ++$i) {
        try {
            $directory->put("/docs_/live{$i}", directoryRecord(time() + 3600));
        } catch (OverflowException) {
            return $i;
        }
    }

    throw new RuntimeException('the directory never filled up');
}

describe('SharedContextDirectory', function (): void {
    test('a full table reports OverflowException rather than OpenSwoole\'s own error', function (): void {
        $directory = new SharedContextDirectory(maxRows: 16);

        expect(fillDirectory($directory))->toBeGreaterThan(0);
    });

    test('a table full of expired records makes room for a new one', function (): void {
        $directory = new SharedContextDirectory(maxRows: 16);
        for ($i = 0; $i < 400; ++$i) {
            $directory->put("/docs_/old{$i}", directoryRecord(time() - 1));
        }

        $directory->put('/docs_/new', directoryRecord(time() + 60));

        expect($directory->get('/docs_/new'))->not->toBeNull()
            ->and($directory->count())->toBeLessThan(400)
        ;
    });

    test('registering a context with a full directory does not throw', function (): void {
        $app = createVia();
        $directory = new SharedContextDirectory(maxRows: 16);
        fillDirectory($directory);
        $app->getApp()->setContextDirectory($directory);

        $ctx = new Context('/docs_/late', '/docs', $app, null, 's');
        $app->getApp()->registerContext($ctx);

        expect($app->getApp()->getContext('/docs_/late'))->toBe($ctx);
    });

    test('tab state needs a live row, and a put of the record keeps it', function (): void {
        $directory = new SharedContextDirectory(maxRows: 16);
        $set = static fn (array $state): array => ['' => ['k' => serialize(1)]];

        expect($directory->changeState('/docs_/none', $set, '"k"'))->toBeFalse()
            ->and($directory->getState('/docs_/none'))->toBeNull()
        ;

        $directory->put('/docs_/a', directoryRecord(time() + 60));
        expect($directory->changeState('/docs_/a', $set, '"k"'))->toBeTrue();
        $directory->put('/docs_/a', directoryRecord(time() + 120));

        expect($directory->getState('/docs_/a'))->toBe(['' => ['k' => serialize(1)]]);
    });

    test('an expired row has no tab state', function (): void {
        $directory = new SharedContextDirectory(maxRows: 16);
        $directory->put('/docs_/old', directoryRecord(time() - 1));

        expect($directory->changeState('/docs_/old', static fn (array $state): array => ['' => ['k' => 'x']], '"k"'))->toBeFalse()
            ->and($directory->getState('/docs_/old'))->toBeNull()
        ;
    });

    test('with revival off, registering a context writes no directory row', function (): void {
        $app = createVia((new Config())->withContextTimeouts(revivalWindowMs: 0));
        $directory = new SharedContextDirectory(maxRows: 16);
        $app->getApp()->setContextDirectory($directory);

        $app->getApp()->registerContext(new Context('/docs_/a', '/docs', $app, null, 's'));

        expect($directory->count())->toBe(0);
    });
});
