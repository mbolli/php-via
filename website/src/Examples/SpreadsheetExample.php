<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Action;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Signal;
use Mbolli\PhpVia\Tracing\Tracer;
use Mbolli\PhpVia\Via;

final class SpreadsheetExample {
    public const string SLUG = 'spreadsheet';

    private const string SCOPE = 'example:spreadsheet';

    /** @var array<string, array{row: int, col: int, hue: int}> contextId => cursor */
    private static array $cursors = [];

    /** @var array<string, array{r1: int, c1: int, r2: int, c2: int}> contextId => selection */
    private static array $selections = [];

    private static ?\SQLite3 $db = null;

    /** @var null|array{maxRow: int, maxCol: int} Raw DB max (without padding), updated on writes */
    private static ?array $extentCache = null;

    public static function register(Via $app): void {
        self::db(); // initialize on registration

        $app->page('/examples/spreadsheet', function (Context $c) use ($app): void {
            $sessionId = $c->getSessionId() ?? $c->getId();
            $contextId = $c->getId();
            $hue = self::hueForSession($sessionId);

            // Initialize cursor for this context
            if (!isset(self::$cursors[$contextId])) {
                self::$cursors[$contextId] = ['row' => 0, 'col' => 0, 'hue' => $hue];
            }

            $c->onDisconnect(function () use ($contextId, $app): void {
                unset(self::$cursors[$contextId], self::$selections[$contextId]);

                if ($app->getContextsByScope(self::SCOPE) !== []) {
                    $app->broadcast(self::SCOPE);
                }
            });

            $c->addScope(self::SCOPE);

            // TAB-scoped signals: viewport + focus + editing
            $c->signal(0, 'viewRow', Scope::TAB);
            $c->signal(0, 'viewCol', Scope::TAB);
            $c->signal(0, 'focusRow', Scope::TAB);
            $c->signal(0, 'focusCol', Scope::TAB);
            $c->signal(false, 'editing', Scope::TAB);
            $c->signal('', 'editValue', Scope::TAB);

            // Client-writable action parameter signals
            $c->signal(0, 'tr', Scope::TAB);
            $c->signal(0, 'tc', Scope::TAB);
            $c->signal('', 'key', Scope::TAB);
            $c->signal(false, 'shift', Scope::TAB);
            $c->signal(0, 'dr', Scope::TAB);
            $c->signal(0, 'dc', Scope::TAB);
            $c->signal('', 'pasted', Scope::TAB);

            // Dynamic viewport dimensions (written by client ResizeObserver)
            $c->signal(20, 'vrows', Scope::TAB);
            $c->signal(10, 'vcols', Scope::TAB);

            // Jump-to-coordinate input value
            $c->signal('', 'jump', Scope::TAB);

            // Scope-level version counter: bumped on every cursor/edit change
            // so that broadcast() has a changed signal to deliver, triggering re-renders
            $c->signal(0, 'v', self::SCOPE, autoBroadcast: false);

            // Selection range stored server-side per context (not as signals)
            if (!isset(self::$selections[$contextId])) {
                self::$selections[$contextId] = ['r1' => -1, 'c1' => -1, 'r2' => -1, 'c2' => -1];
            }

            // -- Actions --

            $c->action(function (Context $ctx) use ($app, $sessionId, $contextId): void {
                /** @var Signal $targetRow */ $targetRow = $ctx->getSignal('tr');

                /** @var Signal $targetCol */ $targetCol = $ctx->getSignal('tc');

                /** @var Signal $shift */ $shift = $ctx->getSignal('shift');

                /** @var Signal $editing */ $editing = $ctx->getSignal('editing');

                /** @var Signal $focusRow */ $focusRow = $ctx->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $ctx->getSignal('focusCol');

                /** @var Signal $editValue */ $editValue = $ctx->getSignal('editValue');

                /** @var Signal $version */ $version = $ctx->getSignal('v');
                $row = $targetRow->int();
                $col = $targetCol->int();
                $isShift = $shift->bool();

                // Commit any pending edit
                if ($editing->bool()) {
                    self::setCell($focusRow->int(), $focusCol->int(), $editValue->string());
                    $editing->setValue(false, broadcast: false);
                    $editValue->setValue('', broadcast: false);
                }

                $focusRow->setValue($row, broadcast: false);
                $focusCol->setValue($col, broadcast: false);

                $sel = &self::$selections[$contextId];
                if ($isShift) {
                    if ($sel['r1'] === -1) {
                        $sel['r1'] = $row;
                        $sel['c1'] = $col;
                    }
                    $sel['r2'] = $row;
                    $sel['c2'] = $col;
                } else {
                    $sel = ['r1' => $row, 'c1' => $col, 'r2' => $row, 'c2' => $col];
                }

                self::$cursors[$contextId] = ['row' => $row, 'col' => $col, 'hue' => self::hueForSession($sessionId)];
                $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
                $app->broadcast(self::SCOPE);
            }, 'focusCell');

            $c->action(function (Context $ctx) use ($app, $sessionId, $contextId): void {
                /** @var Signal $viewRow */ $viewRow = $ctx->getSignal('viewRow');

                /** @var Signal $viewCol */ $viewCol = $ctx->getSignal('viewCol');

                /** @var Signal $focusRow */ $focusRow = $ctx->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $ctx->getSignal('focusCol');

                /** @var Signal $editing */ $editing = $ctx->getSignal('editing');

                /** @var Signal $editValue */ $editValue = $ctx->getSignal('editValue');

                /** @var Signal $version */ $version = $ctx->getSignal('v');

                /** @var Signal $key */ $key = $ctx->getSignal('key');

                /** @var Signal $shift */ $shift = $ctx->getSignal('shift');

                /** @var Signal $viewportRows */ $viewportRows = $ctx->getSignal('vrows');

                /** @var Signal $viewportCols */ $viewportCols = $ctx->getSignal('vcols');
                $direction = $key->string();
                $isShift = $shift->bool();
                $fr = $focusRow->int();
                $fc = $focusCol->int();

                // Commit on navigation if editing
                if ($editing->bool() && $direction !== 'escape') {
                    self::setCell($fr, $fc, $editValue->string());
                    $editing->setValue(false, broadcast: false);
                    $editValue->setValue('', broadcast: false);
                }
                if ($direction === 'Escape') {
                    $editing->setValue(false, broadcast: false);
                    $editValue->setValue('', broadcast: false);
                    $ctx->sync();

                    return;
                }

                match ($direction) {
                    'ArrowUp' => $fr = max(0, $fr - 1),
                    'ArrowDown' => ++$fr,
                    'ArrowLeft' => $fc = max(0, $fc - 1),
                    'ArrowRight' => ++$fc,
                    'Tab' => $isShift ? $fc = max(0, $fc - 1) : ++$fc,
                    'Enter' => $isShift ? $fr = max(0, $fr - 1) : ++$fr,
                    'PageUp' => $fr = max(0, $fr - $viewportRows->int()),
                    'PageDown' => $fr += $viewportRows->int(),
                    'Home' => [$fr, $fc] = [0, 0],
                    default => null,
                };

                $focusRow->setValue($fr, broadcast: false);
                $focusCol->setValue($fc, broadcast: false);

                $sel = &self::$selections[$contextId];
                if ($isShift) {
                    if ($sel['r1'] === -1) {
                        $sel['r1'] = $fr;
                        $sel['c1'] = $fc;
                    }
                    $sel['r2'] = $fr;
                    $sel['c2'] = $fc;
                } else {
                    $sel = ['r1' => $fr, 'c1' => $fc, 'r2' => $fr, 'c2' => $fc];
                }

                // Auto-scroll viewport
                $vr = $viewRow->int();
                $vc = $viewCol->int();
                if ($fr < $vr) {
                    $vr = $fr;
                } elseif ($fr >= $vr + $viewportRows->int()) {
                    $vr = $fr - $viewportRows->int() + 1;
                }
                if ($fc < $vc) {
                    $vc = $fc;
                } elseif ($fc >= $vc + $viewportCols->int()) {
                    $vc = $fc - $viewportCols->int() + 1;
                }
                $viewRow->setValue($vr, broadcast: false);
                $viewCol->setValue($vc, broadcast: false);

                self::$cursors[$contextId] = ['row' => $fr, 'col' => $fc, 'hue' => self::hueForSession($sessionId)];
                $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
                $app->broadcast(self::SCOPE);
            }, 'navigate');

            $c->action(function (Context $ctx): void {
                /** @var Signal $key */ $key = $ctx->getSignal('key');

                /** @var Signal $focusRow */ $focusRow = $ctx->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $ctx->getSignal('focusCol');

                /** @var Signal $editing */ $editing = $ctx->getSignal('editing');

                /** @var Signal $editValue */ $editValue = $ctx->getSignal('editValue');
                $keyVal = $key->string();
                $prefill = mb_strlen($keyVal) === 1 ? $keyVal : '';
                $currentValue = self::getCell($focusRow->int(), $focusCol->int());
                $editing->setValue(true, broadcast: false);
                $editValue->setValue($prefill !== '' ? $prefill : $currentValue, broadcast: false);
                $ctx->sync();
            }, 'startEdit');

            $c->action(function (Context $ctx) use ($app): void {
                /** @var Signal $focusRow */ $focusRow = $ctx->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $ctx->getSignal('focusCol');

                /** @var Signal $editing */ $editing = $ctx->getSignal('editing');

                /** @var Signal $editValue */ $editValue = $ctx->getSignal('editValue');

                /** @var Signal $version */ $version = $ctx->getSignal('v');
                if (!$editing->bool()) {
                    return;
                }
                self::setCell($focusRow->int(), $focusCol->int(), $editValue->string());
                $editing->setValue(false, broadcast: false);
                $editValue->setValue('', broadcast: false);
                $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
                $app->broadcast(self::SCOPE);
            }, 'commitEdit');

            $c->action(function (Context $ctx): void {
                /** @var Signal $viewRow */ $viewRow = $ctx->getSignal('viewRow');

                /** @var Signal $viewCol */ $viewCol = $ctx->getSignal('viewCol');

                /** @var Signal $scrollDr */ $scrollDr = $ctx->getSignal('dr');

                /** @var Signal $scrollDc */ $scrollDc = $ctx->getSignal('dc');
                $viewRow->setValue(max(0, $viewRow->int() + $scrollDr->int()), broadcast: false);
                $viewCol->setValue(max(0, $viewCol->int() + $scrollDc->int()), broadcast: false);
                $ctx->sync();
            }, 'scroll');

            // Absolute-position scroll targets (written by scrollbar drag)
            $c->signal(0, 'str', Scope::TAB);
            $c->signal(0, 'stc', Scope::TAB);

            $c->action(function (Context $ctx): void {
                /** @var Signal $viewRow */ $viewRow = $ctx->getSignal('viewRow');

                /** @var Signal $viewCol */ $viewCol = $ctx->getSignal('viewCol');

                /** @var Signal $scrollToRow */ $scrollToRow = $ctx->getSignal('str');

                /** @var Signal $scrollToCol */ $scrollToCol = $ctx->getSignal('stc');
                $viewRow->setValue(max(0, $scrollToRow->int()), broadcast: false);
                $viewCol->setValue(max(0, $scrollToCol->int()), broadcast: false);
                $ctx->sync();
            }, 'scrollTo');

            $c->action(function (Context $ctx) use ($app): void {
                /** @var Signal $focusRow */ $focusRow = $ctx->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $ctx->getSignal('focusCol');

                /** @var Signal $editing */ $editing = $ctx->getSignal('editing');

                /** @var Signal $editValue */ $editValue = $ctx->getSignal('editValue');

                /** @var Signal $pasted */ $pasted = $ctx->getSignal('pasted');

                /** @var Signal $version */ $version = $ctx->getSignal('v');
                $data = $pasted->string();
                $pasted->setValue('', broadcast: false);
                if ($data === '') {
                    return;
                }

                $startRow = $focusRow->int();
                $startCol = $focusCol->int();
                $rows = explode("\n", str_replace("\r\n", "\n", $data));
                $cells = [];
                foreach ($rows as $ri => $row) {
                    $cols = explode("\t", $row);
                    foreach ($cols as $ci => $value) {
                        $cells[] = [
                            'row' => $startRow + $ri,
                            'col' => $startCol + $ci,
                            'value' => $value,
                        ];
                    }
                }
                self::setCells($cells);
                $editing->setValue(false, broadcast: false);
                $editValue->setValue('', broadcast: false);
                $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
                $app->broadcast(self::SCOPE);
            }, 'paste');

            $c->action(function (Context $ctx) use ($contextId): void {
                $sel = self::$selections[$contextId] ?? ['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 0];
                $r1 = min($sel['r1'], $sel['r2']);
                $r2 = max($sel['r1'], $sel['r2']);
                $c1 = min($sel['c1'], $sel['c2']);
                $c2 = max($sel['c1'], $sel['c2']);

                $cells = self::getCellRange($r1, $c1, $r2 - $r1 + 1, $c2 - $c1 + 1);
                $tsv = '';
                for ($r = $r1; $r <= $r2; ++$r) {
                    $row = [];
                    for ($col = $c1; $col <= $c2; ++$col) {
                        $row[] = $cells[$r . ':' . $col] ?? '';
                    }
                    $tsv .= implode("\t", $row) . "\n";
                }

                $tsvJson = json_encode(rtrim($tsv, "\n"));
                $ctx->execScript("navigator.clipboard.writeText({$tsvJson})");
            }, 'getCopyData');

            $c->action(function (Context $ctx): void {
                /** @var Signal $viewportRows */ $viewportRows = $ctx->getSignal('vrows');

                /** @var Signal $viewportCols */ $viewportCols = $ctx->getSignal('vcols');
                $viewportRows->setValue(max(3, min(100, $viewportRows->int())), broadcast: false);
                $viewportCols->setValue(max(3, min(52, $viewportCols->int())), broadcast: false);
                $ctx->sync();
            }, 'resize');

            $c->action(function (Context $ctx) use ($app, $sessionId, $contextId): void {
                /** @var Signal $viewRow */ $viewRow = $ctx->getSignal('viewRow');

                /** @var Signal $viewCol */ $viewCol = $ctx->getSignal('viewCol');

                /** @var Signal $focusRow */ $focusRow = $ctx->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $ctx->getSignal('focusCol');

                /** @var Signal $jumpTarget */ $jumpTarget = $ctx->getSignal('jump');

                /** @var Signal $version */ $version = $ctx->getSignal('v');

                /** @var Signal $viewportRows */ $viewportRows = $ctx->getSignal('vrows');

                /** @var Signal $viewportCols */ $viewportCols = $ctx->getSignal('vcols');
                $target = trim($jumpTarget->string());
                $jumpTarget->setValue('', broadcast: false);
                if (!preg_match('/^([A-Za-z]+)(\d+)$/i', $target, $matches)) {
                    $ctx->sync();

                    return;
                }
                $col = self::colNameToIndex($matches[1]);
                $row = max(0, (int) $matches[2] - 1);

                $focusRow->setValue($row, broadcast: false);
                $focusCol->setValue($col, broadcast: false);

                $vr = max(0, $row - intdiv($viewportRows->int(), 2));
                $vc = max(0, $col - intdiv($viewportCols->int(), 2));
                $viewRow->setValue($vr, broadcast: false);
                $viewCol->setValue($vc, broadcast: false);

                self::$cursors[$contextId] = ['row' => $row, 'col' => $col, 'hue' => self::hueForSession($sessionId)];
                self::$selections[$contextId] = ['r1' => $row, 'c1' => $col, 'r2' => $row, 'c2' => $col];

                $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
                $app->broadcast(self::SCOPE);
            }, 'jumpTo');

            $c->action(function (Context $ctx) use ($app, $contextId): void {
                /** @var Signal $focusRow */ $focusRow = $ctx->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $ctx->getSignal('focusCol');

                /** @var Signal $editing */ $editing = $ctx->getSignal('editing');

                /** @var Signal $version */ $version = $ctx->getSignal('v');
                if ($editing->bool()) {
                    return;
                }
                $sel = self::$selections[$contextId] ?? ['r1' => $focusRow->int(), 'c1' => $focusCol->int(), 'r2' => $focusRow->int(), 'c2' => $focusCol->int()];
                $r1 = min($sel['r1'], $sel['r2']);
                $r2 = max($sel['r1'], $sel['r2']);
                $c1 = min($sel['c1'], $sel['c2']);
                $c2 = max($sel['c1'], $sel['c2']);

                // If no multi-cell selection, clear just the focused cell
                if ($r1 === $r2 && $c1 === $c2) {
                    self::setCell($focusRow->int(), $focusCol->int(), '');
                } else {
                    $cells = [];
                    for ($r = $r1; $r <= $r2; ++$r) {
                        for ($c = $c1; $c <= $c2; ++$c) {
                            $cells[] = ['row' => $r, 'col' => $c, 'value' => ''];
                        }
                    }
                    self::setCells($cells);
                }

                $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
                $app->broadcast(self::SCOPE);
            }, 'clearCells');

            // -- View: raw PHP on the SSE update hot path, Twig only on initial page load --

            $c->view(function (bool $isUpdate) use ($c, $sessionId, $contextId, $app): string {
                /** @var Signal $viewRow */ $viewRow = $c->getSignal('viewRow');

                /** @var Signal $viewCol */ $viewCol = $c->getSignal('viewCol');

                /** @var Signal $focusRow */ $focusRow = $c->getSignal('focusRow');

                /** @var Signal $focusCol */ $focusCol = $c->getSignal('focusCol');

                /** @var Signal $editing */ $editing = $c->getSignal('editing');

                /** @var Signal $editValue */ $editValue = $c->getSignal('editValue');

                /** @var Signal $targetRow */ $targetRow = $c->getSignal('tr');

                /** @var Signal $targetCol */ $targetCol = $c->getSignal('tc');

                /** @var Signal $key */ $key = $c->getSignal('key');

                /** @var Signal $shift */ $shift = $c->getSignal('shift');

                /** @var Signal $scrollDr */ $scrollDr = $c->getSignal('dr');

                /** @var Signal $scrollDc */ $scrollDc = $c->getSignal('dc');

                /** @var Signal $scrollToRow */ $scrollToRow = $c->getSignal('str');

                /** @var Signal $scrollToCol */ $scrollToCol = $c->getSignal('stc');

                /** @var Signal $pasted */ $pasted = $c->getSignal('pasted');

                /** @var Signal $viewportRows */ $viewportRows = $c->getSignal('vrows');

                /** @var Signal $viewportCols */ $viewportCols = $c->getSignal('vcols');

                /** @var Signal $jumpTarget */ $jumpTarget = $c->getSignal('jump');

                /** @var Action $focusCell */ $focusCell = $c->getAction('focusCell');

                /** @var Action $navigate */ $navigate = $c->getAction('navigate');

                /** @var Action $startEdit */ $startEdit = $c->getAction('startEdit');

                /** @var Action $commitEdit */ $commitEdit = $c->getAction('commitEdit');

                /** @var Action $scroll */ $scroll = $c->getAction('scroll');

                /** @var Action $scrollTo */ $scrollTo = $c->getAction('scrollTo');

                /** @var Action $paste */ $paste = $c->getAction('paste');

                /** @var Action $getCopyData */ $getCopyData = $c->getAction('getCopyData');

                /** @var Action $clearCells */ $clearCells = $c->getAction('clearCells');

                /** @var Action $resize */ $resize = $c->getAction('resize');

                /** @var Action $jumpTo */ $jumpTo = $c->getAction('jumpTo');
                $vr = $viewRow->int();
                $vc = $viewCol->int();
                $fr = $focusRow->int();
                $fc = $focusCol->int();
                $isEditing = $editing->bool();

                $vpRows = $viewportRows->int();
                $vpCols = $viewportCols->int();

                $cells = self::getCellRange($vr, $vc, $vpRows, $vpCols);

                // Build other cursors visible in viewport
                $otherCursors = [];
                foreach (self::$cursors as $cid => $cursor) {
                    if ($cid === $contextId) {
                        continue;
                    }
                    $cr = $cursor['row'];
                    $cc = $cursor['col'];
                    if ($cr >= $vr && $cr < $vr + $vpRows
                        && $cc >= $vc && $cc < $vc + $vpCols) {
                        $otherCursors[$cr . ':' . $cc] = $cursor['hue'];
                    }
                }

                // Selection range (server-side, per context)
                $sel = self::$selections[$contextId] ?? ['r1' => -1, 'c1' => -1, 'r2' => -1, 'c2' => -1];
                $sr1 = min($sel['r1'], $sel['r2']);
                $sr2 = max($sel['r1'], $sel['r2']);
                $sc1 = min($sel['c1'], $sel['c2']);
                $sc2 = max($sel['c1'], $sel['c2']);

                $extent = self::getGridExtent($fr, $fc);

                $d = [
                    'vr' => $vr,
                    'vc' => $vc,
                    'fr' => $fr,
                    'fc' => $fc,
                    'isEditing' => $isEditing,
                    'editingId' => $editing->id(),
                    'editValueId' => $editValue->id(),
                    'trId' => $targetRow->id(),
                    'tcId' => $targetCol->id(),
                    'keyId' => $key->id(),
                    'shiftId' => $shift->id(),
                    'drId' => $scrollDr->id(),
                    'dcId' => $scrollDc->id(),
                    'pastedId' => $pasted->id(),
                    'cells' => $cells,
                    'otherCursors' => $otherCursors,
                    'focusedCellValue' => $cells[$fr . ':' . $fc] ?? '',
                    'selRange' => ['r1' => $sr1, 'c1' => $sc1, 'r2' => $sr2, 'c2' => $sc2],
                    'viewportRows' => $vpRows,
                    'viewportCols' => $vpCols,
                    'viewportRowsId' => $viewportRows->id(),
                    'viewportColsId' => $viewportCols->id(),
                    'resizeUrl' => $resize->url(),
                    'jumpId' => $jumpTarget->id(),
                    'jumpUrl' => $jumpTo->url(),
                    'focusCellUrl' => $focusCell->url(),
                    'navigateUrl' => $navigate->url(),
                    'startEditUrl' => $startEdit->url(),
                    'commitEditUrl' => $commitEdit->url(),
                    'scrollUrl' => $scroll->url(),
                    'scrollToUrl' => $scrollTo->url(),
                    'scrollToRowId' => $scrollToRow->id(),
                    'scrollToColId' => $scrollToCol->id(),
                    'maxRow' => $extent['maxRow'],
                    'maxCol' => $extent['maxCol'],
                    'pasteUrl' => $paste->url(),
                    'getCopyDataUrl' => $getCopyData->url(),
                    'clearCellsUrl' => $clearCells->url(),
                    'colNames' => array_map(fn (int $i) => self::colName($vc + $i), range(0, $vpCols - 1)),
                    'myHue' => self::hueForSession($sessionId),
                    'clientCount' => \count($app->getContextsByScope(self::SCOPE)),
                    'title' => '📊 Spreadsheet',
                    'description' => 'Collaborative spreadsheet with SQLite persistence, virtual scrolling, and multi-user cursors.',
                    'summary' => [
                        '<strong>SQLite persistence</strong> — cell values survive server restarts. The database stores only non-empty cells, making the grid effectively infinite in both directions.',
                        '<strong>Virtual scrolling</strong> — only the visible cells are rendered at a time. Arrow keys, Page Up/Down, Home, mouse wheel, and Tab navigate the viewport. The server fetches only the visible range from SQLite on every render.',
                        '<strong>Dynamic resize</strong> — drag the bottom-right handle to make the grid any size. A <code>ResizeObserver</code> dispatches a throttled event; the browser computes how many rows and columns fit, writes them into signals, and posts to a <code>resize</code> action that re-renders exactly the right number of cells.',
                        '<strong>Jump to coordinate</strong> — type a cell reference like <code>AB2000</code> into the toolbar input and press Enter. The server parses column letters and row number, centers the viewport, and moves the cursor in one round-trip.',
                        '<strong>Collaborative cursors</strong> — each user gets a hue derived from their session ID. Other users\' focused cells show a colored border in real time, broadcast via a custom <code>example:spreadsheet</code> scope.',
                        '<strong>Copy & paste</strong> — Ctrl+C copies the selected range as tab-separated values to the clipboard. Ctrl+V pastes TSV from the clipboard starting at the focused cell, compatible with Google Sheets and Excel.',
                        '<strong>Keyboard-first UX</strong> — a single <code>data-on:keydown__window</code> handler covers arrows, Tab, Enter, Escape, F2, Delete, Ctrl+C, and printable-character-to-edit, with an explicit guard so the jump input is never intercepted.',
                        '<strong>Scope design</strong> — viewport position and editing state are TAB-scoped (private per tab). Cell data and cursor positions use a custom <code>example:spreadsheet</code> scope, so every connected user sees live updates without leaking private state.',
                        '<strong>Raw PHP rendering</strong> — SSE update hot path uses plain PHP string building instead of Twig. Bypassing the template engine on every broadcast yields a 3–4× throughput increase under concurrent load.',
                    ],
                    'anatomy' => [
                        'signals' => [
                            ['name' => 'viewRow / viewCol', 'type' => 'int', 'scope' => 'TAB', 'default' => '0', 'desc' => 'Top-left corner of the visible viewport. Private per tab.'],
                            ['name' => 'focusRow / focusCol', 'type' => 'int', 'scope' => 'TAB', 'default' => '0', 'desc' => 'Currently focused cell coordinates.'],
                            ['name' => 'editing', 'type' => 'bool', 'scope' => 'TAB', 'default' => 'false', 'desc' => 'Whether the focused cell is in edit mode.'],
                            ['name' => 'editValue', 'type' => 'string', 'scope' => 'TAB', 'default' => '\"\"', 'desc' => 'Current cell editor input value.'],
                            ['name' => 'Navigation params', 'type' => 'mixed', 'scope' => 'TAB', 'desc' => 'tr, tc, key, shift, dr, dc, pasted — client-writable action parameters for keyboard and mouse events.'],
                            ['name' => 'vrows / vcols', 'type' => 'int', 'scope' => 'TAB', 'default' => '20×10', 'desc' => 'Dynamic viewport dimensions written by a client-side ResizeObserver.'],
                            ['name' => 'version', 'type' => 'int', 'scope' => 'Custom', 'desc' => 'Shared scope version counter. Bumped on every cursor/edit change to trigger broadcasts.'],
                        ],
                        'actions' => [
                            ['name' => 'focusCell', 'desc' => 'Moves cursor to a cell. Commits pending edits, updates selection, broadcasts cursor position.'],
                            ['name' => 'navigate', 'desc' => 'Keyboard navigation — arrows, Tab, Enter, Escape, PageUp/Down, Home. Auto-scrolls viewport.'],
                            ['name' => 'startEdit', 'desc' => 'Enters edit mode on the focused cell. Prefills with typed character or current value.'],
                            ['name' => 'commitEdit', 'desc' => 'Writes the edit value to SQLite and broadcasts the change.'],
                            ['name' => 'scroll', 'desc' => 'Mouse wheel scrolling — moves viewport by delta rows/columns.'],
                            ['name' => 'scrollTo', 'desc' => 'Absolute viewport positioning — used by scrollbar drag and track clicks.'],
                            ['name' => 'clearCells', 'desc' => 'Deletes the selected range of cells.'],
                            ['name' => 'paste', 'desc' => 'Pastes TSV clipboard data starting at the focused cell. Compatible with Excel/Sheets.'],
                            ['name' => 'jumpTo', 'desc' => 'Parses a cell reference like AB2000, centers viewport, and moves cursor.'],
                        ],
                        'views' => [
                            ['name' => 'spreadsheet.html.twig', 'desc' => 'Outer shell rendered on initial page load only. SSE updates are generated with raw PHP string building, yielding a 3–4× throughput improvement over Twig rendering on the hot path.'],
                        ],
                    ],
                    'githubLinks' => [
                        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/SpreadsheetExample.php'],
                        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/spreadsheet.html.twig'],
                    ],
                ];

                if ($isUpdate) {
                    return self::renderDynamic($d);
                }

                return $c->render('examples/spreadsheet.html.twig', array_merge($d, [
                    'initialDynamic' => self::renderDynamic($d),
                ]));
            }, cacheUpdates: false);
        });
    }

    /**
     * Render the dynamic inner content (<div id="ss-dynamic">) using raw PHP.
     * This is the SSE update hot path — no Twig involved.
     *
     * @param array<string, mixed> $d
     */
    private static function renderDynamic(array $d): string {
        $vr = (int) $d['vr'];
        $vc = (int) $d['vc'];
        $fr = (int) $d['fr'];
        $fc = (int) $d['fc'];
        $maxRow = (int) $d['maxRow'];
        $maxCol = (int) $d['maxCol'];
        $vpRows = (int) $d['viewportRows'];
        $vpCols = (int) $d['viewportCols'];

        /** @var array<string, string> $cells */
        $cells = $d['cells'];

        /** @var array<string, int> $otherCursors */
        $otherCursors = $d['otherCursors'];

        /** @var array{r1: int, c1: int, r2: int, c2: int} $sel */
        $sel = $d['selRange'];
        $myHue = (int) $d['myHue'];
        $isEditing = (bool) $d['isEditing'];
        $clientCount = (int) $d['clientCount'];

        /** @var array<int, string> $colNames */
        $colNames = $d['colNames'];
        $focusedCellValue = (string) $d['focusedCellValue'];
        $editValueId = (string) $d['editValueId'];
        $jumpId = (string) $d['jumpId'];
        $jumpUrl = (string) $d['jumpUrl'];
        $trId = (string) $d['trId'];
        $tcId = (string) $d['tcId'];
        $shiftId = (string) $d['shiftId'];
        $editingId = (string) $d['editingId'];
        $focusCellUrl = (string) $d['focusCellUrl'];
        $startEditUrl = (string) $d['startEditUrl'];
        $scrollToUrl = (string) $d['scrollToUrl'];
        $scrollToRowId = (string) $d['scrollToRowId'];
        $scrollToColId = (string) $d['scrollToColId'];

        // Scroll geometry
        $vScrollRange = $maxRow - $vpRows;
        $hScrollRange = $maxCol - $vpCols;
        $vThumbPct = $maxRow > 0 ? ($vpRows / $maxRow * 100) : 100.0;
        $vPosPct = $vScrollRange > 0 ? ($vr / $vScrollRange * (100 - $vThumbPct)) : 0.0;
        $hThumbPct = $maxCol > 0 ? ($vpCols / $maxCol * 100) : 100.0;
        $hPosPct = $hScrollRange > 0 ? ($vc / $hScrollRange * (100 - $hThumbPct)) : 0.0;
        $vMax = max(0, $vScrollRange);
        $hMax = max(0, $hScrollRange);

        // Focused column name within the visible range
        $focusOffset = $fc - $vc;
        $focusedColName = ($focusOffset >= 0 && isset($colNames[$focusOffset])) ? $colNames[$focusOffset] : '';
        $colDisplay = $focusedColName !== '' ? $focusedColName : (string) ($fc + 1);

        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // --- Toolbar ---
        $out = '<div id="ss-dynamic">';
        $out .= '<div id="ss-toolbar" class="ss-toolbar" data-ref="ssToolbar">';
        $out .= '<div class="ss-cell-ref">' . $h($focusedColName) . ($fr + 1) . '</div>';
        $out .= '<label class="ss-jump-label" for="ss-jump-input">Jump to</label>';
        $out .= '<input type="text" id="ss-jump-input" class="ss-jump-input"'
            . ' data-bind="' . $jumpId . '"'
            . ' placeholder="B5"'
            . ' data-on:keydown="if (evt.key === \'Enter\') { evt.preventDefault(); @post(\'' . $jumpUrl . '\') }"'
            . ' data-on:blur="$' . $jumpId . ' !== \'\' && @post(\'' . $jumpUrl . '\')"'
            . '>';
        $out .= '<div class="ss-formula-bar">' . $h($focusedCellValue) . '</div>';
        $out .= '<div class="ss-users">👥 ' . $clientCount . ' connected</div>';
        $out .= '<div class="ss-pos">Row ' . ($fr + 1) . ', Col ' . $h($colDisplay)
            . ' · Viewing from ' . $h(($colNames[0] ?? 'A') . ($vr + 1)) . '</div>';
        $out .= '</div>';

        // --- Grid ---
        $out .= '<div class="ss-body"><div class="ss-grid-wrapper">';
        $out .= '<table id="ss-grid-table" class="ss-grid">';

        // thead
        $out .= '<thead id="ss-thead" data-ref="ssThead"><tr><th class="ss-row-header">&nbsp;</th>';
        foreach ($colNames as $ci => $colName) {
            $absCol = $vc + $ci;
            $isSelHeader = $absCol >= $sel['c1'] && $absCol <= $sel['c2'] && $sel['r1'] >= 0;
            $out .= '<th class="ss-col-header' . ($isSelHeader ? ' ss-sel-header' : '') . '">' . $h($colName) . '</th>';
        }
        $out .= '</tr></thead>';

        // tbody
        $out .= '<tbody id="ss-tbody"'
            . ' data-on:click="const td = evt.target.closest(\'td.ss-cell:not(.ss-focused)\'); if (!td) return;'
            . ' $' . $shiftId . ' = evt.shiftKey; $' . $trId . ' = +td.dataset.row; $' . $tcId . ' = +td.dataset.col;'
            . ' @post(\'' . $focusCellUrl . '\')"'
            . ' data-on:dblclick="evt.target.closest(\'td.ss-cell\') && @post(\'' . $startEditUrl . '\')"'
            . '>';

        for ($ri = 0; $ri < $vpRows; ++$ri) {
            $absRow = $vr + $ri;
            $isRowSelHeader = $absRow >= $sel['r1'] && $absRow <= $sel['r2'] && $sel['c1'] >= 0;
            $out .= '<tr><th class="ss-row-header' . ($isRowSelHeader ? ' ss-sel-header' : '') . '">' . ($absRow + 1) . '</th>';

            for ($ci = 0; $ci < $vpCols; ++$ci) {
                $absCol = $vc + $ci;
                $cellKey = $absRow . ':' . $absCol;
                $isFocused = ($absRow === $fr && $absCol === $fc);
                $hasMultiSel = $sel['r1'] >= 0 && ($sel['r1'] !== $sel['r2'] || $sel['c1'] !== $sel['c2']);
                $isSelected = $hasMultiSel
                    && $absRow >= $sel['r1'] && $absRow <= $sel['r2']
                    && $absCol >= $sel['c1'] && $absCol <= $sel['c2'];
                $otherHue = $otherCursors[$cellKey] ?? null;

                $cls = 'ss-cell';
                if ($isFocused) {
                    $cls .= ' ss-focused';
                }
                if ($isSelected) {
                    $cls .= ' ss-selected';
                }
                if ($otherHue !== null) {
                    $cls .= ' ss-other-cursor';
                }

                $style = '';
                if ($isFocused) {
                    $style = ' style="outline:2px solid hsl(' . $myHue . ',70%,50%);outline-offset:-1px;"';
                } elseif ($otherHue !== null) {
                    $style = ' style="box-shadow:inset 0 0 0 2px hsl(' . $otherHue . ',70%,50%);"';
                }

                $out .= '<td class="' . $cls . '" data-row="' . $absRow . '" data-col="' . $absCol . '"' . $style . '>';
                if ($isFocused && $isEditing) {
                    $out .= '<input type="text" data-init="el.focus()" id="ss-active-input" class="ss-edit-input" data-bind="' . $editValueId . '">';
                } else {
                    $out .= '<span class="ss-cell-text">' . $h($cells[$cellKey] ?? '') . '</span>';
                }
                $out .= '</td>';
            }
            $out .= '</tr>';
        }

        $out .= '</tbody></table></div>';

        // Vertical scrollbar (inside ss-body, next to the grid)
        $out .= '<div class="ss-scrollbar ss-scrollbar-v"'
            . ' data-on:mousedown__prevent="'
            . 'const rect = $ssTrackV.getBoundingClientRect();'
            . ' if (evt.target === $ssThumbV || $ssThumbV.contains(evt.target)) {'
            . ' el.closest(\'#spreadsheet\').__drag = \'v\'; document.body.style.userSelect = \'none\'; }'
            . ' else { $' . $scrollToRowId . ' = Math.round(Math.max(0, Math.min(1, (evt.clientY - rect.top) / rect.height)) * ' . $vMax . '); @post(\'' . $scrollToUrl . '\'); }"'
            . ' data-on:touchstart__prevent="'
            . 'const rect = $ssTrackV.getBoundingClientRect(); const t = evt.touches[0];'
            . ' if (evt.target === $ssThumbV || $ssThumbV.contains(evt.target)) {'
            . ' el.closest(\'#spreadsheet\').__drag = \'v\'; }'
            . ' else { $' . $scrollToRowId . ' = Math.round(Math.max(0, Math.min(1, (t.clientY - rect.top) / rect.height)) * ' . $vMax . '); @post(\'' . $scrollToUrl . '\'); }"'
            . '>';
        $out .= '<div class="ss-scrollbar-track" data-ref="ssTrackV">';
        $out .= '<div id="ss-vthumb" class="ss-scrollbar-thumb" data-ref="ssThumbV"'
            . ' style="top:' . number_format($vPosPct, 2) . '%;height:' . number_format($vThumbPct, 2) . '%">'
            . '</div>';
        $out .= '</div></div>'; // track + scrollbar-v

        $out .= '</div>'; // ss-body

        // Horizontal scrollbar row
        $out .= '<div class="ss-scrollbar-bottom">';
        $out .= '<div class="ss-scrollbar ss-scrollbar-h"'
            . ' data-on:mousedown__prevent="'
            . 'const rect = $ssTrackH.getBoundingClientRect();'
            . ' if (evt.target === $ssThumbH || $ssThumbH.contains(evt.target)) {'
            . ' el.closest(\'#spreadsheet\').__drag = \'h\'; document.body.style.userSelect = \'none\'; }'
            . ' else { $' . $scrollToColId . ' = Math.round(Math.max(0, Math.min(1, (evt.clientX - rect.left) / rect.width)) * ' . $hMax . '); @post(\'' . $scrollToUrl . '\'); }"'
            . ' data-on:touchstart__prevent="'
            . 'const rect = $ssTrackH.getBoundingClientRect(); const t = evt.touches[0];'
            . ' if (evt.target === $ssThumbH || $ssThumbH.contains(evt.target)) {'
            . ' el.closest(\'#spreadsheet\').__drag = \'h\'; }'
            . ' else { $' . $scrollToColId . ' = Math.round(Math.max(0, Math.min(1, (t.clientX - rect.left) / rect.width)) * ' . $hMax . '); @post(\'' . $scrollToUrl . '\'); }"'
            . '>';
        $out .= '<div class="ss-scrollbar-track" data-ref="ssTrackH">';
        $out .= '<div id="ss-hthumb" class="ss-scrollbar-thumb" data-ref="ssThumbH"'
            . ' style="left:' . number_format($hPosPct, 2) . '%;width:' . number_format($hThumbPct, 2) . '%">'
            . '</div>';
        $out .= '</div></div>'; // track + scrollbar-h
        $out .= '<div class="ss-scrollbar-corner"></div>';
        $out .= '</div>'; // ss-scrollbar-bottom

        $out .= '</div>'; // ss-dynamic

        return $out;
    }

    /**
     * Time a DB operation as a `db.*` span when the Dev Bar tracer is active.
     *
     * These helpers are static and have no Context, so they reach the ambient
     * tracer directly instead of going through $c->span(). Zero overhead when
     * tracing is off.
     *
     * @template T
     *
     * @param callable(): T        $fn
     * @param array<string, mixed> $attributes
     *
     * @return T
     */
    private static function traced(string $name, callable $fn, array $attributes = []): mixed {
        $tracer = Tracer::current();

        return $tracer === null ? $fn() : $tracer->span($name, $fn, $attributes);
    }

    private static function db(): \SQLite3 {
        if (self::$db === null) {
            self::$db = new \SQLite3(__DIR__ . '/../../spreadsheet.db');
            self::$db->exec('PRAGMA journal_mode=WAL');
            self::$db->exec('PRAGMA synchronous=NORMAL');
            self::$db->exec(
                'CREATE TABLE IF NOT EXISTS cells (
                    row INTEGER NOT NULL,
                    col INTEGER NOT NULL,
                    value TEXT NOT NULL DEFAULT \'\',
                    PRIMARY KEY (row, col)
                )'
            );
        }

        return self::$db;
    }

