<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Http\StaticBodyCache;
use Mbolli\PhpVia\Http\StaticBrotli;
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
        // new Via() freezes its Config, so each request gets a new one.
        $rocket = fn (): Config => (new Config())->withDatastarRocket();

        expect($cacheControl(new Config(), $current(new Config())))->toBe('public, max-age=31536000, immutable')
            ->and($cacheControl($rocket(), $current($rocket())))->toBe('public, max-age=31536000, immutable')
            ->and($cacheControl($rocket(), $current(new Config())))->toBe('public, max-age=3600, must-revalidate')
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

describe('Brotli for static files', function (): void {
    $dir = null;

    beforeEach(function () use (&$dir): void {
        if (!function_exists('brotli_compress')) {
            $this->markTestSkipped('ext-brotli required');
        }
        $dir = sys_get_temp_dir() . '/via-static-br-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/app.css', str_repeat('body { color: red; } ', 50));
    });

    afterEach(function () use (&$dir): void {
        exec('rm -rf ' . escapeshellarg((string) $dir));
    });

    test('is on without withBrotli(), with Vary for every client', function () use (&$dir): void {
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)));
        $br = new FakeStaticResponse();
        $plain = new FakeStaticResponse();
        $revalidated = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'gzip, br']), $br);
        $handler->handleRequest(fakeStaticRequest('/app.css'), $plain);
        $handler->handleRequest(fakeStaticRequest('/app.css', ['if-none-match' => $br->headers['ETag']]), $revalidated);

        expect($br->headers['Content-Encoding'])->toBe('br')
            ->and($br->headers['Vary'])->toBe('Accept-Encoding')
            ->and($br->body)->toBe(brotli_compress(str_repeat('body { color: red; } ', 50), 11, BROTLI_TEXT))
            ->and($plain->headers)->not->toHaveKey('Content-Encoding')
            ->and($plain->headers['Vary'])->toBe('Accept-Encoding')
            ->and($plain->headers['ETag'])->toBe($br->headers['ETag'])
            ->and($revalidated->statusCode)->toBe(304)
            ->and($revalidated->headers['Vary'])->toBe('Accept-Encoding')
        ;
    });

    test('a static level of 0 turns it off, Vary included', function () use (&$dir): void {
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)->withBrotli(staticLevel: 0)));
        $response = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $response);

        expect($response->headers)->not->toHaveKey('Content-Encoding')
            ->and($response->headers)->not->toHaveKey('Vary')
        ;
    });

    test('covers /via.css and both Datastar bundles', function (): void {
        $forms = [];
        foreach (['/via.css' => new Config(), '/datastar.js' => new Config(), 'rocket' => (new Config())->withDatastarRocket()] as $name => $config) {
            $response = new FakeStaticResponse();
            requestHandlerFor(createVia($config))->handleRequest(fakeStaticRequest($name === 'rocket' ? '/datastar.js' : $name, ['accept-encoding' => 'br']), $response);
            $forms[$name] = [$response->headers['Content-Encoding'] ?? null, strlen((string) brotli_uncompress($response->body))];
        }

        expect($forms)->toBe([
            '/via.css' => ['br', filesize(__DIR__ . '/../../public/via.css')],
            '/datastar.js' => ['br', filesize(__DIR__ . '/../../public/datastar.js')],
            'rocket' => ['br', filesize(__DIR__ . '/../../public/datastar-rocket.js')],
        ]);
    });

    test('compressible types get their content type and Brotli, compressed formats neither', function () use (&$dir): void {
        $types = [
            'favicon.ico' => 'image/x-icon',
            'site.webmanifest' => 'application/manifest+json',
            'module.wasm' => 'application/wasm',
            'font.ttf' => 'font/ttf',
            'font.otf' => 'font/otf',
            'notes.md' => 'text/markdown; charset=utf-8',
            'data.csv' => 'text/csv; charset=utf-8',
            'feed.rss' => 'application/rss+xml',
            'feed.atom' => 'application/atom+xml',
            'robots.txt' => 'text/plain; charset=utf-8',
            'page.html' => 'text/html; charset=utf-8',
            'page.htm' => 'text/html; charset=utf-8',
            'sitemap.xml' => 'application/xml',
            'font.woff2' => 'font/woff2',
            'font.woff' => 'font/woff',
            'photo.png' => 'image/png',
            'photo.webp' => 'image/webp',
            'anim.gif' => 'image/gif',
            'photo.avif' => 'image/avif',
            'doc.pdf' => 'application/pdf',
            'clip.mp4' => 'video/mp4',
            'clip.webm' => 'video/webm',
            'song.mp3' => 'audio/mpeg',
            'archive.zip' => 'application/octet-stream',
        ];
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)));

        $seen = [];
        $encodings = [];
        foreach (array_keys($types) as $file) {
            file_put_contents("{$dir}/{$file}", str_repeat("{$file} ", 40));
            $response = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest("/{$file}", ['accept-encoding' => 'br']), $response);
            $seen[$file] = $response->headers['Content-Type'];
            $encodings[$file] = $response->headers['Content-Encoding'] ?? 'identity';
        }

        expect($seen)->toBe($types)
            ->and(array_keys(array_filter($encodings, fn (string $e): bool => $e === 'identity')))
            ->toBe(['font.woff2', 'font.woff', 'photo.png', 'photo.webp', 'anim.gif', 'photo.avif', 'doc.pdf', 'clip.mp4', 'clip.webm', 'song.mp3', 'archive.zip'])
        ;
    });

    test('a sidecar over 2 MiB goes out with sendfile(), marked as Brotli', function () use (&$dir): void {
        touch($dir . '/app.css', time() - 10);
        $fp = fopen($dir . '/app.css.br', 'w');
        ftruncate($fp, (2 << 20) + 1);
        fclose($fp);
        $response = new FakeStaticResponse();

        requestHandlerFor(createVia((new Config())->withStaticDir($dir)))->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $response);

        expect($response->sentFile)->toBe(realpath($dir . '/app.css.br'))
            ->and($response->headers['Content-Encoding'])->toBe('br')
            ->and($response->headers['Content-Type'])->toBe('text/css; charset=utf-8')
        ;
    });

    test('what a worker sends while the helper compresses is not cacheable, the level 11 form is', function () use (&$dir): void {
        $via = createVia((new Config())->withStaticDir($dir));
        $quiet = static function (string $level, string $message): void {};
        $worker = new StaticBrotli($via->getConfig(), $quiet);
        $worker->attachHelper(static function (string $job): void {});
        $handler = new RequestHandler($via, new SseHandler($via), new ActionHandler($via), $worker);
        $bigJs = str_repeat('console.log(1); ', (StaticBrotli::INTERIM_BYTES >> 4) + 1);
        file_put_contents($dir . '/big.js', $bigJs);
        $send = function (string $path, array $headers = ['accept-encoding' => 'br']) use ($handler): FakeStaticResponse {
            $response = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest($path, $headers), $response);

            return $response;
        };

        $small = $send('/app.css');
        $big = $send('/big.js');
        $plain = $send('/app.css', []);
        $helper = new StaticBrotli($via->getConfig(), $quiet);
        foreach (['/app.css', '/big.js'] as $path) {
            $file = (string) realpath($dir . $path);
            $worker->receive($helper->compressForWorker($file, (int) filemtime($file), (int) filesize($file)));
        }
        $smallFinal = $send('/app.css');
        $bigFinal = $send('/big.js');

        $default = 'public, max-age=3600, must-revalidate';
        expect([$small->headers['Content-Encoding'] ?? 'identity', $small->headers['Cache-Control']])->toBe(['br', 'no-store'])
            ->and([$big->headers['Content-Encoding'] ?? 'identity', $big->headers['Cache-Control']])->toBe(['identity', 'no-store'])
            ->and($plain->headers['Cache-Control'])->toBe($default)
            ->and($smallFinal->body)->toBe(brotli_compress(str_repeat('body { color: red; } ', 50), 11, BROTLI_TEXT))
            ->and($smallFinal->headers['Cache-Control'])->toBe($default)
            ->and($bigFinal->body)->toBe(brotli_compress($bigJs, 11, BROTLI_TEXT))
            ->and($bigFinal->headers['Cache-Control'])->toBe($default)
        ;
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

    /** The uncompressed bodies a handler keeps. */
    $cacheOf = fn (RequestHandler $handler): StaticBodyCache => (new ReflectionProperty(RequestHandler::class, 'staticCache'))->getValue($handler);

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

    test('an edited file replaces its cached copy instead of adding one per mtime', function () use (&$dir): void {
        $via = createVia((new Config())->withStaticDir($dir)->withDevMode());
        $brotli = new StaticBrotli($via->getConfig(), static function (string $level, string $message): void {});
        $handler = new RequestHandler($via, new SseHandler($via), new ActionHandler($via), $brotli);
        $etags = [];
        foreach (['red', 'blue', 'green'] as $i => $color) {
            file_put_contents($dir . '/app.css', "body { color: {$color}; }");
            touch($dir . '/app.css', time() + $i + 1);
            $response = new FakeStaticResponse();
            $handler->handleRequest(fakeStaticRequest('/app.css', ['accept-encoding' => 'br']), $response);
            expect(brotli_uncompress($response->body))->toBe("body { color: {$color}; }");
            $etags[] = $response->headers['ETag'];
        }

        $br = $brotli->workerCache();
        expect(array_unique($etags))->toHaveCount(3)
            ->and($br->entries())->toHaveCount(1)
            ->and($br->bytes())->toBe(strlen(array_values($br->entries())[0]['body']))
        ;
    });

    test('a file over 2 MiB goes out with sendfile() to clients without Brotli, and compressed to the others', function () use (&$dir, $cacheOf): void {
        $big = '{"a":"' . str_repeat('a', (2 << 20) + 1) . '"}';
        file_put_contents($dir . '/big.json', $big);
        $handler = requestHandlerFor(createVia((new Config())->withStaticDir($dir)));
        $plain = new FakeStaticResponse();
        $br = new FakeStaticResponse();

        $handler->handleRequest(fakeStaticRequest('/big.json'), $plain);
        $handler->handleRequest(fakeStaticRequest('/big.json', ['accept-encoding' => 'br']), $br);

        expect($plain->sentFile)->toBe(realpath($dir . '/big.json'))
            ->and($plain->headers)->not->toHaveKey('Content-Encoding')
            ->and($plain->headers['Content-Type'])->toBe('application/json')
            ->and($plain->body)->toBe($big)
            ->and($cacheOf($handler)->entries())->toBe([])
            ->and($br->sentFile)->toBeNull()
            ->and($br->headers['Content-Encoding'])->toBe('br')
            ->and(brotli_uncompress($br->body))->toBe($big)
        ;
    });

    test('once 16 MiB are cached, further files go out with sendfile()', function () use (&$dir, $cacheOf): void {
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
            ->and($cacheOf($handler)->bytes())->toBe(16 << 20)
        ;
    });

    test('in dev mode, deleted files make room once the cache is full, and live ones stay', function () use (&$dir, $cacheOf): void {
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

        $identity = $cacheOf($handler);
        expect($sent)->not->toContain(true)
            ->and($identity->entries())->toHaveKey(realpath($dir . '/app.css'))
            ->and($identity->entries())->toHaveCount(6)
            ->and($identity->bytes())->toBe(strlen('body { color: red; }') + 5 * (3 << 19))
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

    test('dot segments, PHP files and NUL bytes answer 404 without a look at the disk, decoded or not', function () use (&$dir, &$outside, $handlerWithRoutes, $get): void {
        mkdir($dir . '/.git');
        mkdir($dir . '/assets/.cache', 0o777, true);
        $php = ['index.php', 'shell.PHTML', 'app.phar', 'a.php5', 'a.php8', 'a.phps', 'a.pht', 'a.phpt', 'a.inc'];
        foreach (['.env', '.git/config', '.git/HEAD.css', 'assets/.cache/app.js', '.htpasswd', 'a.css', ...$php] as $file) {
            file_put_contents($dir . '/' . $file, 'SECRET ' . $file);
        }
        $name = basename((string) $outside);
        $handler = $handlerWithRoutes($dir);

        $paths = [
            ...array_map(static fn (string $file): string => '/' . $file, $php),
            '/.env', '/%2eenv', '/%2Eenv', '/.git/config', '/.git/HEAD.css', '/%2egit/HEAD.css', '/assets/.cache/app.js',
            '/assets/%2Ecache/app.js', '/.htpasswd', '/INDEX.PHP', '/index%2ephp',
            "/../{$name}/secret.css", "/%2e%2e/{$name}/secret.css", "/..%2f{$name}/secret.css", "/%2E%2E%2F{$name}%2Fsecret.css",
            '/assets/../a.css', '/./a.css', '/.well-known/../.env', '/.well-known/.hidden', '/a.css%00.txt', '/%00',
            '/sub/.well-known/x.txt',
        ];
        $statuses = [];
        foreach ($paths as $path) {
            $response = $get($handler, $path);
            $statuses[$path] = [$response->statusCode, $response->body];
        }

        expect($statuses)->toBe(array_fill_keys($paths, [404, 'Not Found']))
            // Nothing was looked up, not even the static dir itself.
            ->and((new ReflectionProperty(RequestHandler::class, 'staticBase'))->getValue($handler))->toBeNull()
        ;
    });

    test('a link inside the dir to a dotfile or a PHP file there answers 404, a link to a servable file works', function () use (&$dir, $handlerWithRoutes, $get): void {
        foreach (['.env', 'config.php', 'real.css'] as $file) {
            file_put_contents("{$dir}/{$file}", 'SECRET ' . $file);
        }
        symlink("{$dir}/.env", "{$dir}/env.css");
        symlink("{$dir}/config.php", "{$dir}/config.js");
        symlink("{$dir}/real.css", "{$dir}/alias.css");
        $handler = $handlerWithRoutes($dir);

        $responses = array_map(static fn (FakeStaticResponse $r): array => [$r->statusCode, $r->body], [
            'env' => $get($handler, '/env.css'),
            'php' => $get($handler, '/config.js'),
            'alias' => $get($handler, '/alias.css'),
        ]);

        expect($responses)->toBe([
            'env' => [404, 'Not Found'],
            'php' => [404, 'Not Found'],
            'alias' => [200, 'SECRET real.css'],
        ]);
    });

    test('a percent-encoded path is decoded, so files with spaces are found', function () use (&$dir, $handlerWithRoutes, $get): void {
        file_put_contents($dir . '/my file.css', 'SPACED');
        file_put_contents($dir . '/ünï.css', 'UNICODE');
        $handler = $handlerWithRoutes($dir);

        expect([$get($handler, '/my%20file.css')->statusCode, $get($handler, '/my%20file.css')->body])->toBe([200, 'SPACED'])
            ->and($get($handler, '/' . rawurlencode('ünï.css'))->body)->toBe('UNICODE')
            ->and($get($handler, '/.well-known/acme-challenge/tok3n')->body)->toBe('FILE .well-known/acme-challenge/tok3n')
        ;
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
