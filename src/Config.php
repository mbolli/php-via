<?php

declare(strict_types=1);

namespace Mbolli\PhpVia;

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
    private bool $basePathDetected = false;
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

    /**
     * Whether to trust reverse-proxy headers (X-Base-Path, X-Forwarded-*).
     * Disabled by default — only enable behind a trusted proxy (Caddy, Nginx, etc.).
     */
    private bool $trustProxy = false;

    /**
     * Maximum action requests per IP per window (0 = unlimited).
     */
    private int $actionRateLimit = 0;

    /**
     * Rate-limit window in seconds.
     */
    private int $actionRateWindow = 60;

    /**
     * Set basePath from reverse proxy header.
     * Called on first request with X-Base-Path header from Caddy.
     */
    public function detectBasePathFromRequest(?string $basePathHeader): void {
        if ($this->basePathDetected) {
            return;
        }

        // Only lock once we've seen the actual header.
        // If there's no header (direct hit, health check, local dev without proxy),
        // leave basePath at its configured default and don't lock — the next
        // real proxied request will still be able to set it correctly.
        if ($basePathHeader !== null && $basePathHeader !== '') {
            $this->basePath = rtrim($basePathHeader, '/') . '/';
            $this->basePathDetected = true;
        }
    }

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

    public function withBasePath(string $basePath): self {
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
     * Trust reverse-proxy headers like X-Base-Path.
     * Only enable this when running behind a trusted reverse proxy (Caddy, Nginx, etc.).
     * Without this, clients cannot inject arbitrary base paths.
     */
    public function withTrustProxy(bool $trust = true): self {
        $this->trustProxy = $trust;

        return $this;
    }

    public function getTrustProxy(): bool {
        return $this->trustProxy;
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
}
