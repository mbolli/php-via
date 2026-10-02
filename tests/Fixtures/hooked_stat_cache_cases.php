<?php

declare(strict_types=1);

/*
 * Fixture for HookedStatCacheTest: file caches checked inside Coroutine::run, which turns on the
 * file hooks. The hooked wrapper keeps stat() results across writes until clearstatcache().
 *
 * argv[1] picks the case:
 *   shell   a dev-mode and a production HtmlBuilder each render, see their shell rewritten, render again
 *   static  a dev-mode withStaticDir() file is fetched, rewritten and fetched again, with and without Brotli
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Http\ActionHandler;
use Mbolli\PhpVia\Http\RequestHandler;
use Mbolli\PhpVia\Http\SseHandler;
use Mbolli\PhpVia\Rendering\HtmlBuilder;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Http\Request;
use OpenSwoole\Runtime;
use Tests\Support\FakeStaticResponse;

$case = (string) ($argv[1] ?? 'shell');
$dir = sys_get_temp_dir() . '/via-hooked-stat-' . bin2hex(random_bytes(6));
mkdir($dir);

Coroutine::run(static function () use ($case, $dir): void {
    echo 'hook_flags=', Runtime::getHookFlags(), "\n";

    if ($case === 'shell') {
        $path = $dir . '/shell.html';
        $ctx = new Context('ctx', '/', new Via((new Config())->withLogLevel('error')));
        foreach (['dev' => true, 'prod' => false] as $label => $devMode) {
            file_put_contents($path, 'old {{ content }}');
            $builder = new HtmlBuilder($path, devMode: $devMode);
            $builder->buildDocument('', $ctx, 'ctx', '/');
            // No touch(): it clears the stat cache itself, which an editor in another process does not.
            file_put_contents($path, 'edited {{ content }}');
            echo $label, '=', trim($builder->buildDocument('', $ctx, 'ctx', '/')), "\n";
        }
    }

    if ($case === 'static') {
        $via = new Via((new Config())->withLogLevel('error')->withStaticDir($dir)->withBrotli()->withDevMode());
        $handler = new RequestHandler($via, new SseHandler($via), new ActionHandler($via));
        $fetch = static function (array $headers) use ($handler): FakeStaticResponse {
            $request = new Request();
            $request->server = ['request_uri' => '/app.css', 'request_method' => 'GET'];
            $request->header = $headers;
            $response = new FakeStaticResponse();
            $handler->handleRequest($request, $response);

            return $response;
        };
        foreach (['br' => ['accept-encoding' => 'br'], 'identity' => []] as $label => $headers) {
            file_put_contents($dir . '/app.css', 'body { color: red; }');
            $before = $fetch($headers);
            file_put_contents($dir . '/app.css', 'body { color: rebeccapurple; }');
            $after = $fetch($headers);
            $body = $label === 'br' ? (string) brotli_uncompress($after->body) : $after->body;
            echo "{$label}_etag_changed=", $before->headers['ETag'] !== $after->headers['ETag'] ? 'yes' : 'no', "\n";
            echo "{$label}_body={$body}\n";
        }
    }
});

array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);
