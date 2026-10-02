<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Via;
use OpenSwoole\Http\Request;
use Tests\Support\FakeStaticResponse;

/*
 * /datastar.js, /via.css, and withStaticDir() files must all emit ETag +
 * Last-Modified, honor If-None-Match / If-Modified-Since with a 304, apply
 * Config::getStaticCacheControl(), and invalidate the in-memory brotli cache
 * when the underlying file changes — none of which existed before (the old
 * code hardcoded "public, max-age=3600" with no validator support at all, and
 * cached compressed bytes forever under a path-only key).
 */

/**
 * @param array<string, string> $headers
 */
function fakeStaticRequest(string $path, array $headers = []): Request {
    $request = new Request();
    $request->server = ['request_uri' => $path, 'request_method' => 'GET'];
    $request->header = $headers;

    return $request;
}

function requestHandlerFor(Via $via): RequestHandler {
    return new RequestHandler($via, new SseHandler($via), new ActionHandler($via));
}

describe('framework-bundled static assets (/datastar.js, /via.css)', function (): void {
    test('serves 200 with ETag, Last-Modified and the configured Cache-Control', function (): void {
        $via = createVia();
        $handler = requestHandlerFor($via);
        $request = fakeStaticRequest('/datastar.js');
        $response = new FakeStaticResponse();

        $handler->handleRequest($request, $response);

        expect($response->statusCode)->toBe(200);
        expect($response->headers)->toHaveKey('ETag');
        expect($response->headers)->toHaveKey('Last-Modified');
        expect($response->headers['Cache-Control'])->toBe('public, max-age=3600, must-revalidate');
        expect($response->headers['Content-Type'])->toBe('application/javascript');
        expect($response->body)->toBe(file_get_contents(__DIR__ . '/../../public/datastar.js'));
    });

    test('returns 304 with an empty body when If-None-Match matches', function (): void {
        $via = createVia();
        $handler = requestHandlerFor($via);

        // First request to learn the current ETag.
        $first = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/via.css'), $first);
        $etag = $first->headers['ETag'];

        $second = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/via.css', ['if-none-match' => $etag]), $second);

        expect($second->statusCode)->toBe(304);
        expect($second->body)->toBe('');
        expect($second->headers['ETag'])->toBe($etag);
    });

    test('returns 304 when If-Modified-Since is at the file mtime', function (): void {
        $via = createVia();
        $handler = requestHandlerFor($via);

        $first = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/datastar.js'), $first);
        $lastModified = $first->headers['Last-Modified'];

        $second = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/datastar.js', ['if-modified-since' => $lastModified]), $second);

        expect($second->statusCode)->toBe(304);
    });

    test('serves 200 in full when the ETag does not match', function (): void {
        $via = createVia();
        $handler = requestHandlerFor($via);
        $response = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/datastar.js', ['if-none-match' => 'W/"stale"']), $response);

        expect($response->statusCode)->toBe(200);
        expect($response->body)->not->toBe('');
    });
});

describe('the Datastar bundle at /datastar.js', function (): void {
    test('serves the plain bundle by default', function (): void {
        $response = new FakeStaticResponse();
        requestHandlerFor(createVia())->handleRequest(fakeStaticRequest('/datastar.js'), $response);

        expect($response->statusCode)->toBe(200)
            ->and($response->headers['Content-Type'])->toBe('application/javascript')
            ->and($response->body)->toBe(file_get_contents(__DIR__ . '/../../public/datastar.js'))
        ;
    });

    test('serves the Rocket build with withDatastarRocket(), under its own ETag', function (): void {
        $plain = new FakeStaticResponse();
        requestHandlerFor(createVia())->handleRequest(fakeStaticRequest('/datastar.js'), $plain);
        $rocket = new FakeStaticResponse();
        requestHandlerFor(createVia((new Config())->withDatastarRocket()))->handleRequest(fakeStaticRequest('/datastar.js'), $rocket);

        expect($rocket->statusCode)->toBe(200)
            ->and($rocket->headers['Content-Type'])->toBe('application/javascript')
            ->and($rocket->body)->toBe(file_get_contents(__DIR__ . '/../../public/datastar-rocket.js'))
            ->and($rocket->headers['ETag'])->not->toBe($plain->headers['ETag'])
        ;
    });

    test('caches the current versioned URL for a year and anything else for the configured time', function (): void {
        $cacheControl = function (Config $config, ?string $version): string {
            $request = fakeStaticRequest('/datastar.js');
            if ($version !== null) {
                $request->get = ['v' => $version];
            }
            $response = new FakeStaticResponse();
            requestHandlerFor(createVia($config))->handleRequest($request, $response);

            return $response->headers['Cache-Control'];
        };
        $current = fn (Config $config): string => substr($config->getDatastarUrl(), -10);
        $rocket = (new Config())->withDatastarRocket();

        expect($cacheControl(new Config(), $current(new Config())))->toBe('public, max-age=31536000, immutable')
            ->and($cacheControl($rocket, $current($rocket)))->toBe('public, max-age=31536000, immutable')
            ->and($cacheControl($rocket, $current(new Config())))->toBe('public, max-age=3600, must-revalidate')
            ->and($cacheControl(new Config(), null))->toBe('public, max-age=3600, must-revalidate')
            ->and($cacheControl((new Config())->withDevMode(true), $current(new Config())))->toBe('no-cache')
            ->and($cacheControl((new Config())->withStaticCacheControl('no-store'), $current(new Config())))->toBe('no-store')
        ;
    });

    test('compresses the Rocket build like the plain one', function (): void {
        $response = new FakeStaticResponse();
        $via = createVia((new Config())->withDatastarRocket()->withBrotli());
        requestHandlerFor($via)->handleRequest(fakeStaticRequest('/datastar.js', ['accept-encoding' => 'br']), $response);

        expect($response->headers['Content-Encoding'])->toBe('br')
            ->and(brotli_uncompress($response->body))->toBe(file_get_contents(__DIR__ . '/../../public/datastar-rocket.js'))
        ;
    });
});

