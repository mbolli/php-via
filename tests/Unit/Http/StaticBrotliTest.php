<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Http\StaticBrotli;

/*
 * The worker side of static Brotli talks to the helper through one packed job and one packed reply per file. Here a
 * second StaticBrotli plays the helper, so the protocol runs without processes; StaticBrotliServerTest runs it for
 * real.
 */

beforeEach(function (): void {
    if (!function_exists('brotli_compress')) {
        $this->markTestSkipped('ext-brotli required');
    }
    $this->dir = sys_get_temp_dir() . '/via-static-brotli-' . bin2hex(random_bytes(6));
    mkdir($this->dir);
});

afterEach(function (): void {
    exec('rm -rf ' . escapeshellarg($this->dir));
});

function staticBrotli(?Config $config = null): StaticBrotli {
    return new StaticBrotli($config ?? new Config(), static function (string $level, string $message): void {});
}

/** Text that compresses differently at level 4 and 11. */
function compressibleText(int $bytes, int $seed = 1): string {
    $out = '';
    for ($i = 0; strlen($out) < $bytes; ++$i) {
        $hash = crc32("{$seed}:{$i}");
        $out .= sprintf(".rule-%d { color: #%06x; margin: %dpx; }\n", $i, $hash & 0xFFFFFF, ($hash >> 24) % 65);
    }

    return substr($out, 0, $bytes);
}

/**
 * A worker with a fake helper that records the jobs it is sent.
 *
 * @return array{0: StaticBrotli, 1: ArrayObject<int, array{worker: int, path: string, mtime: int, size: int}>}
 */
function workerWithHelper(?Config $config = null): array {
    $jobs = new ArrayObject();
    $worker = staticBrotli($config);
    $worker->setWorkerId(3);
    $worker->attachHelper(static function (string $job) use ($jobs): void {
        $head = unpack('Nworker/Jmtime/Jsize', $job);
        $jobs[] = ['worker' => $head['worker'], 'path' => substr($job, 20), 'mtime' => $head['mtime'], 'size' => $head['size']];
    });

    return [$worker, $jobs];
}

/** @return array{0: string, 1: int, 2: int} path, mtime, size */
function writeStatic(string $path, string $contents, ?int $mtime = null): array {
    file_put_contents($path, $contents);
    if ($mtime !== null) {
        touch($path, $mtime);
    }
    clearstatcache(true, $path);

    return [$path, (int) filemtime($path), strlen($contents)];
}

