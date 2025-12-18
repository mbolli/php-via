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

    public function __construct(string $logLevel = 'info') {
        $this->minLevel = self::LEVELS[$logLevel] ?? self::LEVELS['info'];
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

        if ($levelValue >= $this->minLevel) {
            $prefix = $context ? "[{$context->getId()}] " : '';
            echo '[' . mb_strtoupper($level) . "] {$prefix}{$message}\n";
        }
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
}
