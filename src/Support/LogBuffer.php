<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Support;

use Mbolli\PhpVia\Tracing\TraceStore;

/**
 * In-process ring buffer of recent log records for the Via Dev Bar.
 *
 * The framework {@see Logger} tees every record here (when tracing is enabled)
 * so the Dev Bar's Logs panel can stream server-side logs live — the same way
 * {@see TraceStore} buffers traces. Single-worker only,
 * like the trace store; production runs with tracing off.
 */
final class LogBuffer {
    /** Maximum stored length of a single message (stack traces get clipped). */
    private const int MAX_MESSAGE_LENGTH = 4000;

    /** @var array<int, array{seq: int, time: float, level: string, message: string}> seq => record */
    private array $buffer = [];

    private int $cursor = 0;

    public function __construct(private int $capacity = 300) {
        $this->capacity = max(1, $capacity);
    }

    public function push(string $level, string $message): void {
        if (\strlen($message) > self::MAX_MESSAGE_LENGTH) {
            $message = substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…';
        }

        $seq = ++$this->cursor;
        $this->buffer[$seq] = [
            'seq' => $seq,
            'time' => round(microtime(true) * 1000),
            'level' => $level,
            'message' => $message,
        ];

        if (\count($this->buffer) > $this->capacity) {
            array_shift($this->buffer);
        }
    }

    /**
     * Records with a sequence number greater than $cursor, oldest first.
     *
     * @return list<array{seq: int, time: float, level: string, message: string}>
     */
    public function since(int $cursor): array {
        $out = [];
        foreach ($this->buffer as $seq => $record) {
            if ($seq > $cursor) {
                $out[] = $record;
            }
        }

        return $out;
    }

    /**
     * The most recent records, newest first, capped at $limit.
     *
     * @return list<array{seq: int, time: float, level: string, message: string}>
     */
    public function recent(int $limit = 200): array {
        $all = array_reverse(array_values($this->buffer));

        return \array_slice($all, 0, max(0, $limit));
    }

    public function cursor(): int {
        return $this->cursor;
    }
}
