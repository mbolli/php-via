<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

use Mbolli\PhpVia\Broker\InMemoryBroker;
use Mbolli\PhpVia\Broker\MessageBroker;

/**
 * Configuration class with fluent API.
 */
class Config {
    private string $host = '0.0.0.0';
    private int $port = 3000;
    private bool $devMode = false;
    private string $logLevel = 'info';
    private ?string $templateDir = null;
    private ?string $shellTemplate = null;
    private string $basePath = '/';
    private ?string $staticDir = null;

    /** @var array<string, mixed> */
    private array $openSwooleSettings = [];

    /** Poll interval for the SSE loop in milliseconds (default 100 ms). */
    private int $ssePollIntervalMs = 100;

    /**
     * Whether to set the Secure flag on the session cookie (required for HTTPS).
     * Defaults to false so local HTTP dev works out of the box.
     * Set to true in production behind HTTPS.
     */
    private bool $secureCookie = false;

    /**
     * Allowed origins for action requests.
     * Null means no Origin restriction (dev default).
     * Set to a list of allowed origins in production, e.g. ['https://example.com'].
     *
     * @var null|list<string>
     */
    private ?array $trustedOrigins = null;

    /** Path to SSL certificate file (PEM). Required for HTTPS/HTTP2. */
    private ?string $sslCertFile = null;

    /** Path to SSL private key file (PEM). Required for HTTPS/HTTP2. */
    private ?string $sslKeyFile = null;

    /**
     * Whether to run HTTP/2 cleartext (h2c) without TLS.
     * Use this when a reverse proxy (Caddy, Nginx) terminates TLS and proxies
     * to this server via h2c. Allows withBrotli() without withCertificate().
     * Only enable when the server is truly behind a trusted TLS-terminating proxy.
     */
    private bool $h2c = false;

    /**
     * Whether to enable Brotli compression for HTTP responses.
     * Requires either withCertificate() (direct HTTPS) or withH2c() (proxy h2c),
     * and the ext-brotli PHP extension. Hard error at start() if either is missing.
     */
    private bool $brotli = false;

    /** Brotli level for dynamic responses (pages, SSE). 0–11; default 4. */
    private int $brotliDynamicLevel = 4;

    /** Brotli level for static assets. 0–11; default 11 (BROTLI_COMPRESS_LEVEL_MAX). */
    private int $brotliStaticLevel = 11;

    /**
     * Number of OpenSwoole worker processes.
     * Worker values > 1 require a multi-worker-capable broker (SwooleBroker, RedisBroker, NatsBroker).
     */
    private int $workerNum = 1;

    /**
     * Maximum number of rows in the GlobalState OpenSwoole\Table.
     * Each row holds one global-state key. Increase if you need more than 1024 distinct keys.
     */
    private int $globalStateTableRows = 1024;

    /**
     * Maximum serialized byte size of a single global-state value.
     * Values exceeding this limit will throw at setGlobalState() time.
     */
    private int $globalStateTableValueBytes = 4096;

    private ?MessageBroker $broker = null;

    /** @var null|callable(\Throwable): void */
    private $brokerErrorHandler;

    /**
     * Maximum action requests per IP per window (0 = unlimited).
     */
    private int $actionRateLimit = 0;

    /**
     * Rate-limit window in seconds.
     */
    private int $actionRateWindow = 60;

    /**
     * Interval in milliseconds between proactive gc_collect_cycles() calls.
     * 0 disables the timer and leaves GC entirely to PHP's automatic trigger.
     */
    private int $gcIntervalMs = 30_000;

    public function withHost(string $host): self {
        $this->host = $host;

        return $this;
    }

    public function withPort(int $port): self {
        $this->port = $port;

        return $this;
    }

    public function withDevMode(bool $devMode = true): self {
        $this->devMode = $devMode;

        return $this;
    }

    public function withLogLevel(string $level): self {
        $this->logLevel = $level;

        return $this;
    }

    public function withTemplateDir(string $dir): self {
        $this->templateDir = $dir;

        return $this;
    }

    public function withStaticDir(string $dir): self {
        $this->staticDir = rtrim($dir, '/');

        return $this;
    }

    public function getStaticDir(): ?string {
        return $this->staticDir;
    }

    public function withShellTemplate(string $path): self {
        $this->shellTemplate = $path;

        return $this;
    }

    /**
     * Set the URL base path prefix (e.g. '/app/' when mounted at a sub-path).
     * Must be a relative path: '/', '/app', '/sub/path', etc.
     *
     * @throws \InvalidArgumentException if the value is not a valid relative path
     */
    public function withBasePath(string $basePath): self {
        // Accept only safe relative paths: zero or more /segment components
        // (each starting with [a-zA-Z0-9]) followed by an optional trailing slash.
        // Rejects protocol-relative paths (//evil.com), absolute URLs (https://…),
        // backslashes, and any other unexpected characters.
        if (!preg_match('#^(?:/[a-zA-Z0-9][a-zA-Z0-9_.-]*)*/?$#', $basePath)) {
            throw new \InvalidArgumentException(
                "Invalid basePath '{$basePath}': must be a relative path like '/', '/app', or '/sub/path'."
            );
        }

        $this->basePath = rtrim($basePath, '/') . '/';

        return $this;
    }

