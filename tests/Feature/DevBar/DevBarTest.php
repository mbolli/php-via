<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\DevBar\DevBarController;
use Mbolli\PhpVia\DevBar\SignalManifest;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Tracing\Tracer;
use Mbolli\PhpVia\Via;

/*
 * End-to-end behaviour of the Dev Bar: overlay injection gating, the signal
 * manifest, the write guard, scope snapshots, and Context::span() wiring into
 * the ambient tracer.
 */

afterEach(function (): void {
    // The tracer is a process-global static; reset it between tests.
    Tracer::setCurrent(null);
});

function devBarVia(bool $writes = false): Via {
    $config = (new Config())->withLogLevel('error')->withDevMode()->withTracing(true);
    if ($writes) {
        $config->withTracingWrites(true);
    }

    return createVia($config);
}

describe('overlay injection', function (): void {
    test('injects the via-dev-bar element and assets before </body>', function (): void {
        $app = devBarVia();
        $ctx = new Context('inj_/1', '/demo', $app);
        $ctx->signal(5, 'count');
        $ctx->view(fn () => '<div id="x">hi</div>');

        $html = $app->buildHtmlDocument($ctx);

        expect($html)->toContain('<via-dev-bar');
        expect($html)->toContain('_via/devbar.js');
        expect($html)->toContain('_via/devbar.css');
        // injected before the closing body tag
        expect(strpos($html, '<via-dev-bar'))->toBeLessThan(strpos($html, '</body>'));
        // signal manifest rides along
        expect($html)->toContain('count');
    });

    test('does not inject when tracing is disabled', function (): void {
        $app = createVia((new Config())->withLogLevel('error'));
        $ctx = new Context('noinj_/1', '/demo', $app);
        $ctx->view(fn () => '<div id="x">hi</div>');

        $html = $app->buildHtmlDocument($ctx);

        expect($html)->not->toContain('<via-dev-bar');
    });
});

describe('SignalManifest', function (): void {
    test('captures id, name, scope and write-ability', function (): void {
        $app = devBarVia();
        $ctx = new Context('man_/1', '/demo', $app);
        $ctx->signal('Alice', 'name');                       // TAB → clientWritable
        $ctx->signal(0, 'shared', Scope::GLOBAL);            // scoped → not clientWritable

        $manifest = SignalManifest::build($ctx);
        $byName = array_column($manifest, null, 'name');

        expect($byName['name']['scope'])->toBe(Scope::TAB);
        expect($byName['name']['clientWritable'])->toBeTrue();
        expect($byName['shared']['scope'])->toBe(Scope::GLOBAL);
        expect($byName['shared']['clientWritable'])->toBeFalse();
    });
});

describe('DevBarController::writeSignal()', function (): void {
    test('403s when writes are disabled', function (): void {
        $app = devBarVia(writes: false);
        $ctx = new Context('w_/1', '/demo', $app);
        $signal = $ctx->signal('a', 'name');
        $app->contexts[$ctx->getId()] = $ctx;

        $result = (new DevBarController($app))->writeSignal($ctx->getId(), $signal->id(), 'b');

        expect($result['status'])->toBe(403);
        expect($signal->string())->toBe('a');
    });

    test('writes the value through the framework path when enabled', function (): void {
        $app = devBarVia(writes: true);
        $ctx = new Context('w_/2', '/demo', $app);
        $signal = $ctx->signal('a', 'name');
        $ctx->view(fn () => '<div id="x"></div>');
        $app->contexts[$ctx->getId()] = $ctx;

        $result = (new DevBarController($app))->writeSignal($ctx->getId(), $signal->id(), 'b');

        expect($result['status'])->toBe(200);
        expect($signal->string())->toBe('b');
    });

    test('404s for an unknown signal', function (): void {
        $app = devBarVia(writes: true);
        $ctx = new Context('w_/3', '/demo', $app);
        $app->contexts[$ctx->getId()] = $ctx;

        $result = (new DevBarController($app))->writeSignal($ctx->getId(), 'nope', 1);

        expect($result['status'])->toBe(404);
    });
});

describe('DevBarController::buildScopesSnapshot()', function (): void {
    test('reports registered scopes and their context counts', function (): void {
        $app = devBarVia();
        $ctx = new Context('s_/1', '/board', $app);
        $app->contexts[$ctx->getId()] = $ctx;
        $app->registerContextInScope($ctx, 'room:lobby');

        $snap = (new DevBarController($app))->buildScopesSnapshot();
        $scopesByName = array_column($snap['scopes'], null, 'scope');

        expect($scopesByName)->toHaveKey('room:lobby');
        expect($scopesByName['room:lobby']['contextCount'])->toBe(1);
        expect($snap['totalContexts'])->toBe(1);
    });
});

describe('Context::span() wiring', function (): void {
    test('records a nested span under the active trace via the ambient tracer', function (): void {
        $app = devBarVia();
        $tracer = $app->getTracer();
        expect($tracer)->not->toBeNull();

        $ctx = new Context('sp_/1', '/demo', $app);

        $tracer->startTrace('POST x');
        $out = $ctx->span('db.list_issues', fn () => 'rows', ['limit' => 10]);
        $tracer->endTrace();

        expect($out)->toBe('rows');

        $trace = $app->getTraceStore()->recent()[0];
        $names = array_column($trace['spans'], 'name');
        expect($names)->toContain('db.list_issues');

        $db = array_values(array_filter($trace['spans'], fn ($s) => $s['name'] === 'db.list_issues'))[0];
        expect($db['attributes']['limit'])->toBe(10);
        expect($db['category'])->toBe('db');
    });

    test('runs the callable with zero overhead when tracing is off', function (): void {
        Tracer::setCurrent(null);
        $app = createVia((new Config())->withLogLevel('error'));
        $ctx = new Context('sp_/2', '/demo', $app);

        expect($ctx->span('db.noop', fn () => 42))->toBe(42);
    });
});
