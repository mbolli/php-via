<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\Scope;
use Mbolli\PhpVia\Via;

/*
 * Spreadsheet edits against POSTs the browser sent before the last commit or Escape reached it.
 *
 * editing and editValue are client-writable, so such a POST still says editing=true with the old
 * draft. Focus is server-owned and has already moved, so committing that draft would write it
 * into the cell the commit moved to.
 */

$ssEditAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$ssEditReady = false;

if (is_file($ssEditAutoload)) {
    require_once $ssEditAutoload;
    $ssEditReady = class_exists('PhpVia\\Website\\Examples\\SpreadsheetExample');
}

if (!$ssEditReady) {
    test('spreadsheet stale edits (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

const SS_EDIT_ROUTE = '/examples/spreadsheet';

function ssEditStatic(string $prop, mixed $value): void {
    (new ReflectionProperty('PhpVia\\Website\\Examples\\SpreadsheetExample', $prop))->setValue(null, $value);
}

function ssEditCell(int $row, int $col): string {
    return (new ReflectionMethod('PhpVia\\Website\\Examples\\SpreadsheetExample', 'getCell'))->invoke(null, $row, $col);
}

function ssEditSetCell(int $row, int $col, string $value): void {
    (new ReflectionMethod('PhpVia\\Website\\Examples\\SpreadsheetExample', 'setCell'))->invoke(null, $row, $col, $value);
}

function ssEditApp(): Via {
    $app = new Via(
        (new Config())
            ->withLogLevel('error')
            ->withTemplateDir(dirname(__DIR__, 2) . '/website/templates')
    );
    ('PhpVia\\Website\\Examples\\SpreadsheetExample')::register($app);

    return $app;
}

function ssEditMount(Via $app, string $id): Context {
    $ctx = new Context($id, SS_EDIT_ROUTE, $app, null, 'sess_edit');
    $app->contexts[$id] = $ctx;
    $app->getApp()->registerContext($ctx);
    $app->getApp()->setContextSession($id, 'sess_edit');
    $app->registerContextInScope($ctx, Scope::TAB);
    $app->invokeHandlerWithParams($app->getRouter()->getRoutes()[SS_EDIT_ROUTE], $ctx, []);

    return $ctx;
}

/**
 * Run an action with the signal values the browser posts, by signal name.
 *
 * @param array<string, mixed> $posted
 */
function ssEditPost(Context $ctx, string $action, array $posted): void {
    $signals = [];
    foreach ($posted as $name => $value) {
        $signals[$ctx->getSignal($name)->id()] = $value;
    }
    $ctx->injectSignals($signals);
    $ctx->executeAction($ctx->getAction($action)->id());
}

beforeEach(function (): void {
    $db = new SQLite3(':memory:');
    $db->exec('CREATE TABLE cells (row INTEGER NOT NULL, col INTEGER NOT NULL, value TEXT NOT NULL DEFAULT \'\', PRIMARY KEY (row, col))');
    ssEditStatic('db', $db);
    ssEditStatic('rangeCache', []);
    ssEditStatic('extentCache', null);
    ssEditStatic('cursors', []);
    ssEditStatic('selections', []);
    ssEditStatic('positions', []);
    ssEditStatic('openEdits', []);
});

afterEach(function (): void {
    ssEditStatic('db', null);
    ssEditStatic('rangeCache', []);
    ssEditStatic('extentCache', null);
});

test('a key pressed before the commit arrived does not write the draft into the next cell', function (): void {
    $ctx = ssEditMount(ssEditApp(), SS_EDIT_ROUTE . '_/edit1');
    ssEditSetCell(1, 0, 'keep');

    ssEditPost($ctx, 'startEdit', ['key' => 'a']);
    ssEditPost($ctx, 'navigate', ['key' => 'Enter', 'editing' => true, 'editValue' => 'abc']);
    ssEditPost($ctx, 'navigate', ['key' => 'Tab', 'editing' => true, 'editValue' => 'abc']);

    expect(ssEditCell(0, 0))->toBe('abc');
    expect(ssEditCell(1, 0))->toBe('keep');
});

test('a key pressed before Escape arrived does not commit the discarded draft', function (): void {
    $ctx = ssEditMount(ssEditApp(), SS_EDIT_ROUTE . '_/edit2');
    ssEditSetCell(0, 0, 'orig');

    ssEditPost($ctx, 'startEdit', ['key' => 'x']);
    ssEditPost($ctx, 'navigate', ['key' => 'Escape', 'editing' => true, 'editValue' => 'typed']);
    ssEditPost($ctx, 'navigate', ['key' => 'Tab', 'editing' => true, 'editValue' => 'typed']);

    expect(ssEditCell(0, 0))->toBe('orig');
});

test('a revived tab keeps its open edit only with its saved position', function (bool $positionKept): void {
    $app = ssEditApp();
    $id = SS_EDIT_ROUTE . '_/edit3';
    $ctx = ssEditMount($app, $id);
    ssEditSetCell(0, 0, 'precious');

    ssEditPost($ctx, 'jumpTo', ['jump' => 'K40']);
    ssEditPost($ctx, 'startEdit', ['key' => 'd']);
    $held = [];
    foreach ($ctx->getSignalFactory()->getTabSignals() as $signalId => $signal) {
        $held[$signalId] = $signal->getValue();
    }
    $held[$ctx->getSignal('editValue')->id()] = 'draft';

    $app->getApp()->destroyContext($id);
    unset($app->contexts[$id]);
    if (!$positionKept) {
        ssEditStatic('positions', []);
    }

    $revived = $app->reviveContextFromClient($id, 'sess_edit', $held);
    expect($revived)->not->toBeNull();
    ssEditPost($revived, 'navigate', ['key' => 'Enter', 'editing' => true, 'editValue' => 'draft']);

    expect(ssEditCell(39, 10))->toBe($positionKept ? 'draft' : '');
    expect(ssEditCell(0, 0))->toBe('precious');
})->with(['position kept' => true, 'position lost' => false]);
