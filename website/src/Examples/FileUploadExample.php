<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Via;

/**
 * File Upload example.
 *
 * Demonstrates uninterrupted uploads across MPA navigation:
 *  - A SharedWorker drives virtual chunk delivery; it survives tab navigations
 *  - SESSION-scoped signals hold upload state (status, %, filename)
 *  - Three sub-pages share those signals — navigate between them while the
 *    upload is running and the progress bar picks up exactly where it left off
 *  - server-side: receiveChunk action updates the SESSION state and broadcasts
 *    to all connected tabs so every open window stays in sync
 *  - Real file upload mode: actual multipart POST, server reads the file and
 *    stores human-readable size + extension in the uploadFileInfo signal
 */
final class FileUploadExample
{
    public const string SLUG = 'file-upload';

    /** Maximum real upload size (bytes). Chunked uploads are not subject to Swoole's package_max_length. */
    private const int REAL_MAX_BYTES = 1024 * 1024 * 1024;
    private const int REAL_MAX_MB    = 1024;

    /** @var array<string,int> label → virtual bytes */
    private const array SIZES = [
        '50 MB'  => 52_428_800,
        '200 MB' => 209_715_200,
        '1 GB'   => 1_073_741_824,
        '5 GB'   => 5_368_709_120,
        '10 GB'  => 10_737_418_240,
    ];

