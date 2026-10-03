<?php

declare(strict_types=1);

use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Via;

/** @return array<string, Closure(Config): Config> one call per Config setter */
function configSetterCalls(): array {
    return [
        'withHost' => fn (Config $c) => $c->withHost('127.0.0.1'),
        'withPort' => fn (Config $c) => $c->withPort(3001),
        'withDevMode' => fn (Config $c) => $c->withDevMode(),
        'withLogLevel' => fn (Config $c) => $c->withLogLevel('warn'),
        'withTemplateDir' => fn (Config $c) => $c->withTemplateDir(__DIR__),
        'withTwigCacheDir' => fn (Config $c) => $c->withTwigCacheDir(sys_get_temp_dir()),
        'withStaticDir' => fn (Config $c) => $c->withStaticDir(__DIR__),
        'withStaticCacheControl' => fn (Config $c) => $c->withStaticCacheControl('no-store'),
        'withDatastarRocket' => fn (Config $c) => $c->withDatastarRocket(),
        'withImportMap' => fn (Config $c) => $c->withImportMap(['x' => '/x.js']),
        'withShellTemplate' => fn (Config $c) => $c->withShellTemplate(__FILE__),
        'withBasePath' => fn (Config $c) => $c->withBasePath('/app'),
        'withSseKeepAliveMs' => fn (Config $c) => $c->withSseKeepAliveMs(1000),
        'withSseMaxQueuedBytes' => fn (Config $c) => $c->withSseMaxQueuedBytes(1),
        'withBroadcastCoalescing' => fn (Config $c) => $c->withBroadcastCoalescing(),
        'withBroadcastTickMs' => fn (Config $c) => $c->withBroadcastTickMs(10),
        'withSwooleSettings' => fn (Config $c) => $c->withSwooleSettings(['max_conn' => 10]),
        'withSecureCookie' => fn (Config $c) => $c->withSecureCookie(),
        'withEmbeddable' => fn (Config $c) => $c->withEmbeddable(),
        'withTrustedOrigins' => fn (Config $c) => $c->withTrustedOrigins(['https://example.com']),
        'withAllowMissingOrigin' => fn (Config $c) => $c->withAllowMissingOrigin(),
        'withStrictTabSignals' => fn (Config $c) => $c->withStrictTabSignals(),
        'withActionRateLimit' => fn (Config $c) => $c->withActionRateLimit(10),
        'withGcIntervalMs' => fn (Config $c) => $c->withGcIntervalMs(0),
        'withContextTimeouts' => fn (Config $c) => $c->withContextTimeouts(cleanupDelayMs: 1),
        'withCertificate' => fn (Config $c) => $c->withCertificate('a.crt', 'a.key'),
        'withBrotli' => fn (Config $c) => $c->withBrotli(false),
        'withH2c' => fn (Config $c) => $c->withH2c(),
        'withBroker' => fn (Config $c) => $c->withBroker(new SwooleBroker()),
        'onBrokerError' => fn (Config $c) => $c->onBrokerError(static function (Throwable $e): void {}),
        'withWorkerNum' => fn (Config $c) => $c->withWorkerNum(2),
        'withGlobalStateTableSize' => fn (Config $c) => $c->withGlobalStateTableSize(64),
        'withScopedSignalTableSize' => fn (Config $c) => $c->withScopedSignalTableSize(64),
        'withContextDirectorySize' => fn (Config $c) => $c->withContextDirectorySize(64),
        'withSessionTableSize' => fn (Config $c) => $c->withSessionTableSize(64),
        'withPersistentGlobalState' => fn (Config $c) => $c->withPersistentGlobalState(sys_get_temp_dir() . '/never.db'),
        'withDevBar' => fn (Config $c) => $c->withDevBar(true),
        'withDevBarOptions' => fn (Config $c) => $c->withDevBarOptions(traces: 10),
    ];
}

describe('Config freeze', function (): void {
    test('every setter of Config is covered here', function (): void {
        $setters = [];
        foreach ((new ReflectionClass(Config::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ((string) $method->getReturnType() === Config::class) {
                $setters[] = $method->getName();
            }
        }
        sort($setters);
        $covered = array_keys(configSetterCalls());
        sort($covered);

        expect($covered)->toBe($setters);
    });

    test('every setter works before new Via()', function (): void {
        foreach (configSetterCalls() as $call) {
            $config = new Config();
            expect($call($config))->toBe($config);
        }
    });

    test('every setter throws after new Via(), naming itself', function (): void {
        $config = (new Config())->withLogLevel('error');
        new Via($config);

        foreach (configSetterCalls() as $name => $call) {
            expect(fn () => $call($config))->toThrow(
                LogicException::class,
                "Config::{$name}() was called after new Via(\$config), which freezes the Config"
            );
        }
    });

    test('a setter that throws leaves the setting as it was', function (): void {
        $config = (new Config())->withLogLevel('error')->withPort(4444);
        new Via($config);

        try {
            $config->withPort(5555);
        } catch (LogicException) {
        }

        expect($config->getPort())->toBe(4444);
    });

    test('getters keep working, and a second Via takes the frozen Config', function (): void {
        $config = (new Config())->withLogLevel('error')->withDevMode()->withBasePath('/app');
        $first = new Via($config);
        $second = new Via($config);

        expect($config->isDevMode())->toBeTrue()
            ->and($config->getBasePath())->toBe('/app/')
            ->and($second->getConfig())->toBe($first->getConfig())
        ;
    });
});
