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

    /** @var array<string, mixed> */
    private array $swooleSettings = [];

    /**
     * Set basePath from reverse proxy header.
     * Called on first request with X-Base-Path header from Caddy.
     */
    public function detectBasePathFromRequest(?string $basePathHeader): void {
        if ($this->basePathDetected) {
            return;
        }

        $this->basePath = $basePathHeader !== null && $basePathHeader !== ''
            ? rtrim($basePathHeader, '/') . '/'
            : '/';

        $this->basePathDetected = true;
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

    public function withShellTemplate(string $path): self {
        $this->shellTemplate = $path;

        return $this;
    }

    public function withBasePath(string $basePath): self {
        $this->basePath = rtrim($basePath, '/') . '/';

        return $this;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function withSwooleSettings(array $settings): self {
        $this->swooleSettings = array_merge($this->swooleSettings, $settings);

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
        return $this->swooleSettings;
    }
}
