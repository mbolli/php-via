<?php

declare(strict_types=1);

/*
 * Fixture for NativeCurlHookTest: one curl request to an unresolvable hostname under the hook_flags
 * in argv[1]. Prints curl_error=... when the request returns; with SWOOLE_HOOK_NATIVE_CURL and
 * libcurl 8.20 or newer the process dies with SIGSEGV before that.
 */

use OpenSwoole\Coroutine;
use OpenSwoole\Runtime;

Coroutine::set(['hook_flags' => (int) ($argv[1] ?? 0)]);
Coroutine::run(static function (): void {
    echo 'hook_flags=', Runtime::getHookFlags(), "\n";

    $ch = curl_init('http://via-hook-test.invalid/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 5]);
    curl_exec($ch);
    echo 'curl_error=', curl_error($ch), "\n";
});
