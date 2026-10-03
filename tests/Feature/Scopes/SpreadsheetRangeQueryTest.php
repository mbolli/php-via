<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Tracing\Tracer;
use Mbolli\PhpVia\Tracing\TraceStore;
use Mbolli\PhpVia\Via;
use OpenSwoole\Timer;

/*
 * Spreadsheet fan-out: identical viewport queries must not be repeated.
 *
 * /examples/spreadsheet renders per client — correctly, since each client has its own viewport,
 * cursor and selection — so a broadcast to N clients is N renders and that is irreducible.
 *
 * What IS reducible is the SQLite work inside them. Every render issues a `db.get_cell_range`
 * query for its viewport, and clients overwhelmingly sit on the SAME viewport (everyone starts at
 * 0,0). The cell data is identical for all of them, so the fan-out repeats one blocking query N
 * times — on a coroutine runtime where a blocking SQLite call stalls the whole worker.
 *
 * Cursor-only broadcasts, which are the common case (every focus move bumps the scope version),
 * do not touch cell data at all, so the repeat spans broadcasts too.
 */

$ssAutoload = dirname(__DIR__, 3) . '/website/vendor/autoload.php';
$ssReady = false;

if (is_file($ssAutoload)) {
    require_once $ssAutoload;
    $ssReady = class_exists('PhpVia\\Website\\Examples\\SpreadsheetExample');
}

if (!$ssReady) {
    test('spreadsheet range query dedup (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing — run composer install in website/ to enable')
    ;

    return;
}

/** Reset one private static on the example. */
function ssReset(string $prop, mixed $value): void {
    (new ReflectionProperty('PhpVia\\Website\\Examples\\SpreadsheetExample', $prop))->setValue(null, $value);
}

/** Invoke one of the example's private static helpers. */
function ssCall(string $method, mixed ...$args): mixed {
    $m = new ReflectionMethod('PhpVia\\Website\\Examples\\SpreadsheetExample', $method);

    return $m->invoke(null, ...$args);
}

/*
 * The caches and the connection are class statics, so they survive between tests in this process —
 * exactly as they survive between broadcasts in a worker, which is the behaviour under test. Give
 * each test a cold cache and its own in-memory database so a write can be exercised without
 * touching the website's real spreadsheet.db.
 */
beforeEach(function (): void {
    $db = new SQLite3(':memory:');
    $db->exec(
        'CREATE TABLE cells (
            row INTEGER NOT NULL,
            col INTEGER NOT NULL,
            value TEXT NOT NULL DEFAULT \'\',
            PRIMARY KEY (row, col)
        )'
    );
    $db->exec("INSERT INTO cells (row, col, value) VALUES (0, 0, 'seed'), (1, 1, 'seed')");

    ssReset('db', $db);
    ssReset('rangeCache', []);
    ssReset('extentCache', null);
    ssReset('cursors', []);
    ssReset('selections', []);
});

afterEach(function (): void {
    ssReset('db', null);
    ssReset('rangeCache', []);
    ssReset('extentCache', null);
});

afterAll(function (): void {
    Tracer::setCurrent(null);
    if (class_exists(Timer::class) && method_exists(Timer::class, 'clearAll')) {
        Timer::clearAll();
    }
});

/**
 * Render `$count` spreadsheet clients as one broadcast fan-out and return the span names emitted.
 *
 * @return list<string>
 */
function spreadsheetFanoutSpans(int $count, ?callable $mutate = null, bool $resetCaches = true): array {
    $app = new Via(
        (new Config())
            ->withLogLevel('error')
            ->withTemplateDir(dirname(__DIR__, 3) . '/website/templates')
    );
    $cls = 'PhpVia\\Website\\Examples\\SpreadsheetExample';
    $cls::register($app);

    $route = '/examples/spreadsheet';
    $handler = $app->getRouter()->getRoutes()[$route];

    $contexts = [];
    for ($i = 0; $i < $count; ++$i) {
        $id = $route . '_/ss' . $i;
        $ctx = new Context($id, $route, $app, null, 'sess_ss' . $i);
        $app->contexts[$id] = $ctx;
        $app->getApp()->registerContext($ctx);
        $app->getApp()->setContextSession($id, 'sess_ss' . $i);
        $app->registerContextInScope($ctx, Scope::TAB);
        $app->invokeHandlerWithParams($handler, $ctx, []);
        $contexts[] = $ctx;
    }

    if ($mutate !== null) {
        $mutate($contexts);
    }

    $tracer = new Tracer(new TraceStore(), testMode: true);
    Tracer::setCurrent($tracer);

    try {
        $tracer->startTrace('fanout');
        foreach ($contexts as $ctx) {
            $ctx->renderView(isUpdate: true);
        }
        $tracer->endTrace();

        $recent = $tracer->getStore()->recent(1);
        expect($recent)->not->toBeEmpty();

        /** @var list<array{name: string}> $spans */
        $spans = $recent[0]['spans'];

        return array_map(static fn (array $s): string => $s['name'], $spans);
    } finally {
        Tracer::setCurrent(null);
        foreach ($contexts as $ctx) {
            $app->getApp()->destroyContext($ctx->getId());
        }
    }
}

test('a fan-out of clients on the same viewport issues one range query', function (): void {
    $spans = spreadsheetFanoutSpans(4);

    $renders = count(array_filter($spans, static fn (string $n): bool => str_starts_with($n, 'render.')));
    $queries = count(array_filter($spans, static fn (string $n): bool => $n === 'db.get_cell_range'));

    expect($renders)->toBeGreaterThan(0, 'the fan-out must actually render');
    expect($queries)->toBe(1, '4 clients on the same viewport must share one range query');
});

test('clients on different viewports each get their own range query', function (): void {
    $spans = spreadsheetFanoutSpans(3, function (array $contexts): void {
        // Scroll each client to a distinct viewport — the data genuinely differs.
        foreach ($contexts as $i => $ctx) {
            $ctx->getSignal('viewRow')->setValue($i * 40);
        }
    });

    $queries = count(array_filter($spans, static fn (string $n): bool => $n === 'db.get_cell_range'));

    expect($queries)->toBe(3, 'distinct viewports must not share a cached range');
});

test('a cell write drops the cached ranges', function (): void {
    $first = spreadsheetFanoutSpans(2);
    expect(count(array_filter($first, static fn (string $n): bool => $n === 'db.get_cell_range')))->toBe(1);

    // A write can land inside any cached viewport, so the next render must re-query.
    ssCall('setCell', 0, 0, 'written');

    $second = spreadsheetFanoutSpans(2, resetCaches: false);
    $queries = count(array_filter($second, static fn (string $n): bool => $n === 'db.get_cell_range'));

    expect($queries)->toBe(1, 'the write must force exactly one fresh query for the shared viewport');
});

test('a cached range serves the same data the query would have', function (): void {
    $cold = ssCall('getCellRange', 0, 0, 20, 10);
    $warm = ssCall('getCellRange', 0, 0, 20, 10);
    expect($warm)->toBe($cold);
    expect($cold)->toHaveKey('0:0');

    ssCall('setCell', 0, 0, 'changed');
    expect(ssCall('getCellRange', 0, 0, 20, 10)['0:0'])->toBe('changed');

    ssCall('setCell', 0, 0, '');
    expect(ssCall('getCellRange', 0, 0, 20, 10))->not->toHaveKey('0:0');
});