    private static function getCell(int $row, int $col): string {
        return self::traced('db.get_cell', static function () use ($row, $col): string {
            $stmt = self::db()->prepare('SELECT value FROM cells WHERE row = :row AND col = :col');
            $stmt->bindValue(':row', $row, SQLITE3_INTEGER);
            $stmt->bindValue(':col', $col, SQLITE3_INTEGER);
            $result = $stmt->execute();
            $data = $result->fetchArray(SQLITE3_ASSOC);

            return $data !== false ? (string) $data['value'] : '';
        }, ['row' => $row, 'col' => $col]);
    }

    /**
     * @return array<string, string> "row:col" => value
     */
    private static function getCellRange(int $startRow, int $startCol, int $rows, int $cols): array {
        return self::traced('db.get_cell_range', static function () use ($startRow, $startCol, $rows, $cols): array {
            $cells = [];
            $stmt = self::db()->prepare(
                'SELECT row, col, value FROM cells
                 WHERE row >= :startRow AND row < :endRow
                 AND col >= :startCol AND col < :endCol'
            );
            $stmt->bindValue(':startRow', $startRow, SQLITE3_INTEGER);
            $stmt->bindValue(':endRow', $startRow + $rows, SQLITE3_INTEGER);
            $stmt->bindValue(':startCol', $startCol, SQLITE3_INTEGER);
            $stmt->bindValue(':endCol', $startCol + $cols, SQLITE3_INTEGER);
            $result = $stmt->execute();

            while ($data = $result->fetchArray(SQLITE3_ASSOC)) {
                $cells[$data['row'] . ':' . $data['col']] = (string) $data['value'];
            }

            return $cells;
        }, ['startRow' => $startRow, 'startCol' => $startCol, 'rows' => $rows, 'cols' => $cols]);
    }

