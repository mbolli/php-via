/**
 * Shared Worker — file upload simulation.
 *
 * Lives as long as at least one browser tab is connected. Survives MPA
 * navigation: when the user navigates to a sub-page the old port disconnects
 * and the new page immediately re-connects, passing fresh action URLs +
 * contextId. In-flight chunk requests that hit an expired context return 400
 * and are silently dropped; the next chunk uses the new URLs.
 *
 * Protocol (messages FROM page TO worker):
 *   {type:'connect',   chunkUrl, cancelUrl, ctxId}               — sent on every page load
 *   {type:'start',     fileName, virtualSize, speedBps,
 *          startUrl, chunkUrl, cancelUrl, ctxId}                  — start simulated upload (worker notifies server)
 *   {type:'startReal', file, startUrl, uploadChunkUrl,
 *          chunkUrl, cancelUrl, ctxId}                            — start real file chunked upload inside worker
 *   {type:'resume',    fileName, virtualTotal, virtualUploaded,
 *          pct, speedBps, chunkUrl, cancelUrl, ctxId}             — resume simulated upload after worker GC
 *   {type:'cancel'}                                               — cancel current upload
 *   {type:'reset'}                                                — reset to idle state
 *
 * No 'disconnect' message is sent on navigation — pages let ports go stale and
 * broadcast() prunes them via try/catch. This keeps the timer alive across the
 * navigation gap so uploads survive MPA transitions.
 *
 * Protocol (messages FROM worker TO page):
 *   {type:'state', status, fileName, pct, virtualUploaded, virtualTotal}
 *   {type:'log',   msg}                                  — relayed to page console
 */

'use strict';

const INTERVAL_MS      = 200;          // simulation tick interval – 5 per second
const CHUNK_SIZE_BYTES = 512 * 1024;   // 512 KB per real chunk

let ports        = [];
let uploadTimer  = null;

let state = {
    status:          'idle',   // idle | uploading | complete | cancelled
    fileName:        '',
    virtualUploaded: 0,
    virtualTotal:    0,
    pct:             0,
    speedBps:        2 * 1024 * 1024, // default 2 MB/s
    isReal:          false,    // true when real chunked upload is in flight
    chunkUrl:        '',       // receiveChunk URL (simulation)
    uploadChunkUrl:  '',       // uploadChunk URL (real file chunks)
    cancelUrl:       '',
    ctxId:           '',
};

// ── Logging ───────────────────────────────────────────────────────────────────

function log(msg) {
    const text = '[upload-worker] ' + msg;
    // Print in worker's own DevTools (chrome://inspect/#workers)
    console.log(text);
    // Relay to all connected pages so it appears in the page console too
    ports.forEach(function (p) { try { p.postMessage({ type: 'log', msg: text }); } catch {} });
}

// ── Connection handling ───────────────────────────────────────────────────────

