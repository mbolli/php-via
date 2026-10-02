<?php

declare(strict_types=1);

namespace Mbolli\PhpVia\Http\Middleware;

/**
 * One response's incremental Brotli encoder.
 *
 * The encoder is created on the first write, so a stream that never sends anything allocates
 * none, and finish() releases it, so a reference that outlives the response keeps no buffers.
 *
 * @internal created per request by BrotliMiddleware
 */
final class BrotliStream {
    /**
     * Null before the first write, false once finished or when creation failed. Untyped because
     * ext-brotli returns an object while its stubs declare a resource.
     *
     * @var null|false|resource
     */
    private mixed $encoder = null;

    public function __construct(private readonly int $level) {}

    /** Compress and flush a chunk, false after finish(). */
    public function write(string $chunk): false|string {
        if ($this->encoder === false) {
            return false;
        }
        $this->encoder ??= brotli_compress_init($this->level);

        return $this->encoder === false ? false : brotli_compress_add($this->encoder, $chunk, BROTLI_FLUSH);
    }

    /** End the stream and release the encoder. False when already finished. */
    public function finish(): false|string {
        $encoder = $this->encoder;
        $this->encoder = false;

        return match (true) {
            $encoder === false => false,
            $encoder === null => brotli_compress('', $this->level),
            default => brotli_compress_add($encoder, '', BROTLI_FINISH),
        };
    }
}
