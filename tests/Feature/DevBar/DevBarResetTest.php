<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Tracing\Tracer;
use Mbolli\PhpVia\Via;

// POST /_via/reset clears the worker's trace buffer, in dev mode only: outside it the Dev Bar is read-only.

afterEach(function (): void {
    Tracer::setCurrent(null);
});

function resetApp(bool $devMode): TestApp {
    return new TestApp((new Config())->withLogLevel('error')->withDevMode($devMode)->withDevBar(true), static function (Via $via): void {
        $via->page('/p', static fn (Context $c) => $c->view(static fn (): string => '<p id="p">p</p>'));
    });
}

describe('POST /_via/reset', function (): void {
    test('outside dev mode refuses, and the traces stay', function (): void {
        $app = resetApp(devMode: false);
        $html = $app->open('/p')->html();
        $traces = count($app->via()->getTraceStore()?->recent() ?? []);

        $response = $app->request('POST', '/_via/reset', '', ['origin' => $app->origin()]);

        expect($response->getStatusCode())->toBe(403)
            ->and((string) $response->getBody())->toContain('needs dev mode')
            ->and($traces)->toBeGreaterThan(0)
            ->and(count($app->via()->getTraceStore()?->recent() ?? []))->toBe($traces)
            ->and($html)->toContain('&quot;devMode&quot;:false')
        ;
        $app->shutdown();
    });

    test('in dev mode clears them', function (): void {
        $app = resetApp(devMode: true);
        $html = $app->open('/p')->html();
        expect($app->via()->getTraceStore()?->recent())->not->toBe([]);

        $response = $app->request('POST', '/_via/reset', '', ['origin' => $app->origin()]);

        expect($response->getStatusCode())->toBe(200)
            ->and($app->via()->getTraceStore()?->recent())->toBe([])
            ->and($html)->toContain('&quot;devMode&quot;:true')
        ;
        $app->shutdown();
    });
});