describe('a worker with a helper', function (): void {
    test('sends a small file at level 4 at once, asks the helper once, then sends level 11', function (): void {
        [$worker, $jobs] = workerWithHelper();
        $css = compressibleText(30_000);
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.css', $css);

        $first = $worker->lookup($path, $mtime, $size);
        $second = $worker->lookup($path, $mtime, $size);

        expect($first)->toBe(['body' => brotli_compress($css, 4, BROTLI_TEXT)])
            ->and($second)->toBe($first)
            ->and($jobs->getArrayCopy())->toBe([['worker' => 3, 'path' => $path, 'mtime' => $mtime, 'size' => $size]])
        ;

        $worker->receive(staticBrotli()->compressForWorker($path, $mtime, $size));

        expect($worker->lookup($path, $mtime, $size))->toBe(['body' => brotli_compress($css, 11, BROTLI_TEXT)])
            ->and($jobs)->toHaveCount(1)
        ;
    });

    test('sends a big file uncompressed until the helper answers', function (): void {
        [$worker, $jobs] = workerWithHelper();
        $js = compressibleText(StaticBrotli::INTERIM_BYTES + 1);
        [$path, $mtime, $size] = writeStatic($this->dir . '/big.js', $js);

        expect($worker->lookup($path, $mtime, $size))->toBeNull()
            ->and($jobs)->toHaveCount(1)
        ;

        $worker->receive(staticBrotli()->compressForWorker($path, $mtime, $size));

        expect(brotli_uncompress($worker->lookup($path, $mtime, $size)['body'] ?? ''))->toBe($js);
    });

    test('has one job out at a time and sends the next once the helper answers', function (): void {
        [$worker, $jobs] = workerWithHelper();
        [$a, $aMtime, $aSize] = writeStatic($this->dir . '/a.js', compressibleText(1000, 1));
        [$b, $bMtime, $bSize] = writeStatic($this->dir . '/b.js', compressibleText(1000, 2));

        $worker->lookup($a, $aMtime, $aSize);
        $worker->lookup($b, $bMtime, $bSize);
        expect(array_column($jobs->getArrayCopy(), 'path'))->toBe([$a]);

        $worker->receive(staticBrotli()->compressForWorker($a, $aMtime, $aSize));
        expect(array_column($jobs->getArrayCopy(), 'path'))->toBe([$a, $b]);
    });

    test('ignores a reply for a job it did not send, as after a reload', function (): void {
        [$worker] = workerWithHelper();
        $css = compressibleText(1000);
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.css', $css);

        $worker->receive(staticBrotli()->compressForWorker($path, $mtime, $size));

        expect($worker->workerCache()->entries())->toBe([]);
    });

    test('asks again for a file that changed while the helper had it', function (): void {
        [$worker, $jobs] = workerWithHelper();
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.css', compressibleText(1000, 1), time() - 10);
        $worker->lookup($path, $mtime, $size);

        $edited = compressibleText(1200, 2);
        [, $newMtime, $newSize] = writeStatic($path, $edited, time());
        $worker->receive(staticBrotli()->compressForWorker($path, $mtime, $size));
        $worker->lookup($path, $newMtime, $newSize);

        expect($jobs)->toHaveCount(2)
            ->and($jobs[1]['mtime'])->toBe($newMtime)
        ;
        $worker->receive(staticBrotli()->compressForWorker($path, $newMtime, $newSize));
        expect($worker->lookup($path, $newMtime, $newSize))->toBe(['body' => brotli_compress($edited, 11, BROTLI_TEXT)]);
    });

    test('never asks the helper for a file over the source limit', function (): void {
        [$worker, $jobs] = workerWithHelper();
        $path = $this->dir . '/huge.js';
        touch($path);

        expect($worker->lookup($path, (int) filemtime($path), StaticBrotli::SOURCE_BYTES + 1))->toBeNull()
            ->and($jobs)->toHaveCount(0)
        ;
    });
});

describe('outside a server', function (): void {
    test('a file is compressed at the static level when first asked for', function (): void {
        $css = compressibleText(5000);
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.css', $css);

        expect(staticBrotli((new Config())->withBrotli(false, staticLevel: 9))->lookup($path, $mtime, $size))
            ->toBe(['body' => brotli_compress($css, 9, BROTLI_TEXT)])
        ;
    });

    test('a static level of 0 sends nothing compressed, sidecars included', function (): void {
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.css', compressibleText(5000));
        file_put_contents($path . '.br', 'precompressed');
        $brotli = staticBrotli((new Config())->withBrotli(staticLevel: 0));

        expect($brotli->enabled())->toBeFalse()
            ->and($brotli->lookup($path, $mtime, $size))->toBeNull()
        ;
    });
});