self.onconnect = function (e) {
    const port = e.ports[0];
    ports.push(port);
    log('port connected (total ports: ' + ports.length + ')');

    port.onmessage = function (evt) {
        const msg = evt.data;
        log('received: ' + msg.type + (msg.ctxId ? ' ctxId=' + msg.ctxId.slice(-6) : ''));
        switch (msg.type) {

            case 'connect':
                // New page connected — update routing info with fresh context URLs
                if (msg.chunkUrl)       state.chunkUrl       = msg.chunkUrl;
                if (msg.cancelUrl)      state.cancelUrl      = msg.cancelUrl;
                if (msg.ctxId)          state.ctxId          = msg.ctxId;
                if (msg.uploadChunkUrl) state.uploadChunkUrl = msg.uploadChunkUrl;
                log('routing updated — status=' + state.status + ' pct=' + state.pct + '%');
                // Immediately push current state so the reconnected page shows the right progress
                port.postMessage({ type: 'state', ...snapshot() });
                break;

            case 'start':
                if (state.status === 'uploading') {
                    log('ignoring start — already uploading');
                    break;
                }
                // Update routing info (page passes everything in the start message)
                if (msg.chunkUrl)  state.chunkUrl  = msg.chunkUrl;
                if (msg.cancelUrl) state.cancelUrl = msg.cancelUrl;
                if (msg.ctxId)     state.ctxId     = msg.ctxId;

                state.status          = 'uploading';
                state.fileName        = msg.fileName || 'file.bin';
                state.virtualTotal    = msg.virtualSize || 1073741824;
                state.virtualUploaded = 0;
                state.pct             = 0;
                state.speedBps        = msg.speedBps || 2 * 1024 * 1024;
                log('starting upload: ' + state.fileName + ' size=' + state.virtualTotal + ' speed=' + state.speedBps);
                broadcast({ type: 'state', ...snapshot() });

                // Worker notifies the server to initialise SESSION signals —
                // this used to be done by the page but the page may navigate away
                // before the fetch completes. Doing it here is safe.
                if (msg.startUrl && state.ctxId) {
                    log('notifying server via startUrl');
                    fetch(msg.startUrl, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    new URLSearchParams({
                            total:   String(state.virtualTotal),
                            file:    state.fileName,
                            via_ctx: state.ctxId,
                        }),
                    }).then(function (res) {
                        log('startUrl response: ' + res.status);
                        if (res.ok) startTimer();
                    }).catch(function (err) {
                        log('startUrl fetch error: ' + err);
                        // Start anyway so simulation runs even if server is temporarily unreachable
                        startTimer();
                    });
                } else {
                    startTimer();
                }
                break;

            case 'resume':
                // Worker was GC'd during navigation — page restored state from sessionStorage.
                if (state.status === 'uploading') {
                    log('ignoring resume — already uploading');
                    break;
                }
                if (msg.isReal) {
                    // Real file XHR can\'t be resumed — the File object is gone.
                    log('resume: real upload was interrupted — resetting to idle');
                    resetState();
                    broadcast({ type: 'state', ...snapshot() });
                    break;
                }
                if (msg.chunkUrl)  state.chunkUrl  = msg.chunkUrl;
                if (msg.cancelUrl) state.cancelUrl = msg.cancelUrl;
                if (msg.ctxId)     state.ctxId     = msg.ctxId;
                state.status          = 'uploading';
                state.fileName        = msg.fileName        || 'file.bin';
                state.virtualTotal    = msg.virtualTotal    || 0;
                state.virtualUploaded = msg.virtualUploaded || 0;
                state.pct             = msg.pct             || 0;
                state.speedBps        = msg.speedBps        || 2 * 1024 * 1024;
                log('resuming upload: ' + state.fileName + ' from pct=' + state.pct + '%');
                broadcast({ type: 'state', ...snapshot() });
                startTimer();
                break;

            case 'startReal': {
                if (state.status === 'uploading') {
                    log('ignoring startReal — already uploading');
                    break;
                }
                if (msg.chunkUrl)       state.chunkUrl       = msg.chunkUrl;
                if (msg.cancelUrl)      state.cancelUrl      = msg.cancelUrl;
                if (msg.ctxId)          state.ctxId          = msg.ctxId;
                if (msg.uploadChunkUrl) state.uploadChunkUrl = msg.uploadChunkUrl;

                const file = msg.file;
                state.status          = 'uploading';
                state.isReal          = true;
                state.fileName        = file.name;
                state.virtualTotal    = file.size;
                state.virtualUploaded = 0;
                state.pct             = 0;
                state.speedBps        = 0;
                log('real chunked upload: ' + file.name + ' (' + file.size + ' bytes)' +
                    ' — ' + Math.ceil(file.size / CHUNK_SIZE_BYTES) + ' chunks');
                broadcast({ type: 'state', ...snapshot() });

                // Acquire an exclusive Web Lock for the lifetime of this upload.
                // The Web Locks spec forbids browsers from terminating a worker while
                // it holds a lock, so the worker (and the File it owns) survive MPA
                // navigation gaps even without extendedLifetime origin trial support.
                // The lock is released automatically when the returned Promise settles.
                const _runRealUpload = function () {

                // 1. Initialise server SESSION signals so all tabs see "uploading"
                return fetch(msg.startUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    new URLSearchParams({
                        total:   String(state.virtualTotal),
                        file:    state.fileName,
                        via_ctx: state.ctxId,
                    }),
                }).then(async function () {
                    log('startUrl OK — beginning chunk loop');
                    let offset = 0;

                    // 2. Send file in slices — each chunk is a separate POST so the
                    //    loop is suspend/resumable: navigation updates state.ctxId and
                    //    state.uploadChunkUrl via "connect" messages between awaits.
                    //
                    //    Offset advances only AFTER a successful response. On failure we
                    //    wait 350 ms (enough for the new page's "connect" to refresh URLs)
                    //    and retry once with the updated ctxId + uploadChunkUrl.
                    while (offset < file.size && state.status === 'uploading') {
                        const chunkOffset = offset;
                        const chunk       = file.slice(chunkOffset, chunkOffset + CHUNK_SIZE_BYTES);

                        const sendChunkOnce = async function () {
                            const fd = new FormData();
                            fd.append('chunk',   chunk, file.name);
                            fd.append('offset',  String(chunkOffset));
                            fd.append('total',   String(file.size));
                            fd.append('name',    file.name);
                            fd.append('via_ctx', state.ctxId);  // refreshed by connect on navigation
                            return fetch(state.uploadChunkUrl, { method: 'POST', body: fd });
                        };

                        let res;
                        try {
                            res = await sendChunkOnce();
                        } catch (err) {
                            log('chunk fetch error at offset ' + chunkOffset + ' — retrying: ' + err);
                            // Wait for new page's connect message to refresh ctxId/uploadChunkUrl
                            await new Promise(function (r) { setTimeout(r, 350); });
                            if (state.status !== 'uploading') return;
                            try {
                                res = await sendChunkOnce();
                            } catch (err2) {
                                log('chunk retry failed: ' + err2);
                                state.status = 'cancelled';
                                state.isReal = false;
                                broadcast({ type: 'state', ...snapshot() });
                                return;
                            }
                        }

                        if (!res.ok) {
                            // 4xx/5xx — possibly stale ctxId during navigation; wait + retry once
                            log('chunk POST HTTP ' + res.status + ' at offset ' + chunkOffset + ' — retrying after delay');
                            await new Promise(function (r) { setTimeout(r, 350); });
                            if (state.status !== 'uploading') return;
                            try {
                                res = await sendChunkOnce();
                            } catch (err2) {
                                log('chunk retry fetch error: ' + err2);
                                state.status = 'cancelled';
                                state.isReal = false;
                                broadcast({ type: 'state', ...snapshot() });
                                return;
                            }
                            if (!res.ok) {
                                log('chunk retry also failed: HTTP ' + res.status);
                                state.status = 'cancelled';
                                state.isReal = false;
                                broadcast({ type: 'state', ...snapshot() });
                                return;
                            }
                        }

                        // Success — advance offset
                        offset += chunk.size;
                        state.virtualUploaded = offset;
                        state.pct             = Math.min(99, Math.round(offset / file.size * 100));
                        broadcast({ type: 'state', ...snapshot() });
                        log('chunk ok: ' + offset + '/' + file.size + ' (' + state.pct + '%)');
                    }

                    if (state.status === 'uploading') {
                        // Server already set status=complete via SSE on last chunk;
                        // mirror it locally so the page updates without waiting for SSE.
                        state.status          = 'complete';
                        state.pct             = 100;
                        state.virtualUploaded = state.virtualTotal;
                        state.isReal          = false;
                        broadcast({ type: 'state', ...snapshot() });
                        log('all chunks sent — upload complete');
                    }
                }).catch(function (err) {
                    log('startReal: startUrl error: ' + err);
                    state.status = 'cancelled';
                    state.isReal = false;
                    broadcast({ type: 'state', ...snapshot() });
                }); // end _runRealUpload
                };

                if ('locks' in navigator) {
                    navigator.locks.request('fu-real-upload', { mode: 'exclusive' }, _runRealUpload);
                } else {
                    log('Web Locks not available — worker may be GC\'d during navigation');
                    _runRealUpload();
                }
                break;
            }

            case 'cancel':
                log('cancel received');
                stopTimer();
                state.status          = 'cancelled';
                state.isReal          = false;
                state.virtualUploaded = 0;
                state.pct             = 0;
                broadcast({ type: 'state', ...snapshot() });
                break;

            case 'reset':
                log('reset received');
                stopTimer();
                resetState();
                broadcast({ type: 'state', ...snapshot() });
                break;

        }
    };

    port.start();
    // State is sent in the 'connect' message handler; no extra push needed here.
    // (A newly connected port sends 'connect' immediately after start())
};

