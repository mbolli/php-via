<?php

declare(strict_types=1);

/*
 * Fixture for HookedStatCacheTest: file caches checked inside Coroutine::run, which turns on the
 * file hooks. The hooked wrapper keeps stat() results across writes until clearstatcache().
 *
 * argv[1] picks the case:
 *   shell   a dev-mode and a production HtmlBuilder each render, see their shell rewritten, render again
 *
 * Prints one "key=value" per line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Rendering\HtmlBuilder;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;

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
});

array_map('unlink', glob($dir . '/*') ?: []);
rmdir($dir);
