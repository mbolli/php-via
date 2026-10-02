<?php

declare(strict_types=1);

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
});
