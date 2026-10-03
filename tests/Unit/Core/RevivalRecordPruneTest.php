<?php

declare(strict_types=1);

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Core\Application;
use Mbolli\PhpVia\Support\Logger;
use Mbolli\PhpVia\Via;

/*
 * Single-worker revival records: every destroyed context adds one, and pruning ran on every
 * destroy. It used to walk all records and, above the cap, sort them all and log a warning each
 * time, which kept a worker busy for 45 s after a burst of page views that never opened a stream.
 * Records share one window, so the map is in expiry order and pruning works from the front.
 */

const PRUNE_CAP = 10_000;

/** @return array<string, array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int}> */
function revivalRecords(Via $app): array {
    return (new ReflectionProperty(Application::class, 'revivableContexts'))->getValue($app->getApp());
}

/** @param array<string, array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int}> $records */
function setRevivalRecords(Via $app, array $records): void {
    (new ReflectionProperty(Application::class, 'revivableContexts'))->setValue($app->getApp(), $records);
}

/** @return array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int} */
function revivalRecord(int $expiresAt): array {
    return ['route' => '/r', 'params' => [], 'sessionId' => null, 'expiresAt' => $expiresAt];
}

/** Register a context under $id and destroy it, as the cleanup timer does. */
function registerAndDestroy(Via $app, string $id): void {
    $app->getApp()->registerContext(new Context($id, '/r', $app));
    $app->getApp()->destroyContext($id);
}

/** @return ArrayObject<int, string> */
function captureRevivalWarnings(Via $app): ArrayObject {
    $lines = new ArrayObject();
    $logger = new class($lines) extends Logger {
        /** @param ArrayObject<int, string> $lines */
        public function __construct(private ArrayObject $lines) {
            parent::__construct('error');
        }

        public function log(string $level, string $message, ?Context $context = null): void {
            if ($level === 'warning' || $level === 'warn') {
                $this->lines[] = $message;
            }
        }
    };
    (new ReflectionProperty(Application::class, 'logger'))->setValue($app->getApp(), $logger);

    return $lines;
}

/** @return array<string, array{route: string, params: array<string, string>, sessionId: null|string, expiresAt: int}> */
function liveRecords(int $count, int $expiresAt): array {
    $records = [];
    for ($i = 0; $i < $count; ++$i) {
        $records['old' . $i] = revivalRecord($expiresAt);
    }

    return $records;
}

describe('revival record pruning', function (): void {
    test('a context destroyed again moves to the back, keeping the records in expiry order', function (): void {
        $app = createVia();
        setRevivalRecords($app, ['a' => revivalRecord(time() + 1), 'b' => revivalRecord(time() + 2)]);

        registerAndDestroy($app, 'a');

        $records = revivalRecords($app);
        expect(array_keys($records))->toBe(['b', 'a'])
            ->and($records['a']['expiresAt'])->toBeGreaterThanOrEqual($records['b']['expiresAt'])
        ;
    });

    test('pruning drops expired records from the front and stops at the first live one', function (): void {
        $app = createVia();
        // 'late' expired too, but behind a live record: the walk never reaches it.
        setRevivalRecords($app, [
            'gone1' => revivalRecord(time() - 5),
            'gone2' => revivalRecord(time() - 1),
            'live' => revivalRecord(time() + 60),
            'late' => revivalRecord(time() - 1),
        ]);

        registerAndDestroy($app, 'new');

        expect(array_keys(revivalRecords($app)))->toBe(['live', 'late', 'new'])
            ->and($app->getApp()->getRevivable('late'))->toBeNull()
            ->and($app->getApp()->getRevivable('live'))->not->toBeNull()
        ;
    });

    test('over the cap the oldest record is evicted from the front, without sorting', function (): void {
        $app = createVia();
        captureRevivalWarnings($app);
        // The front record expires last: a sort by expiry would evict 'old1' instead.
        $records = liveRecords(PRUNE_CAP, time() + 60);
        $records['old0'] = revivalRecord(time() + 600);
        setRevivalRecords($app, $records);

        registerAndDestroy($app, 'new');

        $after = revivalRecords($app);
        expect($after)->toHaveCount(PRUNE_CAP)
            ->and($after)->not->toHaveKey('old0')
            ->and(array_key_first($after))->toBe('old1')
            ->and(array_key_last($after))->toBe('new')
        ;
    });

    test('the eviction warning is logged at most every 10 seconds, with the count since the last one', function (): void {
        $app = createVia();
        $warnings = captureRevivalWarnings($app);
        setRevivalRecords($app, liveRecords(PRUNE_CAP, time() + 60));

        for ($i = 0; $i < 50; ++$i) {
            registerAndDestroy($app, 'burst' . $i);
        }
        expect($warnings->getArrayCopy())->toBe(['Revival records over the cap of 10000 records or 64 MiB of tab state: evicted 1 since the last warning']);

        (new ReflectionProperty(Application::class, 'revivableWarnedAt'))->setValue($app->getApp(), time() - 10);
        registerAndDestroy($app, 'later');

        expect($warnings->getArrayCopy())->toHaveCount(2)
            ->and($warnings[1])->toBe('Revival records over the cap of 10000 records or 64 MiB of tab state: evicted 50 since the last warning')
            ->and(revivalRecords($app))->toHaveCount(PRUNE_CAP)
        ;
    });

    test('destroying contexts at the cap costs about the same as with no records', function (): void {
        $destroyMs = function (int $records): float {
            $app = createVia();
            captureRevivalWarnings($app);
            setRevivalRecords($app, liveRecords($records, time() + 600));
            $ids = [];
            for ($i = 0; $i < 2_000; ++$i) {
                $ids[] = 'ctx' . $i;
                $app->getApp()->registerContext(new Context('ctx' . $i, '/r', $app));
            }
            $start = hrtime(true);
            foreach ($ids as $id) {
                $app->getApp()->destroyContext($id);
            }

            return (hrtime(true) - $start) / 1e6;
        };

        $destroyMs(0); // warm up
        $empty = $destroyMs(0);
        $atCap = $destroyMs(PRUNE_CAP);

        // The full walk with a sort every 100 destroys took about 100 times as long.
        expect($atCap)->toBeLessThan(max(4 * $empty, 50.0));
    });
});