describe('sidecars', function (): void {
    test('a sidecar at least as new as its file is sent as it is, without compressing anything', function (): void {
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.css', compressibleText(5000), time() - 10);
        writeStatic($path . '.br', 'BROTLI BYTES', time() - 10);
        [$worker, $jobs] = workerWithHelper();

        expect($worker->lookup($path, $mtime, $size))->toBe(['body' => 'BROTLI BYTES'])
            ->and($jobs)->toHaveCount(0)
        ;
    });

    test('an older sidecar is ignored', function (): void {
        $css = compressibleText(5000);
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.css', $css, time());
        writeStatic($path . '.br', 'OLD BROTLI BYTES', time() - 60);

        expect(staticBrotli()->lookup($path, $mtime, $size))->toBe(['body' => brotli_compress($css, 11, BROTLI_TEXT)]);
    });

    test('a sidecar over 2 MiB is left on disk for sendfile()', function (): void {
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.js', compressibleText(5000), time() - 10);
        $fp = fopen($path . '.br', 'w');
        ftruncate($fp, StaticBrotli::BODY_BYTES + 1);
        fclose($fp);

        expect(staticBrotli()->lookup($path, $mtime, $size))->toBe(['file' => $path . '.br']);
    });

    test('a sidecar that links out of the directory is ignored', function (): void {
        $css = compressibleText(5000);
        mkdir($this->dir . '/public');
        [$path, $mtime, $size] = writeStatic($this->dir . '/public/app.css', $css, time() - 10);
        writeStatic($this->dir . '/secret', 'SECRET', time());
        symlink($this->dir . '/secret', $path . '.br');

        expect(staticBrotli()->lookup($path, $mtime, $size))->toBe(['body' => brotli_compress($css, 11, BROTLI_TEXT)]);
    });
});

describe('compressing at start', function (): void {
    test('compresses the assets and the compressible files of the static dir, skipping dot entries, images and sidecared files', function (): void {
        foreach (['framework', 'public', 'public/css', 'public/.git'] as $sub) {
            mkdir("{$this->dir}/{$sub}");
        }
        $asset = writeStatic($this->dir . '/framework/asset.js', compressibleText(2000, 1))[0];
        $css = writeStatic($this->dir . '/public/css/site.css', compressibleText(3000, 2))[0];
        writeStatic($this->dir . '/public/.git/config.json', compressibleText(100, 3));
        writeStatic($this->dir . '/public/.env.json', '{"secret":1}');
        writeStatic($this->dir . '/public/logo.png', "\x89PNG");
        writeStatic($this->dir . '/public/shipped.js', compressibleText(4000, 4), time() - 10);
        writeStatic($this->dir . '/public/shipped.js.br', 'BR', time());
        $brotli = staticBrotli();

        $result = $brotli->precompress([$asset], $this->dir . '/public/css/..');

        expect(array_keys($brotli->bootCache()->entries()))->toEqualCanonicalizing([$asset, $css])
            ->and($result['stopped'])->toBeFalse()
            ->and($result['files'])->toBe(2)
        ;
    });

    test('stops at the time budget and leaves the rest to the helper', function (): void {
        $path = writeStatic($this->dir . '/app.js', compressibleText(2000))[0];
        $brotli = staticBrotli();

        $result = $brotli->precompress([], $this->dir, budgetMs: 0);

        expect($result['stopped'])->toBeTrue()
            ->and($result['files'])->toBe(0)
            ->and($brotli->bootCache()->entries())->not->toHaveKey($path)
        ;
    });

    test('skips a file whose estimated time does not fit the budget, and takes smaller ones after it', function (): void {
        $big = writeStatic($this->dir . '/a-big.js', compressibleText(StaticBrotli::INTERIM_BYTES * 4))[0];
        $small = writeStatic($this->dir . '/b-small.js', compressibleText(2000))[0];
        $brotli = staticBrotli();

        // About 1.7 us per byte at level 11: the 1 MB file needs 1.7 s, the small one 3 ms.
        $result = $brotli->precompress([], $this->dir, budgetMs: 300);

        expect($result['stopped'])->toBeTrue()
            ->and($brotli->bootCache()->entries())->toHaveKey($small)
            ->and($brotli->bootCache()->entries())->not->toHaveKey($big)
        ;
    });

    test('a worker sends what start compressed, without compressing it again', function (): void {
        [$path, $mtime, $size] = writeStatic($this->dir . '/app.js', compressibleText(2000));
        [$worker, $jobs] = workerWithHelper();
        $worker->precompress([], $this->dir);
        file_put_contents($path, str_repeat('x', $size));
        touch($path, $mtime);

        expect(brotli_uncompress($worker->lookup($path, $mtime, $size)['body'] ?? ''))->toBe(compressibleText(2000))
            ->and($jobs)->toHaveCount(0)
        ;
    });
});