    /**
     * How long the SSE loop blocks waiting for a patch before looping again.
     * Lower values increase responsiveness; higher values reduce CPU overhead.
     * Default: 100 ms.
     */
    public function withSsePollIntervalMs(int $ms): self {
        $this->ssePollIntervalMs = max(1, $ms);

        return $this;
    }

    public function getSsePollIntervalMs(): int {
        return $this->ssePollIntervalMs;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function withSwooleSettings(array $settings): self {
        $this->openSwooleSettings = array_merge($this->openSwooleSettings, $settings);

        return $this;
    }

    public function getHost(): string {
        return $this->host;
    }

    public function getPort(): int {
        return $this->port;
    }

    public function getDevMode(): bool {
        return $this->devMode;
    }

    public function getLogLevel(): string {
        return $this->logLevel;
    }

    public function getTemplateDir(): ?string {
        return $this->templateDir;
    }

    public function getShellTemplate(): ?string {
        return $this->shellTemplate;
    }

    public function getBasePath(): string {
        return $this->basePath;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSwooleSettings(): array {
        return $this->openSwooleSettings;
    }

    /**
     * Require the Secure cookie attribute.
     * Enable this for any deployment served over HTTPS.
     */
    public function withSecureCookie(bool $secure = true): self {
        $this->secureCookie = $secure;

        return $this;
    }

    public function getSecureCookie(): bool {
        return $this->secureCookie;
    }

    /**
     * Restrict action POST requests to the given list of Origin header values.
     *
     * Each entry should be a full origin without trailing slash, e.g. 'https://example.com'.
     * Pass null (the default) to disable Origin checking — suitable for local dev.
     *
     * @param null|list<string> $origins
     */
    public function withTrustedOrigins(?array $origins): self {
        $this->trustedOrigins = $origins;

        return $this;
    }

    /**
     * @return null|list<string>
     */
    public function getTrustedOrigins(): ?array {
        return $this->trustedOrigins;
    }

    /**
     * Rate-limit action requests per IP.
     *
     * @param int $maxRequests   Maximum requests per window (0 = no limit)
     * @param int $windowSeconds Window size in seconds (default 60)
     */
    public function withActionRateLimit(int $maxRequests, int $windowSeconds = 60): self {
        $this->actionRateLimit = max(0, $maxRequests);
        $this->actionRateWindow = max(1, $windowSeconds);

        return $this;
    }

    public function getActionRateLimit(): int {
        return $this->actionRateLimit;
    }

    public function getActionRateWindow(): int {
        return $this->actionRateWindow;
    }

    /**
     * Configure the proactive GC timer interval.
     *
     * php-via runs as a persistent process; PHP's cycle collector only fires when
     * its internal root buffer fills (~10,000 new roots), which can cause sudden
     * micro-pauses under load. Calling gc_collect_cycles() on a fixed timer spreads
     * that work out predictably during idle gaps between requests.
     *
     * @param int $ms Timer interval in milliseconds. Pass 0 to disable.
     */
    public function withGcInterval(int $ms): self {
        $this->gcIntervalMs = max(0, $ms);

        return $this;
    }

    public function getGcIntervalMs(): int {
        return $this->gcIntervalMs;
    }

    /**
     * Enable HTTPS by providing paths to the SSL certificate and private key files.
     * Also enables HTTP/2 automatically (open_http2_protocol).
     *
     * @param string $certFile Path to PEM certificate file
     * @param string $keyFile  Path to PEM private key file
     */
    public function withCertificate(string $certFile, string $keyFile): self {
        $this->sslCertFile = $certFile;
        $this->sslKeyFile = $keyFile;

        return $this;
    }

    public function getSslCertFile(): ?string {
        return $this->sslCertFile;
    }

    public function getSslKeyFile(): ?string {
        return $this->sslKeyFile;
    }

    /**
     * Returns true if SSL certificate and key have been configured.
     */
    public function isHttps(): bool {
        return $this->sslCertFile !== null && $this->sslKeyFile !== null;
    }

    /**
     * Enable Brotli compression for HTTP responses (pages, static assets, SSE streams).
     * Requires withCertificate() (direct HTTPS) or withH2c() (proxy h2c), and ext-brotli.
     * A hard error is thrown at start() if either requirement is not met.
     *
     * @param bool $enabled      enable or disable Brotli compression
     * @param int  $dynamicLevel Compression level for pages and SSE (0–11). Default 4 — fast,
     *                           low CPU overhead on the hot path.
     * @param int  $staticLevel  Compression level for static assets (0–11). Default 11 — maximum
     *                           ratio; paid once per file then served from an in-memory cache.
     */
    public function withBrotli(bool $enabled = true, int $dynamicLevel = 4, int $staticLevel = 11): self {
        $this->brotli = $enabled;
        $this->brotliDynamicLevel = max(0, min(11, $dynamicLevel));
        $this->brotliStaticLevel = max(0, min(11, $staticLevel));

        return $this;
    }

    public function getBrotli(): bool {
        return $this->brotli;
    }

    public function getBrotliDynamicLevel(): int {
        return $this->brotliDynamicLevel;
    }

    public function getBrotliStaticLevel(): int {
        return $this->brotliStaticLevel;
    }

    /**
     * Enable HTTP/2 cleartext (h2c) mode for use behind a TLS-terminating reverse proxy.
     *
     * Use this when Caddy or Nginx handles TLS certs and proxies to OpenSwoole via h2c
     * (e.g. `reverse_proxy h2c://localhost:3000` in Caddy). Satisfies the withBrotli()
     * HTTPS requirement without needing withCertificate().
     *
     * Do NOT enable on a server exposed directly to untrusted traffic.
     */
    public function withH2c(bool $enabled = true): self {
        $this->h2c = $enabled;

        return $this;
    }

    public function isH2c(): bool {
        return $this->h2c;
    }

    /**
     * Set the message broker for multi-node broadcasting.
     *
     * A broker enables broadcast() to reach contexts on other nodes (workers,
     * servers, containers). The default InMemoryBroker is a no-op suitable for
     * single-node deployments.
     *
     * Example:
     * ```php
     * (new Config())->withBroker(new RedisBroker('127.0.0.1', 6379))
     * ```
     */
    public function withBroker(MessageBroker $broker): self {
        $this->broker = $broker;

        return $this;
    }

    /**
     * Register a callable to be invoked when the broker loses its connection and
     * is attempting to reconnect. Use this to log alerts or expose health metrics.
     *
     * The callable receives the \Throwable that caused the drop.
     *
     * **Security note:** Do NOT log `$e->getMessage()` verbatim in production if the
     * message may contain connection strings, auth tokens, or hostnames that should
     * not appear in log files. Log a safe summary or use a structured logger with
     * redaction.
     *
     * Example:
     * ```php
     * (new Config())
     *     ->withBroker(new RedisBroker('127.0.0.1', 6379))
     *     ->onBrokerError(fn (\Throwable $e) => $logger->error('Broker error: ' . $e->getMessage()))
     * ```
     *
     * @param callable(\Throwable): void $handler
     */
    public function onBrokerError(callable $handler): self {
        $this->brokerErrorHandler = $handler;

        return $this;
    }

    /**
     * Return the configured broker error handler, or null if none was set.
     *
     * @return null|callable(\Throwable): void
     */
    public function getBrokerErrorHandler(): ?callable {
        return $this->brokerErrorHandler;
    }

    /**
     * Return the configured broker, or a no-op InMemoryBroker if none was set.
     */
    public function getBroker(): MessageBroker {
        return $this->broker ?? new InMemoryBroker();
    }

    /**
     * Set the number of OpenSwoole worker processes.
     *
     * Using more than one worker distributes CPU-bound actions across cores.
     * Requires a multi-worker-capable broker: SwooleBroker (same machine),
     * RedisBroker or NatsBroker (multi-server). A RuntimeException is thrown at
     * start() if worker_num > 1 and InMemoryBroker is still in use.
     *
     * Session data is NOT shared across workers — use a sticky-session load
     * balancer when running multi-worker (e.g. Caddy sticky_cookie).
     *
     * Example:
     * ```php
     * (new Config())
     *     ->withWorkerNum(swoole_cpu_num())
     *     ->withBroker(new SwooleBroker())
     * ```
     */
    public function withWorkerNum(int $n): self {
        $this->workerNum = max(1, $n);

        return $this;
    }

    public function getWorkerNum(): int {
        return $this->workerNum;
    }

    /**
     * Tune the OpenSwoole\Table that backs GlobalState in multi-worker mode.
     *
     * @param int $maxRows       Maximum number of distinct global-state keys (default 1024)
     * @param int $maxValueBytes Maximum serialized byte size per value (default 4096)
     */
    public function withGlobalStateTableSize(int $maxRows, int $maxValueBytes = 4096): self {
        $this->globalStateTableRows = max(1, $maxRows);
        $this->globalStateTableValueBytes = max(64, $maxValueBytes);

        return $this;
    }

    public function getGlobalStateTableRows(): int {
        return $this->globalStateTableRows;
    }

    public function getGlobalStateTableValueBytes(): int {
        return $this->globalStateTableValueBytes;
    }
}
