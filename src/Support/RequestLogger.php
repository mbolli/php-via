<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

/**
 * TUI-style colorful request logger.
 *
 * Produces structured, color-coded terminal output for every HTTP request,
 * similar to stario's developer experience.
 */
class RequestLogger {
    // ANSI color codes
    private const string RESET = "\033[0m";
    private const string BOLD = "\033[1m";
    private const string DIM = "\033[2m";

    // Foreground colors
    private const string WHITE = "\033[37m";
    private const string GRAY = "\033[90m";
    private const string GREEN = "\033[32m";
    private const string YELLOW = "\033[33m";
    private const string RED = "\033[31m";
    private const string CYAN = "\033[36m";
    private const string MAGENTA = "\033[35m";
    private const string BLUE = "\033[34m";

    // Bright variants
    private const string BRIGHT_GREEN = "\033[92m";
    private const string BRIGHT_YELLOW = "\033[93m";
    private const string BRIGHT_RED = "\033[91m";
    private const string BRIGHT_CYAN = "\033[96m";

    private bool $enabled;

    /** @var string[] Buffered debug messages to include in the next TUI box */
    private array $debugBuffer = [];

    public function __construct(bool $enabled = true) {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    /**
     * Buffer a debug message to be shown in the next TUI box.
     */
    public function bufferDebug(string $message): void {
        $this->debugBuffer[] = $message;
    }

    /**
     * Drain buffered debug lines as formatted TUI rows.
     */
    private function drainDebugLines(string $borderColor): string {
        if ($this->debugBuffer === []) {
            return '';
        }

        $lines = '';
        foreach ($this->debugBuffer as $msg) {
            $lines .= $borderColor . '│' . self::RESET . '  .debug:         ' . self::DIM . self::GRAY . $msg . self::RESET . "\n";
        }
        $this->debugBuffer = [];

        return $lines;
    }

    /**
     * Log a completed HTTP request with structured TUI output.
     */
    public function logRequest(
        string $method,
        string $path,
        int $statusCode,
        float $durationUs,
        ?string $requestId = null,
    ): void {
        if (!$this->enabled) {
            return;
        }

        $requestId ??= substr(bin2hex(random_bytes(4)), 0, 8);
        $timestamp = date('H:i:s.') . substr((string) microtime(true), -3, 3);
        $duration = $this->formatDuration($durationUs);
        $borderColor = $this->statusBorderColor($statusCode);
        $methodColor = $this->methodColor($method);
        $statusColor = $this->statusColor($statusCode);

        // Top line: timestamp, request ID, method, duration
        $header = $borderColor . '── ' . self::GRAY . $timestamp . self::RESET
            . ' ' . self::DIM . self::WHITE . $requestId . self::RESET
            . ' ' . $methodColor . self::BOLD . $method . self::RESET
            . ' ' . self::DIM . self::GRAY . '(' . $duration . ')' . self::RESET . ' ─';

        // Request details
        $pathLine = $borderColor . '│' . self::RESET . '  .path:          ' . self::WHITE . $path . self::RESET;

        // Response
        $statusLine = $borderColor . '│' . self::RESET . '  .status_code:   ' . $statusColor . self::BOLD . $statusCode . self::RESET;

        // Bottom border
        $footer = $borderColor . '╰─' . self::RESET;

        $debugLines = $this->drainDebugLines($borderColor);

        echo "\n{$header}\n{$pathLine}\n{$statusLine}\n{$debugLines}{$footer}\n";
    }

    /**
     * Log an SSE connection event.
     */
    public function logSseConnect(string $contextId): void {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('H:i:s.') . substr((string) microtime(true), -3, 3);
        $shortCtx = substr($contextId, -8);

        $header = self::BLUE . '── ' . self::GRAY . $timestamp . self::RESET
            . ' ' . self::DIM . self::WHITE . $shortCtx . self::RESET
            . ' ' . self::BLUE . self::BOLD . 'SSE' . self::RESET
            . ' ' . self::DIM . self::CYAN . '↑ connected' . self::RESET;
        $footer = self::BLUE . '╰─' . self::RESET;
        $debugLines = $this->drainDebugLines(self::BLUE);

        echo "\n{$header}\n{$debugLines}{$footer}\n";
    }

    /**
     * Log an SSE disconnect event.
     */
    public function logSseDisconnect(string $contextId): void {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('H:i:s.') . substr((string) microtime(true), -3, 3);
        $shortCtx = substr($contextId, -8);

        $header = self::GRAY . '── ' . self::GRAY . $timestamp . self::RESET
            . ' ' . self::DIM . self::WHITE . $shortCtx . self::RESET
            . ' ' . self::GRAY . self::BOLD . 'SSE' . self::RESET
            . ' ' . self::DIM . self::GRAY . '↓ disconnected' . self::RESET;
        $footer = self::GRAY . '╰─' . self::RESET;
        $debugLines = $this->drainDebugLines(self::GRAY);

        echo "\n{$header}\n{$debugLines}{$footer}\n";
    }

    /**
     * Log an action execution.
     */
    public function logAction(string $actionId, string $contextId, float $durationUs, bool $success): void {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('H:i:s.') . substr((string) microtime(true), -3, 3);
        $shortCtx = substr($contextId, -8);
        $duration = $this->formatDuration($durationUs);
        $color = $success ? self::MAGENTA : self::RED;
        $resultColor = $success ? self::GREEN : self::RED;
        $resultText = $success ? '✓ ok' : '✗ failed';

        $header = "{$color}── {$color}" . self::GRAY . $timestamp . self::RESET
            . ' ' . self::DIM . self::WHITE . $shortCtx . self::RESET
            . ' ' . $color . self::BOLD . 'ACTION' . self::RESET
            . ' ' . self::DIM . self::GRAY . '(' . $duration . ')' . self::RESET;
        $actionLine = "{$color}│" . self::RESET . '  .action:        ' . self::WHITE . $actionId . self::RESET;
        $resultLine = "{$color}│" . self::RESET . '  .result:        ' . $resultColor . $resultText . self::RESET;
        $footer = "{$color}╰─" . self::RESET;
        $debugLines = $this->drainDebugLines($color);

        echo "\n{$header}\n{$actionLine}\n{$resultLine}\n{$debugLines}{$footer}\n";
    }

    /**
     * Log a broadcast event.
     */
    public function logBroadcast(string $scope, int $contextCount): void {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('H:i:s.') . substr((string) microtime(true), -3, 3);

        $header = self::CYAN . '── ' . self::GRAY . $timestamp . self::RESET
            . ' ' . self::CYAN . self::BOLD . 'BROADCAST' . self::RESET
            . ' → ' . self::WHITE . $scope . self::RESET
            . ' ' . self::DIM . self::GRAY . '(' . $contextCount . ' contexts)' . self::RESET;
        $footer = self::CYAN . '╰─' . self::RESET;
        $debugLines = $this->drainDebugLines(self::CYAN);

        echo "\n{$header}\n{$debugLines}{$footer}\n";
    }

    private function formatDuration(float $microseconds): string {
        if ($microseconds < 1000) {
            return round($microseconds) . ' μs';
        }

        if ($microseconds < 1_000_000) {
            return round($microseconds / 1000, 1) . ' ms';
        }

        return round($microseconds / 1_000_000, 2) . ' s';
    }

    private function statusBorderColor(int $statusCode): string {
        return match (true) {
            $statusCode >= 500 => self::RED,
            $statusCode >= 400 => self::YELLOW,
            $statusCode >= 300 => self::BRIGHT_YELLOW,
            $statusCode >= 200 => self::GREEN,
            default => self::WHITE,
        };
    }

    private function methodColor(string $method): string {
        return match ($method) {
            'GET' => self::GREEN,
            'POST' => self::BRIGHT_CYAN,
            'PUT', 'PATCH' => self::YELLOW,
            'DELETE' => self::RED,
            default => self::WHITE,
        };
    }

    private function statusColor(int $statusCode): string {
        return match (true) {
            $statusCode >= 500 => self::BRIGHT_RED,
            $statusCode >= 400 => self::BRIGHT_YELLOW,
            $statusCode >= 300 => self::YELLOW,
            $statusCode >= 200 => self::BRIGHT_GREEN,
            default => self::WHITE,
        };
    }
}
