<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\Middleware\BrotliStream;

describe('BrotliStream', function (): void {
    test('chunks written before finish decompress to the input', function (): void {
        $stream = new BrotliStream(4);
        $out = $stream->write('event: a') . $stream->write("\n\ndata: b\n\n") . $stream->finish();

        expect(brotli_uncompress($out))->toBe("event: a\n\ndata: b\n\n");
    });

    test('finishing a stream that never wrote returns a valid empty stream', function (): void {
        $out = (new BrotliStream(4))->finish();

        expect($out)->toBeString()
            ->and(brotli_uncompress((string) $out))->toBe('')
        ;
    });

    test('finish releases the encoder and later calls return false', function (): void {
        $stream = new BrotliStream(4);
        $stream->write(str_repeat('<div>page</div>', 500));
        $encoder = new ReflectionProperty($stream, 'encoder');
        expect($encoder->getValue($stream))->not->toBeNull();

        $stream->finish();

        expect($encoder->getValue($stream))->toBeFalse()
            ->and($stream->write('late'))->toBeFalse()
            ->and($stream->finish())->toBeFalse()
        ;
    });

    test('no encoder exists before the first write', function (): void {
        $stream = new BrotliStream(4);

        expect((new ReflectionProperty($stream, 'encoder'))->getValue($stream))->toBeNull();
    });
});
