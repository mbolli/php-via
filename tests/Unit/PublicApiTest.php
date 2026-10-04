<?php

declare(strict_types=1);

use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Via;

test('Config, Signal, Action and Scope are final; Via and Context stay open for test doubles', function (): void {
    foreach ([Config::class, Signal::class, Action::class, Scope::class] as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeTrue($class);
    }
    foreach ([Via::class, Context::class] as $class) {
        expect((new ReflectionClass($class))->isFinal())->toBeFalse($class);
    }
});

test('Config getters besides the ones apps read are @internal', function (): void {
    $public = [];
    foreach ((new ReflectionClass(Config::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $internal = str_contains((string) $method->getDocComment(), '@internal');
        if (preg_match('/^(get|is)[A-Z]/', $method->getName()) === 1 && (string) $method->getReturnType() !== 'never' && !$internal) {
            $public[] = $method->getName();
        }
    }
    sort($public);

    expect($public)->toBe(['getBasePath', 'getContextRevivalWindowMs', 'getDatastarIntegrity', 'getDatastarUrl', 'getImportMap', 'isDevMode', 'isHttps']);
});

test('the Config getters 0.13.1 shipped still answer, from what the setters set', function (): void {
    $broker = new SwooleBroker();
    $config = (new Config())
        ->withLogLevel('error')
        ->withHost('127.0.0.1')
        ->withPort(4444)
        ->withWorkerNum(3)
        ->withStaticDir('/srv/public/')
        ->withStaticCacheControl('no-store')
        ->withContextTimeouts(cleanupDelayMs: 7, connectMs: 8)
        ->withDevBar(true)
        ->withDevBarOptions(traces: 12, pollMs: 13)
        ->withBroker($broker)
    ;
    new Via($config);

    expect($config->getHost())->toBe('127.0.0.1')
        ->and($config->getPort())->toBe(4444)
        ->and($config->getLogLevel())->toBe('error')
        ->and($config->getWorkerNum())->toBe(3)
        ->and($config->getStaticDir())->toBe('/srv/public')
        ->and($config->getStaticCacheControl('/srv/public/a.css', 'text/css'))->toBe('no-store')
        ->and($config->getContextCleanupDelayMs())->toBe(7)
        ->and($config->getContextConnectTimeoutMs())->toBe(8)
        ->and($config->isTracingEnabled())->toBeTrue()
        ->and($config->isTracingWritesEnabled())->toBeFalse()
        ->and($config->getTraceBufferSize())->toBe(12)
        ->and($config->getSsePollIntervalMs())->toBe(13)
        ->and($config->getBroker())->toBe($broker)
        ->and((new Config())->withWorkerNum(2)->getBroker())->toBeInstanceOf(SwooleBroker::class)
    ;

    $shipped = ['getTwigCacheDir', 'getStaticDir', 'getStaticCacheControl', 'getSsePollIntervalMs', 'getSseKeepAliveMs', 'getSseMaxQueuedBytes',
        'isBroadcastCoalescingEnabled', 'getBroadcastTickMs', 'getHost', 'getPort', 'getLogLevel', 'getTemplateDir', 'getShellTemplate',
        'getBasePath', 'getSwooleSettings', 'getSecureCookie', 'getSessionCookieSameSite', 'isSessionCookiePartitioned', 'getFrameAncestors',
        'getTrustedOrigins', 'getAllowMissingOrigin', 'getStrictTabSignals', 'getActionRateLimit', 'getActionRateWindow', 'getGcIntervalMs',
        'getContextCleanupDelayMs', 'getContextConnectTimeoutMs', 'getContextRevivalWindowMs', 'getSslCertFile', 'getSslKeyFile', 'isHttps',
        'getBrotli', 'getBrotliDynamicLevel', 'getBrotliStaticLevel', 'isH2c', 'getBrokerErrorHandler', 'getBroker', 'getWorkerNum',
        'getSessionTableRows', 'getSessionTableValueBytes', 'getContextDirectoryRows', 'getContextDirectoryRecordBytes',
        'getContextDirectoryTtlSeconds', 'getScopedSignalTableRows', 'getScopedSignalTableValueBytes', 'getGlobalStatePath',
        'getGlobalStateFlushMs', 'getGlobalStateTableRows', 'getGlobalStateTableValueBytes', 'isTracingEnabled', 'isTracingWritesEnabled',
        'getTraceBufferSize'];
    foreach ($shipped as $getter) {
        expect((string) (new ReflectionMethod(Config::class, $getter))->getReturnType())->not->toBe('never', $getter);
    }
});
