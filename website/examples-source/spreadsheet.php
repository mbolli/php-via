<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

$app = new Via(
    (new Config())
        ->withPort(3001)
        ->withDevMode(true)
);

// ─── SQLite setup ────────────────────────────────────────────────────────────

$db = new SQLite3(__DIR__ . '/spreadsheet.db');
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA synchronous=NORMAL');
$db->exec(
    'CREATE TABLE IF NOT EXISTS cells (
        row INTEGER NOT NULL,
        col INTEGER NOT NULL,
        value TEXT NOT NULL DEFAULT \'\',
        PRIMARY KEY (row, col)
    )'
);

$viewportRows = 20;
$viewportCols = 10;
$scope = 'spreadsheet';

/** @var array<string, array{row: int, col: int, hue: int}> contextId => cursor */
$cursors = [];

/** @var array<string, array{r1: int, c1: int, r2: int, c2: int}> contextId => selection */
$selections = [];

function colName(int $col): string {
    $name = '';
    ++$col;
    while ($col > 0) {
        --$col;
        $name = chr(65 + ($col % 26)) . $name;
        $col = intdiv($col, 26);
    }

    return $name;
}

function hueForSession(string $id): int {
    return hexdec(substr(md5($id), 0, 4)) % 360;
}

function getCell(SQLite3 $db, int $row, int $col): string {
    $stmt = $db->prepare('SELECT value FROM cells WHERE row = :row AND col = :col');
    $stmt->bindValue(':row', $row, SQLITE3_INTEGER);
    $stmt->bindValue(':col', $col, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $data = $result->fetchArray(SQLITE3_ASSOC);

    return $data !== false ? (string) $data['value'] : '';
}

/** @return array<string, string> */
function getCellRange(SQLite3 $db, int $startRow, int $startCol, int $rows, int $cols): array {
    $cells = [];
    $stmt = $db->prepare(
        'SELECT row, col, value FROM cells WHERE row >= :sr AND row < :er AND col >= :sc AND col < :ec'
    );
    $stmt->bindValue(':sr', $startRow, SQLITE3_INTEGER);
    $stmt->bindValue(':er', $startRow + $rows, SQLITE3_INTEGER);
    $stmt->bindValue(':sc', $startCol, SQLITE3_INTEGER);
    $stmt->bindValue(':ec', $startCol + $cols, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($data = $result->fetchArray(SQLITE3_ASSOC)) {
        $cells[$data['row'] . ':' . $data['col']] = (string) $data['value'];
    }

    return $cells;
}

function setCell(SQLite3 $db, int $row, int $col, string $value): void {
    if ($value === '') {
        $stmt = $db->prepare('DELETE FROM cells WHERE row = :row AND col = :col');
    } else {
        $stmt = $db->prepare(
            'INSERT INTO cells (row, col, value) VALUES (:row, :col, :value)
             ON CONFLICT(row, col) DO UPDATE SET value = excluded.value'
        );
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
    }
    $stmt->bindValue(':row', $row, SQLITE3_INTEGER);
    $stmt->bindValue(':col', $col, SQLITE3_INTEGER);
    $stmt->execute();
}

/** @param array<int, array{row: int, col: int, value: string}> $cells */
function setCells(SQLite3 $db, array $cells): void {
    $db->exec('BEGIN');
    foreach ($cells as $cell) {
        setCell($db, $cell['row'], $cell['col'], $cell['value']);
    }
    $db->exec('COMMIT');
}

// ─── Page ────────────────────────────────────────────────────────────────────

$app->page('/', function (Context $c) use (
    $app,
    $db,
    &$cursors,
    &$selections,
    $viewportRows,
    $viewportCols,
    $scope
): void {
    $sessionId = $c->getSessionId() ?? $c->getId();
    $contextId = $c->getId();
    $hue = hueForSession($sessionId);

    if (!isset($cursors[$contextId])) {
        $cursors[$contextId] = ['row' => 0, 'col' => 0, 'hue' => $hue];
    }
    if (!isset($selections[$contextId])) {
        $selections[$contextId] = ['r1' => -1, 'c1' => -1, 'r2' => -1, 'c2' => -1];
    }

    $c->onDisconnect(function () use (&$cursors, &$selections, $contextId, $app, $scope): void {
        unset($cursors[$contextId], $selections[$contextId]);
        if ($app->getContextsByScope($scope) !== []) {
            $app->broadcast($scope);
        }
    });

    $c->addScope($scope);

    // TAB-scoped signals
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

    // Scope-level version counter
    $version = $c->signal(0, 'v', $scope, autoBroadcast: false);

    // ── Actions ──

    $focusCell = $c->action(function (Context $ctx) use (
        $app,
        $sessionId,
        $contextId,
        &$cursors,
        &$selections,
        $focusRow,
        $focusCol,
        $editing,
        $editValue,
        $version,
        $targetRow,
        $targetCol,
        $shift,
        $db,
        $scope
    ): void {
        $row = $targetRow->int();
        $col = $targetCol->int();
        $isShift = $shift->bool();

        if ($editing->bool()) {
            setCell($db, $focusRow->int(), $focusCol->int(), $editValue->string());
            $editing->setValue(false, broadcast: false);
            $editValue->setValue('', broadcast: false);
        }

        $focusRow->setValue($row, broadcast: false);
        $focusCol->setValue($col, broadcast: false);

        $sel = &$selections[$contextId];
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

        $cursors[$contextId] = ['row' => $row, 'col' => $col, 'hue' => hueForSession($sessionId)];
        $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
        $ctx->sync();
        $app->broadcast($scope);
    }, 'focusCell');

    $navigate = $c->action(function (Context $ctx) use (
        $app,
        $sessionId,
        $contextId,
        &$cursors,
        &$selections,
        $viewRow,
        $viewCol,
        $focusRow,
        $focusCol,
        $editing,
        $editValue,
        $version,
        $key,
        $shift,
        $db,
        $viewportRows,
        $viewportCols,
        $scope
    ): void {
        $direction = $key->string();
        $isShift = $shift->bool();
        $fr = $focusRow->int();
        $fc = $focusCol->int();

        if ($editing->bool() && $direction !== 'Escape') {
            setCell($db, $fr, $fc, $editValue->string());
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
            'PageUp' => $fr = max(0, $fr - $viewportRows),
            'PageDown' => $fr += $viewportRows,
            'Home' => [$fr, $fc] = [0, 0],
            default => null,
        };

        $focusRow->setValue($fr, broadcast: false);
        $focusCol->setValue($fc, broadcast: false);

        $sel = &$selections[$contextId];
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
        } elseif ($fr >= $vr + $viewportRows) {
            $vr = $fr - $viewportRows + 1;
        }
        if ($fc < $vc) {
            $vc = $fc;
        } elseif ($fc >= $vc + $viewportCols) {
            $vc = $fc - $viewportCols + 1;
        }
        $viewRow->setValue($vr, broadcast: false);
        $viewCol->setValue($vc, broadcast: false);

        $cursors[$contextId] = ['row' => $fr, 'col' => $fc, 'hue' => hueForSession($sessionId)];
        $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
        $ctx->sync();
        $app->broadcast($scope);
    }, 'navigate');

    $startEdit = $c->action(function (Context $ctx) use ($db, $focusRow, $focusCol, $editing, $editValue, $key): void {
        $keyVal = $key->string();
        $prefill = mb_strlen($keyVal) === 1 ? $keyVal : '';
        $currentValue = getCell($db, $focusRow->int(), $focusCol->int());
        $editing->setValue(true, broadcast: false);
        $editValue->setValue($prefill !== '' ? $prefill : $currentValue, broadcast: false);
        $ctx->sync();
    }, 'startEdit');

    $commitEdit = $c->action(function (Context $ctx) use ($app, $db, $focusRow, $focusCol, $editing, $editValue, $version, $scope): void {
        if (!$editing->bool()) {
            return;
        }
        setCell($db, $focusRow->int(), $focusCol->int(), $editValue->string());
        $editing->setValue(false, broadcast: false);
        $editValue->setValue('', broadcast: false);
        $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
        $ctx->sync();
        $app->broadcast($scope);
    }, 'commitEdit');

    $scroll = $c->action(function (Context $ctx) use ($viewRow, $viewCol, $scrollDr, $scrollDc): void {
        $viewRow->setValue(max(0, $viewRow->int() + $scrollDr->int()), broadcast: false);
        $viewCol->setValue(max(0, $viewCol->int() + $scrollDc->int()), broadcast: false);
        $ctx->sync();
    }, 'scroll');

    $paste = $c->action(function (Context $ctx) use ($app, $db, $focusRow, $focusCol, $editing, $editValue, $pasted, $version, $scope): void {
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
            foreach (explode("\t", $row) as $ci => $value) {
                $cells[] = ['row' => $startRow + $ri, 'col' => $startCol + $ci, 'value' => $value];
            }
        }
        setCells($db, $cells);
        $editing->setValue(false, broadcast: false);
        $editValue->setValue('', broadcast: false);
        $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
        $ctx->sync();
        $app->broadcast($scope);
    }, 'paste');

    $getCopyData = $c->action(function (Context $ctx) use (&$selections, $contextId, $db): void {
        $sel = $selections[$contextId];
        $r1 = min($sel['r1'], $sel['r2']);
        $r2 = max($sel['r1'], $sel['r2']);
        $c1 = min($sel['c1'], $sel['c2']);
        $c2 = max($sel['c1'], $sel['c2']);

        $cells = getCellRange($db, $r1, $c1, $r2 - $r1 + 1, $c2 - $c1 + 1);
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

    $clearCells = $c->action(function (Context $ctx) use ($app, $db, &$selections, $contextId, $focusRow, $focusCol, $editing, $version, $scope): void {
        if ($editing->bool()) {
            return;
        }
        $sel = $selections[$contextId];
        $r1 = min($sel['r1'], $sel['r2']);
        $r2 = max($sel['r1'], $sel['r2']);
        $c1 = min($sel['c1'], $sel['c2']);
        $c2 = max($sel['c1'], $sel['c2']);

        if ($r1 === $r2 && $c1 === $c2) {
            setCell($db, $focusRow->int(), $focusCol->int(), '');
        } else {
            $cells = [];
            for ($r = $r1; $r <= $r2; ++$r) {
                for ($c = $c1; $c <= $c2; ++$c) {
                    $cells[] = ['row' => $r, 'col' => $c, 'value' => ''];
                }
            }
            setCells($db, $cells);
        }

        $version->setValue($version->int() + 1, markChanged: true, broadcast: false);
        $ctx->sync();
        $app->broadcast($scope);
    }, 'clearCells');

    // ── View ──

    $c->view(function () use (
        $db,
        &$cursors,
        &$selections,
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
        $focusCell,
        $navigate,
        $startEdit,
        $scroll,
        $paste,
        $getCopyData,
        $clearCells,
        $viewportRows,
        $viewportCols,
        $app
    ): string {
        $vr = $viewRow->int();
        $vc = $viewCol->int();
        $fr = $focusRow->int();
        $fc = $focusCol->int();
        $isEditing = $editing->bool();
        $hue = hueForSession($sessionId);

        $cells = getCellRange($db, $vr, $vc, $viewportRows, $viewportCols);

        // Other cursors in viewport
        $otherStyles = [];
        foreach ($cursors as $cid => $cur) {
            if ($cid === $contextId) {
                continue;
            }
            $cr = $cur['row'];
            $cc = $cur['col'];
            if ($cr >= $vr && $cr < $vr + $viewportRows && $cc >= $vc && $cc < $vc + $viewportCols) {
                $otherStyles[$cr . ':' . $cc] = $cur['hue'];
            }
        }

        // Selection range
        $sel = $selections[$contextId];
        $sr1 = min($sel['r1'], $sel['r2']);
        $sr2 = max($sel['r1'], $sel['r2']);
        $sc1 = min($sel['c1'], $sel['c2']);
        $sc2 = max($sel['c1'], $sel['c2']);
        $hasMultiSel = $sr1 >= 0 && ($sr1 !== $sr2 || $sc1 !== $sc2);

        // Signal IDs for JS expressions
        $keyId = $key->id();
        $shiftId = $shift->id();
        $editingId = $editing->id();
        $editValueId = $editValue->id();
        $trId = $targetRow->id();
        $tcId = $targetCol->id();
        $drId = $scrollDr->id();
        $dcId = $scrollDc->id();
        $pastedId = $pasted->id();

        // Action URLs
        $navUrl = $navigate->url();
        $focusUrl = $focusCell->url();
        $startUrl = $startEdit->url();
        $scrollUrl = $scroll->url();
        $pasteUrl = $paste->url();
        $copyUrl = $getCopyData->url();
        $clearUrl = $clearCells->url();

        // Build column headers
        $colHeaders = '';
        for ($ci = 0; $ci < $viewportCols; ++$ci) {
            $absC = $vc + $ci;
            $selClass = ($absC >= $sc1 && $absC <= $sc2 && $sr1 >= 0) ? ' style="background:#dbeafe;color:#1e40af"' : '';
            $colHeaders .= "<th{$selClass}>" . colName($absC) . '</th>';
        }

        // Build rows
        $tbody = '';
        for ($ri = 0; $ri < $viewportRows; ++$ri) {
            $absRow = $vr + $ri;
            $rowSelClass = ($absRow >= $sr1 && $absRow <= $sr2 && $sc1 >= 0) ? ' style="background:#dbeafe;color:#1e40af"' : '';
            $tbody .= "<tr><th{$rowSelClass}>" . ($absRow + 1) . '</th>';
            for ($ci = 0; $ci < $viewportCols; ++$ci) {
                $absCol = $vc + $ci;
                $ck = $absRow . ':' . $absCol;
                $val = htmlspecialchars($cells[$ck] ?? '', ENT_QUOTES);
                $isFocused = $absRow === $fr && $absCol === $fc;
                $isSelected = $hasMultiSel && $absRow >= $sr1 && $absRow <= $sr2 && $absCol >= $sc1 && $absCol <= $sc2;
                $hasOther = isset($otherStyles[$ck]);

                $cls = 'ss-cell';
                $style = '';
                if ($isFocused) {
                    $cls .= ' ss-focused';
                    $style = "outline:2px solid hsl({$hue},70%,50%);outline-offset:-1px;";
                } elseif ($hasOther) {
                    $h = $otherStyles[$ck];
                    $style = "box-shadow:inset 0 0 0 2px hsl({$h},70%,50%);";
                }
                if ($isSelected) {
                    $cls .= ' ss-selected';
                }

                $attrs = '';
                if (!$isFocused) {
                    $attrs = " data-on:click=\"\${$shiftId}=evt.shiftKey;\${$trId}={$absRow};\${$tcId}={$absCol};@get('{$focusUrl}')\"";
                }
                $attrs .= " data-on:dblclick=\"@get('{$startUrl}')\"";

                if ($isFocused && $isEditing) {
                    $tbody .= "<td class=\"{$cls}\" style=\"position:relative;{$style}\"{$attrs}>"
                        . "<input style=\"position:absolute;inset:0;width:100%;height:100%;border:none;padding:0 4px;font:inherit\" data-bind=\"{$editValueId}\" data-init=\"el.focus()\">"
                        . '</td>';
                } else {
                    $styleAttr = $style ? " style=\"{$style}\"" : '';
                    $tbody .= "<td class=\"{$cls}\"{$styleAttr}{$attrs}><span class=\"ss-cell-text\">{$val}</span></td>";
                }
            }
            $tbody .= '</tr>';
        }

        $cellRef = colName($fc) . ($fr + 1);
        $clientCount = count($app->getClients());

        return <<<HTML
        <div id="content" tabindex="0" style="outline:none"
             data-on:keydown__window="
                \${$keyId}=evt.key;\${$shiftId}=evt.shiftKey;
                const k=evt.key,e=\${$editingId};
                if(e){if(['Tab','Enter','Escape'].includes(k)){@get('{$navUrl}');evt.preventDefault()}return}
                if(['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(k)){@get('{$navUrl}');evt.preventDefault()}
                else if(['Tab','Enter','Escape','PageUp','PageDown','Home'].includes(k)){@get('{$navUrl}');evt.preventDefault()}
                else if(k==='F2'){@get('{$startUrl}');evt.preventDefault()}
                else if(k==='c'&&(evt.ctrlKey||evt.metaKey)){@get('{$copyUrl}')}
                else if(k==='Delete'||k==='Backspace'){@get('{$clearUrl}');evt.preventDefault()}
                else if(k==='v'&&(evt.ctrlKey||evt.metaKey)){/* paste handled by paste event */}
                else if(k.length===1&&!evt.ctrlKey&&!evt.metaKey&&!evt.altKey){@get('{$startUrl}');evt.preventDefault()}
             "
             data-on:keyup__window="\${$keyId}='';\${$shiftId}=false"
             data-on:paste__window="\${$pastedId}=event.clipboardData.getData('text/plain');\${$pastedId}&&@post('{$pasteUrl}');event.preventDefault()"
             data-on:wheel__throttle.150ms="
                \${$drId}=event.deltaY>0?4:event.deltaY<0?-4:0;
                \${$dcId}=event.deltaX>0?4:event.deltaX<0?-4:0;
                if(\${$drId}!==0||\${$dcId}!==0)@get('{$scrollUrl}');
             "
        >
            <h1>📊 Collaborative Spreadsheet</h1>
            <p>{$cellRef} · 👥 {$clientCount} connected</p>
            <div style="overflow:hidden;border:1px solid #ccc;border-radius:4px">
                <table class="ss-grid">
                    <thead><tr><th style="width:36px">&nbsp;</th>{$colHeaders}</tr></thead>
                    <tbody>{$tbody}</tbody>
                </table>
            </div>
            <style>
            .ss-grid{border-collapse:collapse;width:100%;table-layout:fixed;font-size:.85rem;user-select:none}
            .ss-grid th,.ss-grid td{border:1px solid #e0e0e0;padding:0;height:1.75rem;text-align:left;position:relative}
            .ss-grid thead th{background:#f0f0f0;text-align:center;font-weight:500;font-size:.7rem;min-width:80px}
            .ss-grid tbody th{background:#f0f0f0;text-align:center;width:36px;font-size:.7rem}
            .ss-cell{cursor:cell;overflow:hidden;white-space:nowrap;max-width:80px}
            .ss-cell-text{display:block;padding:0 4px;overflow:hidden;text-overflow:ellipsis;line-height:1.75rem}
            .ss-focused{z-index:2}.ss-selected{background:#dbeafe!important}
            </style>
        </div>
        HTML;
    }, cacheUpdates: false);
});

$app->start();