    private static function setCell(int $row, int $col, string $value): void {
        self::traced('db.set_cell', static function () use ($row, $col, $value): void {
            if ($value === '') {
                $stmt = self::db()->prepare('DELETE FROM cells WHERE row = :row AND col = :col');
                $stmt->bindValue(':row', $row, SQLITE3_INTEGER);
                $stmt->bindValue(':col', $col, SQLITE3_INTEGER);
                $stmt->execute();
                // Shrink extent cache if the deleted cell was at the boundary.
                if (self::$extentCache !== null
                    && ($row >= self::$extentCache['maxRow'] || $col >= self::$extentCache['maxCol'])) {
                    self::refreshExtentCache();
                }
            } else {
                $stmt = self::db()->prepare(
                    'INSERT INTO cells (row, col, value) VALUES (:row, :col, :value)
                     ON CONFLICT(row, col) DO UPDATE SET value = excluded.value'
                );
                $stmt->bindValue(':value', $value, SQLITE3_TEXT);
                $stmt->bindValue(':row', $row, SQLITE3_INTEGER);
                $stmt->bindValue(':col', $col, SQLITE3_INTEGER);
                $stmt->execute();
                // Grow extent cache if the new cell exceeds the known boundary.
                if (self::$extentCache !== null) {
                    if ($row > self::$extentCache['maxRow']) {
                        self::$extentCache['maxRow'] = $row;
                    }
                    if ($col > self::$extentCache['maxCol']) {
                        self::$extentCache['maxCol'] = $col;
                    }
                }
            }
        }, ['row' => $row, 'col' => $col, 'op' => $value === '' ? 'delete' : 'upsert']);
    }

