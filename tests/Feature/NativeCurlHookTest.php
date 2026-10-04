<?php

declare(strict_types=1);

use Mbolli\PhpVia\Via;
use Tests\Support\FixturePort;

/*
 * OpenSwoole 26.2's native curl hook segfaults the process on a curl request to any hostname, resolvable
 * or not, once libcurl is 8.20.0 or newer (curl#21558). Via's default hook_flags leave the hook out
 * there, and start() warns when the caller's flags keep it.
 */

/** @return array{code: int, out: string} */
function curlUnderHookFlags(int $flags): array {
    exec(
        'timeout 20 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/native_curl_hostname.php')
        . ' ' . $flags . ' 2>&1',
        $out,
        $code,
    );

    return ['code' => $code, 'out' => implode("\n", $out)];
}

/** Output of a Via server started with $settings, stopped by its first worker 50 ms after start. */
function startViaOnce(?string $settings): string {
    $port = FixturePort::pick(4300, 30);

    $code = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
        . '$config = (new Mbolli\PhpVia\Config())->withHost("127.0.0.1")->withPort(' . $port . ')'
        . ($settings !== null ? "->withSwooleSettings({$settings})" : '') . ';'
        . '$app = new Mbolli\PhpVia\Via($config);'
        . '$app->onWorkerStart(static fn () => OpenSwoole\Timer::after(50, static fn () => $app->getServer()?->shutdown()));'
        . '$app->start(); echo "stopped\n";';

    return (string) shell_exec('timeout 20 ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
}

beforeEach(function (): void {
    if (!function_exists('curl_init')) {
        $this->markTestSkipped('ext-curl required');
    }
});

test('a curl request to a hostname returns under the default and the narrow hook_flags', function (int $flags): void {
    $r = curlUnderHookFlags($flags);

    expect($r['code'])->toBe(0, $r['out']);
    expect($r['out'])->toContain('hook_flags=' . $flags)->toContain('curl_error=');
})->with([
    'default' => [Via::defaultHookFlags()],
    'narrow' => [Via::noFileIoHookFlags()],
]);

test('the native curl hook still crashes with this libcurl, so the default has to leave it out', function (): void {
    if (!Via::nativeCurlHookCrashes()) {
        $this->markTestSkipped('libcurl is older than 8.20.0');
    }

    $r = curlUnderHookFlags(SWOOLE_HOOK_ALL);

    expect($r['code'])->toBe(128 + 11, 'expected SIGSEGV: ' . $r['out']);
});

test('start() warns when hook_flags keep the native curl hook on an affected libcurl', function (?string $settings, bool $keepsHook): void {
    $out = startViaOnce($settings);

    expect($out)->toContain('stopped');
    if ($keepsHook && Via::nativeCurlHookCrashes()) {
        expect($out)->toContain('[WARN] hook_flags include SWOOLE_HOOK_NATIVE_CURL');
    } else {
        expect($out)->not->toContain('SWOOLE_HOOK_NATIVE_CURL');
    }
})->with([
    'default flags' => [null, false],
    'SWOOLE_HOOK_ALL' => ["['hook_flags' => SWOOLE_HOOK_ALL]", true],
    'the narrow set' => ["['hook_flags' => Mbolli\\PhpVia\\Via::noFileIoHookFlags()]", false],
]);
