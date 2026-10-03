<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Core;

use Mbolli\PhpVia\Broker\InMemoryBroker;
use Mbolli\PhpVia\Broker\MessageBroker;
use Mbolli\PhpVia\Broker\SwooleBroker;
use Mbolli\PhpVia\Rendering\Bootstrap;
use Mbolli\PhpVia\Rendering\TemplateEngine;

/**
 * What the framework reads from the Config, taken once when new Via() freezes it (Config::freeze()).
 * Apps read the Config's own getters instead.
 *
 * @internal
 */
final readonly class Settings {
    /**
     * @param array<string, mixed>                          $swooleSettings
     * @param null|list<string>                             $frameAncestors
     * @param null|list<string>                             $trustedOrigins
     * @param null|\Closure(\Throwable): void               $brokerErrorHandler
     * @param null|string                                   $importMapJson            the import map as JSON, null when php-via writes none
     * @param null|\Closure(string, string): ?string|string $staticCacheControlPolicy
     */
    public function __construct(
        public string $host,
        public int $port,
        public bool $devMode,
        public string $logLevel,
        public ?TemplateEngine $templateEngine,
        public ?string $shellTemplate,
        public string $basePath,
        public ?string $staticDir,
        public bool $datastarRocketEnabled,
        public string $datastarUrl,
        public ?string $importMapJson,
        public int $ssePollIntervalMs,
        public int $sseKeepAliveMs,
        public int $sseMaxQueuedBytes,
        public bool $broadcastCoalescingEnabled,
        public int $broadcastTickMs,
        public array $swooleSettings,
        public bool $secureCookie,
        public string $sessionCookieSameSite,
        public bool $sessionCookiePartitioned,
        public ?array $frameAncestors,
        public ?array $trustedOrigins,
        public bool $allowMissingOrigin,
        public bool $strictTabSignals,
        public int $actionRateLimit,
        public int $actionRateWindow,
        public int $gcIntervalMs,
        public int $contextCleanupDelayMs,
        public int $contextConnectTimeoutMs,
        public int $contextReconnectTimeoutMs,
        public int $contextRevivalWindowMs,
        public ?string $sslCertFile,
        public ?string $sslKeyFile,
        public bool $https,
        public bool $brotli,
        public int $brotliDynamicLevel,
        public int $brotliStaticLevel,
        public bool $h2c,
        public ?\Closure $brokerErrorHandler,
        public int $workerNum,
        public int $sessionTableRows,
        public int $sessionTableValueBytes,
        public int $contextDirectoryRows,
        public int $contextDirectoryRecordBytes,
        public int $contextDirectoryTtlSeconds,
        public int $contextDirectoryTabStateBytes,
        public int $scopedSignalTableRows,
        public int $scopedSignalTableValueBytes,
        public ?string $globalStatePath,
        public int $globalStateFlushMs,
        public int $globalStateTableRows,
        public int $globalStateTableValueBytes,
        public bool $tracingEnabled,
        public int $traceBufferSize,
        private ?bool $devBarWrites,
        private ?MessageBroker $configuredBroker,
        private \Closure|string|null $staticCacheControlPolicy,
    ) {}

    /**
     * The import map as a <script type="importmap"> tag, '' when php-via writes no map. via_head writes it.
     */
    public function importMapTag(?string $nonce = null): string {
        return $this->importMapJson === null ? '' : '<script type="importmap"' . Bootstrap::nonceAttribute($nonce) . '>' . $this->importMapJson . '</script>';
    }

    /**
     * Cache-Control value for a file-backed static response, see Config::withStaticCacheControl().
     *
     * @param string $filePath  absolute path of the file being served
     * @param string $mimeType  resolved MIME type without a charset suffix, e.g. 'text/css'
     * @param bool   $versioned the URL carries the file's current content version, such as the Datastar URL
     */
    public function staticCacheControl(string $filePath, string $mimeType, bool $versioned = false): string {
        return self::cacheControl($this->staticCacheControlPolicy, $this->devMode, $filePath, $mimeType, $versioned);
    }

    /**
     * staticCacheControl() for a policy from Config::withStaticCacheControl().
     *
     * @param null|\Closure(string, string): ?string|string $policy
     */
    public static function cacheControl(\Closure|string|null $policy, bool $devMode, string $filePath, string $mimeType, bool $versioned = false): string {
        if ($policy instanceof \Closure) {
            $value = $policy($filePath, $mimeType);
            if ($value !== null) {
                return $value;
            }
        } elseif ($policy !== null) {
            return $policy;
        }

        if ($devMode) {
            return 'no-cache';
        }

        return $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=3600, must-revalidate';
    }

    /**
     * The broker from withBroker(); without one a new SwooleBroker for more than one worker, else a no-op InMemoryBroker.
     */
    public function broker(): MessageBroker {
        return $this->configuredBroker ?? self::defaultBroker($this->workerNum);
    }

    /**
     * The broker for an app without withBroker(): SwooleBroker for more than one worker, else a no-op InMemoryBroker.
     */
    public static function defaultBroker(int $workerNum): MessageBroker {
        return $workerNum > 1 ? new SwooleBroker() : new InMemoryBroker();
    }

    /**
     * Whether the Dev Bar may write signals: dev mode, the Dev Bar on, and withDevBarOptions(writes: true) or
     * VIA_DEVBAR_WRITES=1.
     */
    public function tracingWritesEnabled(): bool {
        return self::devBarWritesEnabled($this->devMode, $this->tracingEnabled, $this->devBarWrites);
    }

    /**
     * tracingWritesEnabled() for the given dev mode, Dev Bar state and withDevBarOptions(writes:).
     */
    public static function devBarWritesEnabled(bool $devMode, bool $devBar, ?bool $writes): bool {
        if (!$devMode || !$devBar) {
            return false;
        }

        if ($writes !== null) {
            return $writes;
        }

        $env = getenv('VIA_DEVBAR_WRITES');

        return $env === '1' || $env === 'true';
    }
}
