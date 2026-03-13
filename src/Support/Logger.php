<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

use Mbolli\PhpVia\Context;

/**
 * Centralized logging utility.
 *
 * Handles log level filtering and output formatting.
 */
class Logger {
    private const array LEVELS = [
        'debug' => 0,
        'info' => 1,
        'warn' => 2,
        'error' => 3,
    ];

    private int $minLevel;
    private ?RequestLogger $requestLogger = null;

    public function __construct(string $logLevel = 'info') {
        $this->minLevel = self::LEVELS[$logLevel] ?? self::LEVELS['info'];
    }

    /**
     * Attach a TUI request logger to absorb debug messages into its output.
     */
    public function setRequestLogger(RequestLogger $requestLogger): void {
        $this->requestLogger = $requestLogger;
    }

    /**
     * Log a message with optional context.
     *
     * @param string       $level   Log level (debug, info, warn, error)
     * @param string       $message Log message
     * @param null|Context $context Optional context for prefixing
     */
    public function log(string $level, string $message, ?Context $context = null): void {
        $levelValue = self::LEVELS[$level] ?? self::LEVELS['info'];

        if ($levelValue < $this->minLevel) {
            return;
        }

        // When TUI logger is active, buffer debug messages for structured output
        if ($level === 'debug' && $this->requestLogger?->isEnabled()) {
            $this->requestLogger->bufferDebug($message);

            return;
        }

        $prefix = $context ? "[{$context->getId()}] " : '';
        echo '[' . mb_strtoupper($level) . "] {$prefix}{$message}\n";
    }

    /**
     * Log debug message.
     */
    public function debug(string $message, ?Context $context = null): void {
        $this->log('debug', $message, $context);
    }

    /**
     * Log info message.
     */
    public function info(string $message, ?Context $context = null): void {
        $this->log('info', $message, $context);
    }

    /**
     * Log warning message.
     */
    public function warn(string $message, ?Context $context = null): void {
        $this->log('warn', $message, $context);
    }

    /**
     * Log error message.
     */
    public function error(string $message, ?Context $context = null): void {
        $this->log('error', $message, $context);
    }

    /**
     * Log a fatal crash with timestamp and memory snapshot.
     * Always printed regardless of configured log level.
     */
    public function fatal(string $message): void {
        $ts = date('Y-m-d H:i:s');
        $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
        $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
        echo "[FATAL] [{$ts}] [mem {$mem}MB peak {$peak}MB] {$message}\n";
    }
}