    /** @var array<string,int> label → bytes/second */
    private const array SPEEDS = [
        '256 KB/s' => 262_144,
        '1 MB/s'   => 1_048_576,
        '5 MB/s'   => 5_242_880,
        '25 MB/s'  => 26_214_400,
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public static function register(Via $app): void
    {
        $app->page('/examples/file-upload', function (Context $c) use ($app): void {
            [
                'status'          => $status,
                'pct'             => $pct,
                'uploadedBytes'   => $uploadedBytes,
                'totalBytes'      => $totalBytes,
                'fileName'        => $fileName,
                'uploadFileInfo'  => $uploadFileInfo,
                'uploadScope'     => $uploadScope,
                'startUpload'     => $startUpload,
                'receiveChunk'    => $receiveChunk,
                'cancelUpload'    => $cancelUpload,
                'resetUpload'     => $resetUpload,
                'uploadChunk'     => $uploadChunk,
            ] = self::mountUploadState($c, $app);

            // ── TAB-local state (not broadcast) ───────────────────────────
            $uploadMode = $c->signal('sim', 'uploadMode'); // 'sim' | 'real'
            $fileError  = '';

            // ── setMode — instantly switches between sim and real forms
            $setMode = $c->action(function () use ($c, $uploadMode, &$fileError): void {
                $mode = (string) $c->input('mode', 'sim');
                if (!\in_array($mode, ['sim', 'real'], strict: true)) {
                    return;
                }
                $fileError = '';
                $uploadMode->setValue($mode);
                $c->syncSignals();
                $c->sync();
            }, 'setMode');

            // ── uploadRealFile is no longer used:
            // Real uploads go through startReal (worker) → startUpload → uploadChunk.
            // The action is removed to avoid a dead URL being registered.

            $c->view(function () use (
                $c,
                $status, $pct, $uploadedBytes, $totalBytes, $fileName, $uploadFileInfo,
                $uploadMode, &$fileError,
                $startUpload, $receiveChunk, $cancelUpload, $resetUpload,
                $setMode, $uploadChunk,
            ): string {
                return $c->render('examples/file-upload.html.twig', array_merge(self::meta(), [
                    // Upload state
                    'status'         => $status,
                    'pct'            => $pct,
                    'uploadedBytes'  => $uploadedBytes,
                    'totalBytes'     => $totalBytes,
                    'fileName'       => $fileName,
                    'uploadFileInfo' => $uploadFileInfo,
                    'uploadMode'     => $uploadMode,
                    // Actions
                    'startUpload'    => $startUpload,
                    'receiveChunk'   => $receiveChunk,
                    'cancelUpload'   => $cancelUpload,
                    'resetUpload'    => $resetUpload,
                    'setMode'        => $setMode,
                    'uploadChunk'    => $uploadChunk,
                    // Sim options
                    'sizes'          => self::SIZES,
                    'speeds'         => self::SPEEDS,
                    'realMaxMb'      => self::REAL_MAX_MB,
                    'ctxId'          => $c->getId(),
                    'activePage'     => 'upload',
                    // Per-render state (PHP refs, not signals)
                    'fileError'      => $fileError,
                ]));
            }, block: 'demo', cacheUpdates: false);
        });

        $app->page('/examples/file-upload/browse', function (Context $c) use ($app): void {
            [
                'status'         => $status,
                'pct'            => $pct,
                'totalBytes'     => $totalBytes,
                'fileName'       => $fileName,
                'uploadFileInfo' => $uploadFileInfo,
                'receiveChunk'   => $receiveChunk,
                'cancelUpload'   => $cancelUpload,
                'uploadChunk'    => $uploadChunk,
            ] = self::mountUploadState($c, $app);

            $c->view(function () use (
                $c,
                $status, $pct, $totalBytes, $fileName, $uploadFileInfo,
                $receiveChunk, $cancelUpload, $uploadChunk,
            ): string {
                return $c->render('examples/file-upload-browse.html.twig', array_merge(self::meta(), [
                    'status'         => $status,
                    'pct'            => $pct,
                    'totalBytes'     => $totalBytes,
                    'fileName'       => $fileName,
                    'uploadFileInfo' => $uploadFileInfo,
                    'receiveChunk'   => $receiveChunk,
                    'cancelUpload'   => $cancelUpload,
                    'uploadChunk'    => $uploadChunk,
                    'ctxId'          => $c->getId(),
                    'activePage'     => 'browse',
                ]));
            }, block: 'demo', cacheUpdates: false);
        });

        $app->page('/examples/file-upload/settings', function (Context $c) use ($app): void {
            [
                'status'         => $status,
                'pct'            => $pct,
                'totalBytes'     => $totalBytes,
                'fileName'       => $fileName,
                'uploadFileInfo' => $uploadFileInfo,
                'receiveChunk'   => $receiveChunk,
                'cancelUpload'   => $cancelUpload,
                'uploadChunk'    => $uploadChunk,
            ] = self::mountUploadState($c, $app);

            $c->view(function () use (
                $c,
                $status, $pct, $totalBytes, $fileName, $uploadFileInfo,
                $receiveChunk, $cancelUpload, $uploadChunk,
            ): string {
                return $c->render('examples/file-upload-settings.html.twig', array_merge(self::meta(), [
                    'status'         => $status,
                    'pct'            => $pct,
                    'totalBytes'     => $totalBytes,
                    'fileName'       => $fileName,
                    'uploadFileInfo' => $uploadFileInfo,
                    'receiveChunk'   => $receiveChunk,
                    'cancelUpload'   => $cancelUpload,
                    'uploadChunk'    => $uploadChunk,
                    'ctxId'          => $c->getId(),
                    'activePage'     => 'settings',
                ]));
            }, block: 'demo', cacheUpdates: false);
        });
    }

    // ── Shared state setup ────────────────────────────────────────────────────

    /**
     * Static page metadata shared across all three sub-pages.
     *
     * @return array<string, mixed>
     */
    private static function meta(): array
    {
        return [
            'title'       => '📤 Persistent File Upload',
            'description' => 'Upload continues across MPA navigation. A <strong>SharedWorker</strong> drives chunk delivery; <strong>SESSION-scoped signals</strong> keep every tab in sync via SSE.',
            'summary'     => [
                '<strong>SharedWorker</strong> lives as long as any tab in the session is open. Navigate away — it keeps sending chunks. Navigate back — the new page connects to the same worker.',
                '<strong>SESSION-scoped signals</strong> hold <code>uploadStatus</code>, <code>uploadPct</code>, <code>uploadFileName</code>, and byte counters. When the new page\'s SSE connects, the server immediately pushes current state.',
                '<strong>receiveChunk action</strong> is called by the worker every 200 ms. It updates the session signals and calls <code>$app->broadcast($uploadScope)</code> so all open tabs see the same progress.',
                '<strong>Real file mode</strong> submits an actual <code>multipart/form-data</code> POST. <code>$c->file(\'file\')</code> returns the upload array; the server formats type + size and stores it in the SESSION-scoped <code>uploadFileInfo</code> signal.',
                '<strong>MPA navigation is the proof</strong>. Click Browse or Settings while uploading — the progress bar persists because state lives on the server, not the page.',
            ],
            'anatomy'     => [
                'signals' => [
                    ['name' => 'uploadStatus',    'type' => 'string', 'scope' => 'SESSION', 'default' => '"idle"', 'desc' => 'Upload lifecycle: idle | uploading | complete | cancelled.'],
                    ['name' => 'uploadPct',        'type' => 'int',    'scope' => 'SESSION', 'default' => '0',      'desc' => 'Upload progress 0–100. Authoritative server value — navigation gaps self-heal on next chunk.'],
                    ['name' => 'uploadFileName',   'type' => 'string', 'scope' => 'SESSION', 'default' => '""',     'desc' => 'Filename displayed in the progress bar.'],
                    ['name' => 'uploadTotalBytes', 'type' => 'int',    'scope' => 'SESSION', 'default' => '0',      'desc' => 'Total file size in bytes (virtual or real).'],
                    ['name' => 'uploadedBytes',    'type' => 'int',    'scope' => 'SESSION', 'default' => '0',      'desc' => 'Bytes transferred so far.'],
                    ['name' => 'uploadFileInfo',   'type' => 'string', 'scope' => 'SESSION', 'default' => '""',     'desc' => 'Human-readable file metadata for real uploads, e.g. "3.2 MB · PDF". Empty for simulated uploads.'],
                    ['name' => 'uploadMode',       'type' => 'string', 'scope' => 'TAB',     'default' => '"sim"',  'desc' => 'Which form is shown: sim (SharedWorker simulation) or real (multipart upload). TAB-scoped — each tab can differ.'],
                ],
                'actions' => [
                    ['name' => 'startUpload',     'scope' => 'SESSION', 'desc' => 'Initialises upload SESSION signals and broadcasts to all tabs.'],
                    ['name' => 'receiveChunk',    'scope' => 'SESSION', 'desc' => 'Called by SharedWorker every 200 ms. Updates pct + uploadedBytes, self-heals missed chunks, broadcasts.'],
                    ['name' => 'uploadChunk',     'scope' => 'SESSION', 'desc' => 'Receives a 512\u202fKB slice from the SharedWorker chunk loop. Tracks offset+size, updates pct/uploadedBytes, sets status=complete on the final chunk.'],
                    ['name' => 'setMode',         'scope' => 'TAB',     'desc' => 'Switches uploadMode between sim and real, clears file errors, re-renders the form block.'],
                    ['name' => 'cancelUpload',    'scope' => 'SESSION', 'desc' => 'Resets all upload signals to idle. Works from any sub-page.'],
                    ['name' => 'resetUpload',     'scope' => 'SESSION', 'desc' => 'Clears completed/cancelled state so a new upload can begin.'],
                ],
                'views' => [
                    ['name' => 'file-upload.html.twig',          'desc' => 'Mode toggle, sim form (filename + size/speed pickers), real file form (multipart, $c->file(), file info display).'],
                    ['name' => 'file-upload-browse.html.twig',   'desc' => 'Fake file browser sub-page showing live upload row during transfer.'],
                    ['name' => 'file-upload-settings.html.twig', 'desc' => 'Fake settings sub-page proving upload state survives any navigation.'],
                ],
            ],
            'githubLinks' => [
                ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/FileUploadExample.php'],
                ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/file-upload.html.twig'],
                ['label' => 'View worker',   'url' => 'https://github.com/mbolli/php-via/blob/master/website/public/upload-worker.js'],
            ],
        ];
    }

    /**
     * Register SESSION-scoped upload signals and actions on the given context.
     *
     * All three sub-pages call this so that SESSION broadcasts reach them and
     * their action URLs are valid for the SharedWorker to deliver chunks.
     *
     * @return array{
     *     status: Signal,
     *     pct: Signal,
     *     uploadedBytes: Signal,
     *     totalBytes: Signal,
     *     fileName: Signal,
     *     uploadFileInfo: Signal,
     *     uploadScope: string,
     *     startUpload: Action,
     *     receiveChunk: Action,
     *     cancelUpload: Action,
     *     resetUpload: Action,
     *     uploadChunk: Action,
     * }
     */
    private static function mountUploadState(Context $c, Via $app): array
    {
        $uploadScope = Scope::build('upload', $c->getSessionId() ?? $c->getId());
        $c->addScope($uploadScope);

        // SESSION-scoped signals — shared across all contexts in the upload scope
        $status         = $c->signal('idle', 'uploadStatus',   $uploadScope);
        $pct            = $c->signal(0,      'uploadPct',      $uploadScope);
        $uploadedBytes  = $c->signal(0,      'uploadedBytes',  $uploadScope);
        $totalBytes     = $c->signal(0,      'uploadTotalBytes', $uploadScope);
        $fileName       = $c->signal('',     'uploadFileName', $uploadScope);
        $uploadFileInfo = $c->signal('',     'uploadFileInfo', $uploadScope);

        // ── startUpload — called once by the page JS before the worker begins chunking
        $startUpload = $c->action(function () use (
            $c,
            $status, $pct, $uploadedBytes, $totalBytes, $fileName, $uploadFileInfo, $uploadScope, $app,
        ): void {
            $total = (int) $c->input('total', 0);
            $name  = (string) $c->input('file', 'file.bin');

            if ($total <= 0) {
                return;
            }

            $status->setValue('uploading');
            $fileName->setValue($name);
            $totalBytes->setValue($total);
            $uploadedBytes->setValue(0);
            $pct->setValue(0);
            $uploadFileInfo->setValue('');

            $app->broadcast($uploadScope);
        }, 'startUpload');

        // ── receiveChunk — called by SharedWorker every 200 ms
        // The worker sends its authoritative pct so navigation gaps self-heal:
        // if two chunks were missed, the next chunk jumps pct forward correctly.
        $receiveChunk = $c->action(function () use (
            $c,
            $status, $pct, $uploadedBytes, $totalBytes, $fileName, $uploadScope, $app,
        ): void {
            if ($status->string() !== 'uploading') {
                return;
            }

            $workerPct = (int) $c->input('pct', 0);
            $total     = (int) $c->input('total', 0);
            $name      = (string) $c->input('file', '');

            // Initialise on first chunk if startUpload was not yet received
            if ($total > 0 && $totalBytes->int() === 0) {
                $totalBytes->setValue($total);
            }

            if ($name !== '' && $fileName->string() === '') {
                $fileName->setValue($name);
            }

            $newPct = max($pct->int(), $workerPct); // never go backwards
            $pct->setValue($newPct);
            $uploadedBytes->setValue((int) round($totalBytes->int() * $newPct / 100));

            if ($newPct >= 100) {
                $status->setValue('complete');
            }

            $app->broadcast($uploadScope);
        }, 'receiveChunk');

        // ── cancelUpload — resets to idle; works from any sub-page
        $cancelUpload = $c->action(function () use (
            $status, $pct, $uploadedBytes, $totalBytes, $fileName, $uploadFileInfo, $uploadScope, $app,
        ): void {
            $status->setValue('idle');
            $fileName->setValue('');
            $totalBytes->setValue(0);
            $uploadedBytes->setValue(0);
            $pct->setValue(0);
            $uploadFileInfo->setValue('');

            $app->broadcast($uploadScope);
        }, 'cancelUpload');

        // ── resetUpload — clears complete/cancelled; allows starting again
        $resetUpload = $c->action(function () use (
            $status, $pct, $uploadedBytes, $totalBytes, $fileName, $uploadFileInfo, $uploadScope, $app,
        ): void {
            $status->setValue('idle');
            $fileName->setValue('');
            $totalBytes->setValue(0);
            $uploadedBytes->setValue(0);
            $pct->setValue(0);
            $uploadFileInfo->setValue('');

            $app->broadcast($uploadScope);
        }, 'resetUpload');

        // ── uploadChunk — receives a real file slice from the SharedWorker chunk loop.
        // NOTE: this is a demo — the chunk bytes are intentionally discarded after
        //       validation. A real implementation would write them to disk or object storage.
        $uploadChunk = $c->action(function () use (
            $c, $app,
            $status, $pct, $uploadedBytes, $totalBytes, $fileName, $uploadFileInfo, $uploadScope,
        ): void {
            if ($status->string() !== 'uploading') {
                return;
            }

            $chunk  = $c->file('chunk');
            $offset = (int) $c->input('offset', 0);
            $total  = (int) $c->input('total', 0);
            $name   = (string) $c->input('name', '');

            if ($chunk === null || $total <= 0) {
                return;
            }

            // A04 – Insecure Design: guard total size server-side; client-supplied value
            // is not trusted. Log the violation so probing attempts leave a trace.
            if ($total > self::REAL_MAX_BYTES) {
                $app->log('warn', 'uploadChunk: rejected oversized claim total=' . $total . ' limit=' . self::REAL_MAX_BYTES);
                $status->setValue('cancelled');
                $app->broadcast($uploadScope);

                return;
            }

            // A04 – Insecure Design: derive progress from server-side tracking, not from
            // a client-supplied offset. Use the amount already recorded + this chunk's
            // actual byte count — never trust $offset alone as proof bytes were delivered.
            //
            // This prevents fake completion via `offset = total - 2, chunk = 2 bytes`.
            // It also bounds chunk count: once server-tracked bytes reach $total the upload
            // finalises, so repeated offset=0 posts can never stall it open indefinitely.
            $alreadyReceived = $uploadedBytes->int();
            $chunkSize       = $chunk['size'];

            // Validate offset matches server expectation (tolerate re-sends of same chunk).
            if ($offset !== $alreadyReceived) {
                // Silently ignore duplicate or out-of-order chunks; worker retries will
                // re-send with the correct offset on the next pass.
                return;
            }

            if ($totalBytes->int() === 0) {
                $totalBytes->setValue($total);
            }

            // A03 – Injection: strip path separators and null bytes from filename before
            // storing it in a signal. basename() handles the common cases.
            if ($fileName->string() === '' && $name !== '') {
                $safeName = basename(str_replace("\0", '', $name));
                $fileName->setValue($safeName !== '' ? $safeName : 'upload');
            }

            $received = $alreadyReceived + $chunkSize;
            $isFinal  = $received >= $total;
            $uploadedBytes->setValue($received);
            $pct->setValue($isFinal ? 100 : min(99, (int) round($received / $total * 100)));

            if ($isFinal) {
                $storedName = $fileName->string();
                $ext        = strtoupper(pathinfo($storedName, PATHINFO_EXTENSION));
                $ext        = $ext !== '' ? $ext : 'file';
                $sizeLabel  = $total >= 1_048_576
                    ? round($total / 1_048_576, 1) . ' MB'
                    : ceil($total / 1024) . ' kB';
                $uploadFileInfo->setValue($sizeLabel . ' · ' . $ext);
                $status->setValue('complete');
            }

            $app->broadcast($uploadScope);
        }, 'uploadChunk');

        return compact(
            'status', 'pct', 'uploadedBytes', 'totalBytes', 'fileName', 'uploadFileInfo',
            'uploadScope', 'startUpload', 'receiveChunk', 'cancelUpload', 'resetUpload', 'uploadChunk',
        );
    }
}
