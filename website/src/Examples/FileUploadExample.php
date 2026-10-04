<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/**
 * File Upload example: SharedWorker extendedLifetime demo.
 *
 * Shows how an MPA can gain SPA-like continuity with no SPA routing code:
 *  - SharedWorker survives page navigations; extendedLifetime (Chrome 148+)
 *    covers even the brief port-zero gap between pages
 *  - Per-session signals hold upload state server-side; new pages pick up
 *    instantly when their SSE stream opens
 *  - Simulated mode: receiveChunk action called every 200 ms by the worker
 *  - Real file mode: File.slice() 512 KB chunks POSTed sequentially;
 *    mid-upload navigation is transparent via connect/URL refresh
 *  - Fallback: nav guard (confirm dialog + beforeunload) on non-Chrome browsers
 */
final class FileUploadExample {
    public const string SLUG = 'file-upload';

    /** Maximum real upload size (bytes). Chunked uploads are not subject to Swoole's package_max_length. */
    private const int REAL_MAX_BYTES = 1024 * 1024 * 1024;
    private const int REAL_MAX_MB = 1024;

    /** @var array<string,int> label → virtual bytes */
    private const array SIZES = [
        '50 MB' => 52_428_800,
        '200 MB' => 209_715_200,
        '1 GB' => 1_073_741_824,
        '5 GB' => 5_368_709_120,
        '10 GB' => 10_737_418_240,
    ];

