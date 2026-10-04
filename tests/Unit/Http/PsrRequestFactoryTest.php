<?php

declare(strict_types=1);

use Mbolli\PhpVia\Http\Adapter\PsrRequestFactory;
use Psr\Http\Message\UploadedFileInterface;
use Tests\Support\FakeRequest;

// PsrRequestFactory: the PSR-7 request that middleware and Via::route() handlers get.

describe('PsrRequestFactory', function (): void {
    test('passes uploaded files on as UploadedFile objects, nested as the field names nest', function (): void {
        $dir = sys_get_temp_dir() . '/via-upload-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("{$dir}/report", 'id,name');
        file_put_contents("{$dir}/a", 'a');
        $request = new FakeRequest('POST', '/upload');
        // OpenSwoole's shape: one entry per file, lists and maps as the field names give them.
        $request->files = [
            'report' => ['name' => 'up.csv', 'type' => 'text/csv', 'tmp_name' => "{$dir}/report", 'error' => UPLOAD_ERR_OK, 'size' => 7],
            'docs' => [
                ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => "{$dir}/a", 'error' => UPLOAD_ERR_OK, 'size' => 1],
                ['name' => 'big.bin', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
            ],
            'deep' => ['error' => ['name' => 'e.txt', 'type' => 'text/plain', 'tmp_name' => "{$dir}/a", 'error' => UPLOAD_ERR_OK, 'size' => 1]],
            'broken' => ['name' => 'x', 'tmp_name' => '', 'error' => 99, 'size' => 0],
        ];

        try {
            $files = (new PsrRequestFactory())->create($request, 'route')->getUploadedFiles();
            $report = $files['report'] ?? null;
            assert($report instanceof UploadedFileInterface);
            $report->moveTo("{$dir}/moved.csv");

            expect($report->getClientFilename())->toBe('up.csv')
                ->and($report->getClientMediaType())->toBe('text/csv')
                ->and($report->getSize())->toBe(7)
                ->and(file_get_contents("{$dir}/moved.csv"))->toBe('id,name')
                ->and($files['docs'][0]->getClientFilename())->toBe('a.txt')
                ->and((string) $files['docs'][0]->getStream())->toBe('a')
                ->and($files['docs'][1]->getError())->toBe(UPLOAD_ERR_INI_SIZE)
                ->and($files['deep']['error']->getClientFilename())->toBe('e.txt')
                ->and($files)->not->toHaveKey('broken')
            ;
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    });

    test('a request without files has none', function (): void {
        expect((new PsrRequestFactory())->create(new FakeRequest('POST', '/upload', body: '{}'), 'route')->getUploadedFiles())->toBe([]);
    });
});
