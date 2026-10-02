<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
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

describe('the static file cache', function (): void {
    $dir = null;

    beforeEach(function () use (&$dir): void {
        $dir = sys_get_temp_dir() . '/via-static-cache-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/app.css', 'body { color: red; }');
    });

    afterEach(function () use (&$dir): void {
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    });

    /** Rewrite a file with same-length bytes and its old mtime, so only a read could tell. */
    $swapBytes = function (string $path, string $bytes): void {
        $mtime = (int) filemtime($path);
        expect(strlen($bytes))->toBe((int) filesize($path));
        file_put_contents($path, $bytes);
        touch($path, $mtime);
    };

    /** @return array<string, array<string, array{mtime: int, size: int, body: string}>> */
    $cacheOf = fn (RequestHandler $handler): array => (new ReflectionProperty(RequestHandler::class, 'staticCache'))->getValue($handler);

    /** @return array<string, int> */
    $bytesOf = fn (RequestHandler $handler): array => (new ReflectionProperty(RequestHandler::class, 'staticCacheBytes'))->getValue($handler);

    test('a Brotli hit is served from memory without reading the file', function () use (&$dir): void {
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)->withBrotli()));
        $first = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $first);

        // stat() still works on an unreadable file, file_get_contents() does not.
        chmod($dir . '/app.css', 0);

        try {
            if (is_readable($dir . '/app.css')) {
                $this->markTestSkipped('running as root, the file stays readable');
            }
            $second = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $second);
        } finally {
            chmod($dir . '/app.css', 0o644);
        }

        expect($second->statusCode)->toBe(200)
            ->and($second->headers['Content-Encoding'])->toBe('br')
            ->and(brotli_uncompress($second->body))->toBe('body { color: red; }')
            ->and($second->headers['ETag'])->toBe($first->headers['ETag'])
        ;
    });

    test('text for clients without Brotli and binary files are served from memory too', function () use (&$dir, $swapBytes): void {
        file_put_contents($dir . '/dot.png', "\x89PNG-one");
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)->withBrotli()));
        foreach (['/app.css', '/dot.png'] as $path) {
            $handler->handleRequest(fakeStaticRequest($path, ['accept-encoding' => 'br']), new FakeStaticResponse());
            $handler->handleRequest(fakeStaticRequest($path), new FakeStaticResponse());
        }

        $swapBytes($dir . '/app.css', 'body { color: tan; }');
        $swapBytes($dir . '/dot.png', "\x89PNG-two");
        $css = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/app.css'), $css);
        $png = new FakeStaticResponse();
        $handler->handleRequest(fakeStaticRequest('/dot.png', ['accept-encoding' => 'br']), $png);

        expect($css->body)->toBe('body { color: red; }')
            ->and($css->headers)->not->toHaveKey('Content-Encoding')
            ->and($png->body)->toBe("\x89PNG-one")
            ->and($png->headers)->not->toHaveKey('Content-Encoding')
        ;
    });

    test('an edited file replaces its cached copy instead of adding one per mtime', function () use (&$dir, $cacheOf, $bytesOf): void {
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)->withBrotli()->withDevMode()));
        $etags = [];
        foreach (['red', 'blue', 'green'] as $i => $color) {
            file_put_contents($dir . '/app.css', "body { color: {$color}; }");
            touch($dir . '/app.css', time() + $i + 1);
            $response = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $response);
            expect(brotli_uncompress($response->body))->toBe("body { color: {$color}; }");
            $etags[] = $response->headers['ETag'];
        }

        $br = $cacheOf($handler)['br'];
        expect(array_unique($etags))->toHaveCount(3)
            ->and($br)->toHaveCount(1)
            ->and($bytesOf($handler)['br'])->toBe(strlen(reset($br)['body']))
        ;
    });

    test('a file over 2 MiB goes out with sendfile(), uncompressed and uncached', function () use (&$dir, $cacheOf): void {
        $big = str_repeat('a', (2 << 20) + 1) . '{}';
        file_put_contents($dir . '/big.json', $big);
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)->withBrotli()));
        $response = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/big.json', ['accept-encoding' => 'br']), $response);

        expect($response->sentFile)->toBe(realpath($dir . '/big.json'))
            ->and($response->headers)->not->toHaveKey('Content-Encoding')
            ->and($response->headers['Content-Type'])->toBe('application/json')
            ->and($response->body)->toBe($big)
            ->and($cacheOf($handler)['br'])->toBe([])
        ;
    });

    test('once 16 MiB are cached, further files go out with sendfile()', function () use (&$dir, $bytesOf): void {
        for ($i = 0; $i < 9; ++$i) {
            file_put_contents($dir . "/f{$i}.png", str_repeat((string) $i, 2 << 20));
        }
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)));
        $sent = [];
        for ($i = 0; $i < 9; ++$i) {
            $response = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest("/f{$i}.png"), $response);
            expect($response->body)->toBe(str_repeat((string) $i, 2 << 20));
            $sent[] = $response->sentFile !== null;
        }

        expect($sent)->toBe([false, false, false, false, false, false, false, false, true])
            ->and($bytesOf($handler)['identity'])->toBe(16 << 20)
        ;
    });

    test('in dev mode, deleted files make room once the cache is full, and live ones stay', function () use (&$dir, $cacheOf, $bytesOf): void {
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)->withDevMode()));
        $handler->handleRequest(fakeStaticRequest('/app.css'), new FakeStaticResponse());
        $sent = [];
        // A watch build writes app.<n>.js and deletes the previous one; 11 of them overflow 16 MiB.
        for ($i = 0; $i < 15; ++$i) {
            file_put_contents($dir . "/app.{$i}.js", str_repeat((string) ($i % 10), 3 << 19));
            $response = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest("/app.{$i}.js"), $response);
            expect($response->body)->toBe(str_repeat((string) ($i % 10), 3 << 19));
            $sent[] = $response->sentFile !== null;
            unlink($dir . "/app.{$i}.js");
        }

        $identity = $cacheOf($handler)['identity'];
        expect($sent)->not->toContain(true)
            ->and($identity)->toHaveKey(realpath($dir . '/app.css'))
            ->and($identity)->toHaveCount(6)
            ->and($bytesOf($handler)['identity'])->toBe(strlen('body { color: red; }') + 5 * (3 << 19))
        ;
    });
});