// ── Helpers ──────────────────────────────────────────────────────────────────

function snapshot() {
    return {
        status:          state.status,
        fileName:        state.fileName,
        pct:             state.pct,
        speedBps:        state.speedBps,
        isReal:          state.isReal,
        virtualUploaded: state.virtualUploaded,
        virtualTotal:    state.virtualTotal,
    };
}

function broadcast(msg) {
    ports = ports.filter(port => {
        try { port.postMessage(msg); return true; } catch { return false; }
    });
}

function startTimer() {
    stopTimer();
    log('timer started');
    uploadTimer = setInterval(sendChunk, INTERVAL_MS);
}

function stopTimer() {
    if (uploadTimer !== null) {
        clearInterval(uploadTimer);
        uploadTimer = null;
        log('timer stopped');
    }
}

function resetState() {
    state.status          = 'idle';
    state.fileName        = '';
    state.virtualUploaded = 0;
    state.virtualTotal    = 0;
    state.pct             = 0;
    state.isReal          = false;
}

// ── Core chunk loop ───────────────────────────────────────────────────────────

async function sendChunk() {
    if (state.status !== 'uploading') {
        stopTimer();
        return;
    }

    const bytesPerChunk = Math.max(1, Math.round(state.speedBps * INTERVAL_MS / 1000));
    const remaining     = state.virtualTotal - state.virtualUploaded;
    const chunkBytes    = Math.min(bytesPerChunk, remaining);

    if (chunkBytes <= 0) {
        // Upload complete
        state.status          = 'complete';
        state.pct             = 100;
        state.virtualUploaded = state.virtualTotal;
        stopTimer();
        log('upload complete');
        broadcast({ type: 'state', ...snapshot() });
        return;
    }

    state.virtualUploaded += chunkBytes;
    state.pct = Math.min(100, Math.round(state.virtualUploaded / state.virtualTotal * 100));

    // Broadcast locally first — keeps animation smooth without waiting for server RTT
    broadcast({ type: 'state', ...snapshot() });

    // Best-effort delivery to server — may fail during navigation (old context GC'd)
    if (state.chunkUrl && state.ctxId) {
        try {
            const res = await fetch(state.chunkUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({
                    bytes:   String(chunkBytes),
                    total:   String(state.virtualTotal),
                    file:    state.fileName,
                    pct:     String(state.pct),
                    via_ctx: state.ctxId,
                }),
            });
            if (!res.ok) {
                log('chunk POST failed: ' + res.status + ' (ctxId=' + state.ctxId.slice(-6) + ')');
            }
        } catch (err) {
            // Network temporarily unavailable (tab navigating) — continue locally.
            // The next chunk will use updated URLs from the 'connect' message.
            log('chunk fetch error (likely navigating): ' + err);
        }
    }
}