    /**
     * Write multiple cells at once (for paste).
     *
     * @param array<int, array{row: int, col: int, value: string}> $cells
     */
    private static function setCells(array $cells): void {
        // The batch is one span; each inner setCell nests as a child, so a paste
        // visibly shows its N writes in the trace waterfall.
        self::traced('db.set_cells', static function () use ($cells): void {
            self::db()->exec('BEGIN');
            foreach ($cells as $cell) {
                self::setCell($cell['row'], $cell['col'], $cell['value']);
            }
            self::db()->exec('COMMIT');
        }, ['count' => \count($cells)]);
    }

    private static function colNameToIndex(string $col): int {
        $col = strtoupper($col);
        $result = 0;
        $len = \strlen($col);
        for ($i = 0; $i < $len; ++$i) {
            $result = $result * 26 + (\ord($col[$i]) - 64);
        }

        return $result - 1; // 0-indexed
    }

    private static function colName(int $col): string {
        $name = '';
        ++$col; // 0-indexed to 1-indexed
        while ($col > 0) {
            --$col;
            $name = \chr(65 + ($col % 26)) . $name;
            $col = intdiv($col, 26);
        }

        return $name;
    }

    private static function hueForSession(string $sessionId): int {
        return hexdec(substr(md5($sessionId), 0, 4)) % 360;
    }

    /**
     * @return array{maxRow: int, maxCol: int}
     */
    private static function getGridExtent(int $focusRow, int $focusCol): array {
        if (self::$extentCache === null) {
            self::refreshExtentCache();
        }

        return [
            'maxRow' => max(self::$extentCache['maxRow'], $focusRow) + 50,
            'maxCol' => max(self::$extentCache['maxCol'], $focusCol) + 10,
        ];
    }

    private static function refreshExtentCache(): void {
        $result = self::traced('db.grid_extent', static fn (): array => (array) self::db()->querySingle(
            'SELECT COALESCE(MAX(row), 0) AS maxRow, COALESCE(MAX(col), 0) AS maxCol FROM cells',
            true
        ));

        /** @var array{maxRow: int, maxCol: int} $result */
        self::$extentCache = ['maxRow' => (int) $result['maxRow'], 'maxCol' => (int) $result['maxCol']];
    }
}