describe('the static dir lookup', function (): void {
    $dir = null;
    $outside = null;

    beforeEach(function () use (&$dir, &$outside): void {
        $dir = sys_get_temp_dir() . '/via-static-lookup-' . bin2hex(random_bytes(6));
        $outside = $dir . '-outside';
        mkdir($dir . '/_action', 0o777, true);
        mkdir($dir . '/_via');
        mkdir($dir . '/.well-known/acme-challenge', 0o777, true);
        mkdir($outside);
        foreach (['_sse', 'about', '_action/save.draft', '_via/devbar.js', 'feed.xml', '.well-known/acme-challenge/tok3n'] as $file) {
            file_put_contents($dir . '/' . $file, 'FILE ' . $file);
        }
        file_put_contents($outside . '/secret.css', 'SECRET');
        file_put_contents($outside . '/secret', 'SECRET');
        symlink($outside . '/secret.css', $dir . '/link.css');
    });

    afterEach(function () use (&$dir, &$outside): void {
        exec('rm -rf ' . escapeshellarg($dir) . ' ' . escapeshellarg($outside));
    });

    /** A handler with the routes registered, as Via::start() wires it. */
    $handlerWithRoutes = function (string $dir): RequestHandler {
        $via = createVia((new Config())->withStaticDir($dir));
        $via->page('/about', fn (Context $c) => $c->view(fn (): string => '<p>ROUTE about</p>'));
        $via->page('/feed.xml', fn (Context $c) => $c->view(fn (): string => '<p>ROUTE feed</p>'));
        $handler = requestHandlerFor($via);
        $handler->setRoutes($via->getRouter()->getRoutes());

        return $handler;
    };

    $get = function (RequestHandler $handler, string $path): FakeStaticResponse {
        $request = new class extends Request {
            public function getContent(): false|string {
                return '';
            }
        };
        $request->server = ['request_uri' => $path, 'request_method' => 'GET'];
        $request->header = [];
        $request->cookie = [];
        $request->get = [];
        $response = new FakeStaticResponse();
        $handler->handleRequest($request, $response);

        return $response;
    };

    test('/_sse, /_action, /_via and extension-less page routes never reach a same-named file', function () use (&$dir, $handlerWithRoutes, $get): void {
        $handler = $handlerWithRoutes($dir);

        $sse = $get($handler, '/_sse');
        $action = $get($handler, '/_action/save.draft');
        $devBar = $get($handler, '/_via/devbar.js');
        $page = $get($handler, '/about');

        expect([$sse->statusCode, $sse->body])->toBe([400, 'Invalid context'])
            ->and($action->statusCode)->toBe(405)
            ->and([$devBar->statusCode, $devBar->body])->toBe([404, 'Not Found'])
            ->and($page->statusCode)->toBe(200)
            ->and($page->body)->toContain('ROUTE about')
        ;
    });

    test('a path with an extension is still served from the static dir before routing', function () use (&$dir, $handlerWithRoutes, $get): void {
        $response = $get($handlerWithRoutes($dir), '/feed.xml');

        expect($response->statusCode)->toBe(200)
            ->and($response->body)->toBe('FILE feed.xml')
        ;
    });

    test('an extension-less file is served when no route matches', function () use (&$dir, $handlerWithRoutes, $get): void {
        $response = $get($handlerWithRoutes($dir), '/.well-known/acme-challenge/tok3n');

        expect($response->statusCode)->toBe(200)
            ->and($response->body)->toBe('FILE .well-known/acme-challenge/tok3n')
        ;
    });

    test('paths leading outside the static dir are refused', function () use (&$dir, &$outside, $handlerWithRoutes, $get): void {
        $handler = $handlerWithRoutes($dir);
        $name = basename((string) $outside);

        foreach (["/../{$name}/secret.css", "/../{$name}/secret", '/link.css', '/'] as $path) {
            $response = $get($handler, $path);
            expect([$path, $response->statusCode, $response->body])->toBe([$path, 404, 'Not Found']);
        }
    });

    test('a static dir symlink switched by a deploy keeps serving its old target until a reload', function () use (&$dir, $handlerWithRoutes, $get): void {
        foreach (['rel1' => 'one', 'rel2' => 'two'] as $release => $body) {
            mkdir("{$dir}/{$release}");
            file_put_contents("{$dir}/{$release}/app.css", $body);
        }
        symlink("{$dir}/rel1", "{$dir}/current");
        $handler = $handlerWithRoutes("{$dir}/current");
        $before = $get($handler, '/app.css');

        unlink("{$dir}/current");
        symlink("{$dir}/rel2", "{$dir}/current");
        clearstatcache(true);
        $after = $get($handler, '/app.css');

        expect([$before->statusCode, $before->body])->toBe([200, 'one'])
            ->and([$after->statusCode, $after->body])->toBe([200, 'one'])
        ;
    });
});