    /** @var array<string,int> label → bytes/second */
    private const array SPEEDS = [
        '256 KB/s' => 262_144,
        '1 MB/s' => 1_048_576,
        '5 MB/s' => 5_242_880,
        '25 MB/s' => 26_214_400,
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public static function register(Via $app): void {
        $app->page('/examples/file-upload', function (Context $c) use ($app): void {
            self::mountUploadState($c, $app);

            // ── TAB-local state (not broadcast) ───────────────────────────
            $c->signal('sim', 'uploadMode'); // 'sim' | 'real'
            $fileError = '';

            // ── setMode: instantly switches between sim and real forms
            $c->action(function (Context $ctx) use (&$fileError): void {
                $mode = (string) $ctx->input('mode', 'sim');
                if (!\in_array($mode, ['sim', 'real'], strict: true)) {
                    return;
                }
                $fileError = '';
                $ctx->getSignal('uploadMode')->setValue($mode);
                $ctx->sync();
            }, 'setMode');

            $c->view('examples/file-upload.html.twig', function () use (&$fileError): array {
                return array_merge(self::meta(), [
                    // Sim options
                    'sizes' => self::SIZES,
                    'speeds' => self::SPEEDS,
                    'realMaxMb' => self::REAL_MAX_MB,
                    'activePage' => 'upload',
                    // Per-render state (PHP refs, not signals)
                    'fileError' => $fileError,
                ]);
            }, block: 'demo');
        });

        $app->page('/examples/file-upload/browse', function (Context $c) use ($app): void {
            self::mountUploadState($c, $app);

            $c->view('examples/file-upload-browse.html.twig', fn (): array => array_merge(self::meta(), [
                'activePage' => 'browse',
            ]), block: 'demo');
        });

        $app->page('/examples/file-upload/settings', function (Context $c) use ($app): void {
            self::mountUploadState($c, $app);

            $c->view('examples/file-upload-settings.html.twig', fn (): array => array_merge(self::meta(), [
                'activePage' => 'settings',
            ]), block: 'demo');
        });
    }

    // ── Shared state setup ────────────────────────────────────────────────────

    /**
     * Static page metadata shared across all three sub-pages.
     *
     * @return array<string, mixed>
     */
    private static function meta(): array {
        return [
            'title' => 'Background Upload via SharedWorker',
            'description' => 'A demonstration of how <code>SharedWorker</code> + <code>extendedLifetime</code> (Chrome 148+) lets an MPA behave like an SPA: background work survives page navigations without a single line of SPA routing code.',
            'summary' => [
                '<strong>The core idea</strong>: in a classic MPA, navigating away tears down the page, kills any in-flight XHR, and resets all client state. A <strong>SharedWorker</strong> partially breaks that rule: it is shared across tabs and not tied to a single page lifecycle. But the browser is still allowed to terminate it the moment all ports disconnect, which happens briefly during every navigation.',
                '<strong>extendedLifetime</strong> (Chrome 148+) closes the last gap: without it, the browser may terminate the SharedWorker during the brief moment between pages when the port count drops to zero. On Chrome 148+, a real upload continues chunking with zero user friction. On other browsers, that gap can kill the worker, hence the navigation guard.',
                '<strong>Per-session signals</strong> are the server-side complement. They hold <code>uploadStatus</code>, <code>uploadPct</code>, <code>uploadFileName</code>, and byte counters for every tab of your session, in a custom <code>upload:</code> scope built from <code>Scope::sessionScope()</code>. Each write broadcasts that scope, and when the new page\'s SSE stream opens, the server pushes the current state (no polling, no local storage).',
                '<strong>Real file mode</strong> shows the full picture: the worker slices the file into 512 KB chunks (<code>File.slice()</code>) and POSTs them one by one to the <code>uploadChunk</code> action. Between each <code>await</code> the worker may receive a <code>connect</code> message with fresh URLs from the new page, making mid-upload navigation transparent.',
                '<strong>Graceful fallback</strong>: on browsers where the worker can be killed between pages, an in-page confirm dialog intercepts subnav clicks and a <code>beforeunload</code> handler covers other navigation. The guard is automatically suppressed on Chrome 148+ once the worker\'s <code>workerBorn</code> timestamp confirms it survived.',
            ],
            'anatomy' => [
                'signals' => [
                    ['name' => 'uploadStatus', 'type' => 'string', 'scope' => 'Custom', 'default' => '"idle"', 'desc' => 'Upload lifecycle: idle | uploading | complete | cancelled.'],
                    ['name' => 'uploadPct', 'type' => 'int', 'scope' => 'Custom', 'default' => '0', 'desc' => 'Upload progress 0 to 100. Authoritative server value: navigation gaps self-heal on next chunk.'],
                    ['name' => 'uploadFileName', 'type' => 'string', 'scope' => 'Custom', 'default' => '""', 'desc' => 'Filename displayed in the progress bar.'],
                    ['name' => 'uploadTotalBytes', 'type' => 'int', 'scope' => 'Custom', 'default' => '0', 'desc' => 'Total file size in bytes (virtual or real).'],
                    ['name' => 'uploadedBytes', 'type' => 'int', 'scope' => 'Custom', 'default' => '0', 'desc' => 'Bytes transferred so far.'],
                    ['name' => 'uploadFileInfo', 'type' => 'string', 'scope' => 'Custom', 'default' => '""', 'desc' => 'Human-readable file metadata for real uploads, e.g. "3.2 MB · PDF". Empty for simulated uploads.'],
                    ['name' => 'uploadMode', 'type' => 'string', 'scope' => 'TAB', 'default' => '"sim"', 'desc' => 'Which form is shown: sim (SharedWorker simulation) or real (multipart upload). TAB-scoped: each tab can differ.'],
                ],
                'actions' => [
                    ['name' => 'startUpload', 'desc' => 'Initialises the upload signals (status=uploading, total, filename); the writes reach every tab of the session. Called by the worker before the chunk loop.'],
                    ['name' => 'receiveChunk', 'desc' => 'Simulated mode only. Called by SharedWorker every 200 ms. Updates pct and uploadedBytes from the worker\'s authoritative value, which heals navigation gaps.'],
                    ['name' => 'uploadChunk', 'desc' => 'Real file mode only. Receives a 512 KB slice from the SharedWorker chunk loop. Tracks offset and size, updates pct/uploadedBytes, sets status=complete and formats uploadFileInfo on the final chunk.'],
                    ['name' => 'setMode', 'desc' => 'Switches uploadMode between sim and real, clears file errors, re-renders the form block.'],
                    ['name' => 'cancelUpload', 'desc' => 'Resets all upload signals to idle. Works from any sub-page.'],
                    ['name' => 'resetUpload', 'desc' => 'Clears completed/cancelled state so a new upload can begin.'],
                ],
                'views' => [
                    ['name' => 'file-upload.html.twig', 'desc' => 'Mode toggle, sim form (filename + size/speed pickers), real file form. Real uploads use File.slice() chunks (512 KB each) sent by the SharedWorker via uploadChunk. Navigation guard shown on non-Chrome browsers.'],
                    ['name' => 'file-upload-browse.html.twig', 'desc' => 'Fake file browser sub-page showing live upload row during transfer.'],
                    ['name' => 'file-upload-settings.html.twig', 'desc' => 'Fake settings sub-page proving upload state survives any navigation.'],
                ],
            ],
            'githubLinks' => [
                ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/FileUploadExample.php'],
                ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/file-upload.html.twig'],
                ['label' => 'View worker', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/public/upload-worker.js'],
            ],
        ];
    }

    /**
     * Register the session's upload signals and actions on the given context.
     *
     * All three sub-pages call this so that the upload's broadcasts reach them and
     * their action URLs are valid for the SharedWorker to deliver chunks.
     */
    private static function mountUploadState(Context $c, Via $app): void {
        // One scope per session, built from its session scope: signal ids carry the scope, and the session id stays on the server.
        // A scope of its own keeps the frequent progress broadcasts away from the session's other pages.
        $uploadScope = Scope::build('upload', Scope::sessionScope($c->getSessionId() ?? $c->getId()));

        // Shared by every tab of the session. Each write broadcasts the upload scope, which re-renders these pages.
        $c->signal('idle', 'uploadStatus', $uploadScope);
        $c->signal(0, 'uploadPct', $uploadScope);
        $c->signal(0, 'uploadedBytes', $uploadScope);
        $c->signal(0, 'uploadTotalBytes', $uploadScope);
        $c->signal('', 'uploadFileName', $uploadScope);
        $c->signal('', 'uploadFileInfo', $uploadScope);

        // ── startUpload: called once by the page JS before the worker begins chunking
        $c->action(function (Context $ctx): void {
            $total = (int) $ctx->input('total', 0);
            $name = (string) $ctx->input('file', 'file.bin');

            if ($total <= 0) {
                return;
            }

            $ctx->getSignal('uploadStatus')->setValue('uploading');
            $ctx->getSignal('uploadFileName')->setValue($name);
            $ctx->getSignal('uploadTotalBytes')->setValue($total);
            $ctx->getSignal('uploadedBytes')->setValue(0);
            $ctx->getSignal('uploadPct')->setValue(0);
            $ctx->getSignal('uploadFileInfo')->setValue('');
        }, 'startUpload');

        // ── receiveChunk: called by SharedWorker every 200 ms
        // The worker sends its authoritative pct so navigation gaps self-heal:
        // if two chunks were missed, the next chunk jumps pct forward correctly.
        $c->action(function (Context $ctx): void {
            $status = $ctx->getSignal('uploadStatus');
            $pct = $ctx->getSignal('uploadPct');
            $uploadedBytes = $ctx->getSignal('uploadedBytes');
            $totalBytes = $ctx->getSignal('uploadTotalBytes');
            $fileName = $ctx->getSignal('uploadFileName');

            if ($status->string() !== 'uploading') {
                return;
            }

            $workerPct = (int) $ctx->input('pct', 0);
            $total = (int) $ctx->input('total', 0);
            $name = (string) $ctx->input('file', '');

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
        }, 'receiveChunk');

        // ── cancelUpload: resets to idle; works from any sub-page
        $c->action(function (Context $ctx): void {
            $ctx->getSignal('uploadStatus')->setValue('idle');
            $ctx->getSignal('uploadFileName')->setValue('');
            $ctx->getSignal('uploadTotalBytes')->setValue(0);
            $ctx->getSignal('uploadedBytes')->setValue(0);
            $ctx->getSignal('uploadPct')->setValue(0);
            $ctx->getSignal('uploadFileInfo')->setValue('');
        }, 'cancelUpload');

        // ── resetUpload: clears complete/cancelled; allows starting again
        $c->action(function (Context $ctx): void {
            $ctx->getSignal('uploadStatus')->setValue('idle');
            $ctx->getSignal('uploadFileName')->setValue('');
            $ctx->getSignal('uploadTotalBytes')->setValue(0);
            $ctx->getSignal('uploadedBytes')->setValue(0);
            $ctx->getSignal('uploadPct')->setValue(0);
            $ctx->getSignal('uploadFileInfo')->setValue('');
        }, 'resetUpload');

        // ── uploadChunk: receives a real file slice from the SharedWorker chunk loop.
        // NOTE: this is a demo. The chunk bytes are intentionally discarded after
        //       validation. A real implementation would write them to disk or object storage.
        $c->action(function (Context $ctx) use ($app): void {
            $status = $ctx->getSignal('uploadStatus');
            $pct = $ctx->getSignal('uploadPct');
            $uploadedBytes = $ctx->getSignal('uploadedBytes');
            $totalBytes = $ctx->getSignal('uploadTotalBytes');
            $fileName = $ctx->getSignal('uploadFileName');
            $uploadFileInfo = $ctx->getSignal('uploadFileInfo');

            if ($status->string() !== 'uploading') {
                return;
            }

            $chunk = $ctx->file('chunk');
            $offset = (int) $ctx->input('offset', 0);
            $total = (int) $ctx->input('total', 0);
            $name = (string) $ctx->input('name', '');

            if ($chunk === null || $total <= 0) {
                return;
            }

            // A04 (Insecure Design): guard total size server-side; client-supplied value
            // is not trusted. Log the violation so probing attempts leave a trace.
            if ($total > self::REAL_MAX_BYTES) {
                $app->log('warn', 'uploadChunk: rejected oversized claim total=' . $total . ' limit=' . self::REAL_MAX_BYTES);
                $status->setValue('cancelled');

                return;
            }

            // A04 (Insecure Design): derive progress from server-side tracking, not from
            // a client-supplied offset. Use the amount already recorded + this chunk's
            // actual byte count. Never trust $offset alone as proof bytes were delivered.
            //
            // This prevents fake completion via `offset = total - 2, chunk = 2 bytes`.
            // It also bounds chunk count: once server-tracked bytes reach $total the upload
            // finalises, so repeated offset=0 posts can never stall it open indefinitely.
            $alreadyReceived = $uploadedBytes->int();
            $chunkSize = $chunk['size'];

            // Validate offset matches server expectation (tolerate re-sends of same chunk).
            if ($offset !== $alreadyReceived) {
                // Silently ignore duplicate or out-of-order chunks; worker retries will
                // re-send with the correct offset on the next pass.
                return;
            }

            if ($totalBytes->int() === 0) {
                $totalBytes->setValue($total);
            }

            // A03 (Injection): strip path separators and null bytes from filename before
            // storing it in a signal. basename() handles the common cases.
            if ($fileName->string() === '' && $name !== '') {
                $safeName = basename(str_replace("\0", '', $name));
                $fileName->setValue($safeName !== '' ? $safeName : 'upload');
            }

            $received = $alreadyReceived + $chunkSize;
            $isFinal = $received >= $total;
            $uploadedBytes->setValue($received);
            $pct->setValue($isFinal ? 100 : min(99, (int) round($received / $total * 100)));

            if ($isFinal) {
                $storedName = $fileName->string();
                $ext = strtoupper(pathinfo($storedName, PATHINFO_EXTENSION));
                $ext = $ext !== '' ? $ext : 'file';
                $sizeLabel = $total >= 1_048_576
                    ? round($total / 1_048_576, 1) . ' MB'
                    : ceil($total / 1024) . ' kB';
                $uploadFileInfo->setValue($sizeLabel . ' · ' . $ext);
                $status->setValue('complete');
            }
        }, 'uploadChunk');
    }
}
