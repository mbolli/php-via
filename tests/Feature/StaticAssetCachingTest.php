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
