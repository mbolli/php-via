<?php

declare(strict_types=1);

namespace PhpVia\Website\Examples;

use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

final class SpreadsheetExample {
    public const string SLUG = 'spreadsheet';

    private const string SCOPE = 'example:spreadsheet';

    /** @var array<string, array{row: int, col: int, hue: int}> contextId => cursor */
    private static array $cursors = [];

    /** @var array<string, array{r1: int, c1: int, r2: int, c2: int}> contextId => selection */
    private static array $selections = [];

    private static ?\SQLite3 $db = null;

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
            $viewRow = $c->signal(0, 'viewRow', Scope::TAB);
            $viewCol = $c->signal(0, 'viewCol', Scope::TAB);
            $focusRow = $c->signal(0, 'focusRow', Scope::TAB);
            $focusCol = $c->signal(0, 'focusCol', Scope::TAB);
            $editing = $c->signal(false, 'editing', Scope::TAB);
            $editValue = $c->signal('', 'editValue', Scope::TAB);

            // Client-writable action parameter signals
            $targetRow = $c->signal(0, 'tr', Scope::TAB);
            $targetCol = $c->signal(0, 'tc', Scope::TAB);
            $key = $c->signal('', 'key', Scope::TAB);
            $shift = $c->signal(false, 'shift', Scope::TAB);
            $scrollDr = $c->signal(0, 'dr', Scope::TAB);
            $scrollDc = $c->signal(0, 'dc', Scope::TAB);
            $pasted = $c->signal('', 'pasted', Scope::TAB);

            // Dynamic viewport dimensions (written by client ResizeObserver)
            $viewportRows = $c->signal(20, 'vrows', Scope::TAB);
            $viewportCols = $c->signal(10, 'vcols', Scope::TAB);

            // Jump-to-coordinate input value
            $jumpTarget = $c->signal('', 'jump', Scope::TAB);

            // Scope-level version counter: bumped on every cursor/edit change
            // so that broadcast() has a changed signal to deliver, triggering re-renders
            $version = $c->signal(0, 'v', self::SCOPE, autoBroadcast: false);

            // Selection range stored server-side per context (not as signals)
            if (!isset(self::$selections[$contextId])) {
                self::$selections[$contextId] = ['r1' => -1, 'c1' => -1, 'r2' => -1, 'c2' => -1];
            }

            // -- Actions --

            $focusCell = $c->action(function (Context $ctx) use ($app, $sessionId, $contextId, $focusRow, $focusCol, $editing, $editValue, $version, $targetRow, $targetCol, $shift): void {
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
                $ctx->sync();
                $app->broadcast(self::SCOPE);
            }, 'focusCell');

            $navigate = $c->action(function (Context $ctx) use ($app, $sessionId, $contextId, $viewRow, $viewCol, $focusRow, $focusCol, $editing, $editValue, $version, $key, $shift, $viewportRows, $viewportCols): void {
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
                $ctx->sync();
                $app->broadcast(self::SCOPE);
            }, 'navigate');

            $startEdit = $c->action(function (Context $ctx) use ($focusRow, $focusCol, $editing, $editValue, $key): void {
                $keyVal = $key->string();
                $prefill = mb_strlen($keyVal) === 1 ? $keyVal : '';
                $currentValue = self::getCell($focusRow->int(), $focusCol->int());
                $editing->setValue(true, broadcast: false);
                $editValue->setValue($prefill !== '' ? $prefill : $currentValue, broadcast: false);
                $ctx->sync();
            }, 'startEdit');

            $commitEdit = $c->action(function (Context $ctx) use ($app, $focusRow, $focusCol, $editing, $editValue, $version): void {
                if (!$editing->bool()) {
                    return;
                }
                self::setCell($focusRow->int(), $focusCol->int(), $editValue->string());
                $editing->setValue(false, broadcast: false);
                $editValue->setValue('', broadcast: false);
                $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
                $ctx->sync();
                $app->broadcast(self::SCOPE);
            }, 'commitEdit');

            $scroll = $c->action(function (Context $ctx) use ($viewRow, $viewCol, $scrollDr, $scrollDc): void {
                $viewRow->setValue(max(0, $viewRow->int() + $scrollDr->int()), broadcast: false);
                $viewCol->setValue(max(0, $viewCol->int() + $scrollDc->int()), broadcast: false);
                $ctx->sync();
            }, 'scroll');

            $paste = $c->action(function (Context $ctx) use ($app, $focusRow, $focusCol, $editing, $editValue, $pasted, $version): void {
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
                $ctx->sync();
                $app->broadcast(self::SCOPE);
            }, 'paste');

            $getCopyData = $c->action(function (Context $ctx) use ($contextId): void {
                $sel = self::$selections[$contextId] ?? ['r1' => 0, 'c1' => 0, 'r2' => 0, 'c2' => 0];
                $r1 = min($sel['r1'], $sel['r2']);
                $r2 = max($sel['r1'], $sel['r2']);
                $c1 = min($sel['c1'], $sel['c2']);
                $c2 = max($sel['c1'], $sel['c2']);

                $cells = self::getCellRange($r1, $c1, $r2 - $r1 + 1, $c2 - $c1 + 1);
                $tsv = '';
                for ($r = $r1; $r <= $r2; ++$r) {
                    $row = [];
                    for ($c = $c1; $c <= $c2; ++$c) {
                        $row[] = $cells[$r . ':' . $c] ?? '';
                    }
                    $tsv .= implode("\t", $row) . "\n";
                }

                $tsvJson = json_encode(rtrim($tsv, "\n"));
                $ctx->execScript("navigator.clipboard.writeText({$tsvJson})");
            }, 'getCopyData');