describe('Config::getStaticCacheControl() wired into withStaticDir() responses', function (): void {
    $dir = null;

    beforeEach(function () use (&$dir): void {
        $dir = sys_get_temp_dir() . '/via-static-test-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/app.css', 'body { color: red; }');
    });

    afterEach(function () use (&$dir): void {
        @unlink($dir . '/app.css');
        @rmdir($dir);
    });

    test('uses the 1 hour revalidated default outside devMode', function () use (&$dir): void {
        $via = createVia((new Config())->withStaticDir($dir));
        $handler = requestHandlerFor($via);
        $response = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/app.css'), $response);

        expect($response->statusCode)->toBe(200);
        expect($response->headers['Cache-Control'])->toBe('public, max-age=3600, must-revalidate');
        expect($response->headers['Content-Type'])->toBe('text/css; charset=utf-8');
        expect($response->body)->toBe('body { color: red; }');
    });

    test('relaxes to no-cache in devMode so edits are visible immediately', function () use (&$dir): void {
        $via = createVia((new Config())->withStaticDir($dir)->withDevMode());
        $handler = requestHandlerFor($via);
        $response = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/app.css'), $response);

        expect($response->headers['Cache-Control'])->toBe('no-cache');
    });

    test('an explicit withStaticCacheControl() overrides the devMode default', function () use (&$dir): void {
        $via = createVia((new Config())->withStaticDir($dir)->withStaticCacheControl('public, max-age=31536000, immutable'));
        $handler = requestHandlerFor($via);
        $response = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/app.css'), $response);

        expect($response->headers['Cache-Control'])->toBe('public, max-age=31536000, immutable');
    });

    test('a callable is invoked with the resolved file path and bare MIME type (no charset)', function () use (&$dir): void {
        file_put_contents($dir . '/app.js', 'console.log(1);');
        $seen = [];

        $config = (new Config())->withStaticDir($dir)->withStaticCacheControl(
            function (string $filePath, string $mimeType) use (&$seen): string {
                $seen[] = [$filePath, $mimeType];

                return $mimeType === 'application/javascript'
                    ? 'public, max-age=100'
                    : 'public, max-age=200';
            }
        );
        $handler = requestHandlerFor(createVia($config));

        $cssResponse = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/app.css'), $cssResponse);
        $jsResponse = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/app.js'), $jsResponse);

        expect($cssResponse->headers['Cache-Control'])->toBe('public, max-age=200');
        expect($jsResponse->headers['Cache-Control'])->toBe('public, max-age=100');
        expect($seen)->toBe([
            [realpath($dir . '/app.css'), 'text/css'],
            [realpath($dir . '/app.js'), 'application/javascript'],
        ]);

        @unlink($dir . '/app.js');
    });

    test('a changed file is served fresh, not stale brotli-compressed bytes from an earlier request', function () use (&$dir): void {
        $via = createVia((new Config())->withStaticDir($dir)->withBrotli());
        $handler = requestHandlerFor($via);

        $first = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $first);
        expect(brotli_uncompress($first->body))->toBe('body { color: red; }');

        // Edit the file with a distinct mtime one second in the future — filesystem
        // mtime resolution is 1s, so an immediate rewrite could otherwise collide.
        file_put_contents($dir . '/app.css', 'body { color: blue; }');
        touch($dir . '/app.css', time() + 1);
        clearstatcache(true, $dir . '/app.css');

        $second = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $second);

        expect(brotli_uncompress($second->body))->toBe('body { color: blue; }');
        expect($second->headers['ETag'])->not->toBe($first->headers['ETag']);
    });

    test('JavaScript modules and JSON get their MIME types and are compressed', function () use (&$dir): void {
        file_put_contents($dir . '/component.mjs', 'export const x = 1;');
        file_put_contents($dir . '/data.json', '{"a":1}');
        file_put_contents($dir . '/component.js.map', '{"version":3}');
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)->withBrotli()));

        $types = [];
        foreach (['/component.mjs', '/data.json', '/component.js.map'] as $path) {
            $response = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest($path, ['accept-encoding' => 'br']), $response);
            $types[$path] = [$response->headers['Content-Type'], $response->headers['Content-Encoding'] ?? null];
        }

        expect($types)->toBe([
            '/component.mjs' => ['application/javascript', 'br'],
            '/data.json' => ['application/json', 'br'],
            '/component.js.map' => ['application/json', 'br'],
        ]);

        @unlink($dir . '/component.mjs');
        @unlink($dir . '/data.json');
        @unlink($dir . '/component.js.map');
    });

    test('ETag reflects both files independently under a shared RequestHandler instance', function () use (&$dir): void {
        file_put_contents($dir . '/other.js', 'console.log(1);');

        $via = createVia((new Config())->withStaticDir($dir));
        $handler = requestHandlerFor($via);

        $cssResponse = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/app.css'), $cssResponse);

        $jsResponse = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/other.js'), $jsResponse);

        expect($cssResponse->headers['ETag'])->not->toBe($jsResponse->headers['ETag']);
        expect($jsResponse->headers['Content-Type'])->toBe('application/javascript');

        @unlink($dir . '/other.js');
    });
});