            $resize = $c->action(function (Context $ctx) use ($viewportRows, $viewportCols): void {
                $viewportRows->setValue(max(3, min(100, $viewportRows->int())), broadcast: false);
                $viewportCols->setValue(max(3, min(52, $viewportCols->int())), broadcast: false);
                $ctx->sync();
            }, 'resize');

            $jumpTo = $c->action(function (Context $ctx) use ($app, $sessionId, $contextId, $viewRow, $viewCol, $focusRow, $focusCol, $jumpTarget, $version, $viewportRows, $viewportCols): void {
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
                $ctx->sync();
                $app->broadcast(self::SCOPE);
            }, 'jumpTo');

            $clearCells = $c->action(function (Context $ctx) use ($app, $contextId, $focusRow, $focusCol, $editing, $version): void {
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
                $ctx->sync();
                $app->broadcast(self::SCOPE);
            }, 'clearCells');

            // -- Render --

            $c->view(function () use (
                $c,
                $sessionId,
                $contextId,
                $viewRow,
                $viewCol,
                $focusRow,
                $focusCol,
                $editing,
                $editValue,
                $targetRow,
                $targetCol,
                $key,
                $shift,
                $scrollDr,
                $scrollDc,
                $pasted,
                $viewportRows,
                $viewportCols,
                $jumpTarget,
                $focusCell,
                $navigate,
                $startEdit,
                $commitEdit,
                $scroll,
                $paste,
                $getCopyData,
                $clearCells,
                $resize,
                $jumpTo,
                $app,
            ): string {
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

                return $c->render('examples/spreadsheet.html.twig', [
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
                            ['name' => 'clearCells', 'desc' => 'Deletes the selected range of cells.'],
                            ['name' => 'paste', 'desc' => 'Pastes TSV clipboard data starting at the focused cell. Compatible with Excel/Sheets.'],
                            ['name' => 'jumpTo', 'desc' => 'Parses a cell reference like AB2000, centers viewport, and moves cursor.'],
                        ],
                        'views' => [
                            ['name' => 'spreadsheet.html.twig', 'desc' => 'Virtual viewport rendering with collaborative multi-user cursors. Keyboard-first UX with a single data-on:keydown handler. Only visible cells are rendered.'],
                        ],
                    ],
                    'githubLinks' => [
                        ['label' => 'View handler', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/src/Examples/SpreadsheetExample.php'],
                        ['label' => 'View template', 'url' => 'https://github.com/mbolli/php-via/blob/master/website/templates/examples/spreadsheet.html.twig'],
                    ],
                    'vr' => $vr,
                    'vc' => $vc,
                    'fr' => $fr,
                    'fc' => $fc,
                    'isEditing' => $isEditing,
                    'editingId' => $editing->id(),
                    'editValueId' => $editValue->id(),
                    'editValueVal' => $editValue->string(),
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
                    'pasteUrl' => $paste->url(),
                    'getCopyDataUrl' => $getCopyData->url(),
                    'clearCellsUrl' => $clearCells->url(),
                    'colNames' => array_map(fn (int $i) => self::colName($vc + $i), range(0, $vpCols - 1)),
                    'myHue' => self::hueForSession($sessionId),
                    'clientCount' => \count($app->getClients()),
                ]);
            }, block: 'demo', cacheUpdates: false);
        });
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
        $stmt = self::db()->prepare('SELECT value FROM cells WHERE row = :row AND col = :col');
        $stmt->bindValue(':row', $row, SQLITE3_INTEGER);
        $stmt->bindValue(':col', $col, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $data = $result->fetchArray(SQLITE3_ASSOC);

        return $data !== false ? (string) $data['value'] : '';
    }

    /**
     * @return array<string, string> "row:col" => value
     */
    private static function getCellRange(int $startRow, int $startCol, int $rows, int $cols): array {
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
    }

    private static function setCell(int $row, int $col, string $value): void {
        if ($value === '') {
            $stmt = self::db()->prepare('DELETE FROM cells WHERE row = :row AND col = :col');
        } else {
            $stmt = self::db()->prepare(
                'INSERT INTO cells (row, col, value) VALUES (:row, :col, :value)
                 ON CONFLICT(row, col) DO UPDATE SET value = excluded.value'
            );
            $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        }
        $stmt->bindValue(':row', $row, SQLITE3_INTEGER);
        $stmt->bindValue(':col', $col, SQLITE3_INTEGER);
        $stmt->execute();
    }

    /**
     * Write multiple cells at once (for paste).
     *
     * @param array<int, array{row: int, col: int, value: string}> $cells
     */
    private static function setCells(array $cells): void {
        self::db()->exec('BEGIN');
        foreach ($cells as $cell) {
            self::setCell($cell['row'], $cell['col'], $cell['value']);
        }
        self::db()->exec('COMMIT');
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
}
