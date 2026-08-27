<?php

declare(strict_types=1);

/*
 * Regression: SpreadsheetExample::setCells() opened its batch with a DEFERRED
 * transaction (exec('BEGIN')) and then read inside it — setCell()'s delete path
 * calls refreshExtentCache(), which runs a SELECT.
 *
 * A deferred transaction takes its read snapshot at the first statement. If another
 * connection commits before this transaction's first WRITE, the upgrade fails with
 * SQLITE_BUSY_SNAPSHOT. That error bypasses the busy handler entirely, so
 * busy_timeout provides no protection: under sustained contention one writer can be
 * starved indefinitely.
 *
 * BEGIN IMMEDIATE takes the write lock up front, so the read and the write share one
 * snapshot and the upgrade cannot fail.
 *
 * The first test pins the SQLite semantics (deterministic, two handles, no threads).
 * The second guards the example that motivated it.
 */

function txTestDb(string $path): SQLite3 {
    $db = new SQLite3($path);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA synchronous=NORMAL');
    $db->busyTimeout(0);
    // Deterministic failure surface: a locked/BUSY write raises SQLite3Exception
    // instead of a PHP warning that the test runner would escalate.
    $db->enableExceptions(true);

    return $db;
}

beforeEach(function (): void {
    $this->dbPath = sys_get_temp_dir() . '/via_tx_' . bin2hex(random_bytes(6)) . '.db';
    $seed = txTestDb($this->dbPath);
    $seed->exec('CREATE TABLE cells (row INTEGER, col INTEGER, value TEXT, PRIMARY KEY (row, col))');
    $seed->exec("INSERT INTO cells VALUES (0, 0, 'seed')");
    $seed->close();
});

afterEach(function (): void {
    foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
        if (file_exists($f)) {
            @unlink($f);
        }
    }
});

test('deferred BEGIN that reads before writing cannot upgrade once another writer commits', function (): void {
    $a = txTestDb($this->dbPath);
    $b = txTestDb($this->dbPath);

    // A: deferred transaction, then a read — this is the shape setCells() had.
    $a->exec('BEGIN');
    $a->querySingle('SELECT COALESCE(MAX(row), 0) FROM cells');

    // B: an unrelated connection commits in the window between A's read and A's write.
    expect($b->exec("INSERT INTO cells VALUES (1, 1, 'from-b')"))->toBeTrue();

    // A: attempts to upgrade its snapshot to a write. This is the starvation case.
    $upgradeFailed = false;

    try {
        $a->exec("INSERT INTO cells VALUES (2, 2, 'from-a')");
    } catch (SQLite3Exception) {
        $upgradeFailed = true;
    }

    expect($upgradeFailed)->toBeTrue();
    expect($a->lastErrorCode())->toBe(5); // SQLITE_BUSY (BUSY_SNAPSHOT)

    try {
        $a->exec('ROLLBACK');
    } catch (SQLite3Exception) {
    }
    $a->close();
    $b->close();
});

test('BEGIN IMMEDIATE holds one snapshot across read and write, so the upgrade cannot fail', function (): void {
    $a = txTestDb($this->dbPath);
    $b = txTestDb($this->dbPath);

    // A: takes the write lock up front — the fix.
    $a->exec('BEGIN IMMEDIATE');
    $a->querySingle('SELECT COALESCE(MAX(row), 0) FROM cells');

    // B now cannot interpose: it is the one that must back off, not A.
    $bBlocked = false;

    try {
        $b->exec("INSERT INTO cells VALUES (1, 1, 'from-b')");
    } catch (SQLite3Exception) {
        $bBlocked = true;
    }

    expect($bBlocked)->toBeTrue();
    expect($b->lastErrorCode())->toBe(5);

    // A completes its batch unharmed.
    expect($a->exec("INSERT INTO cells VALUES (2, 2, 'from-a')"))->toBeTrue();
    expect($a->exec('COMMIT'))->toBeTrue();

    $check = txTestDb($this->dbPath);
    expect($check->querySingle('SELECT value FROM cells WHERE row = 2 AND col = 2'))->toBe('from-a');

    $a->close();
    $b->close();
    $check->close();
});

test('SpreadsheetExample opens its write batch with an immediate transaction', function (): void {
    $source = dirname(__DIR__, 2) . '/website/src/Examples/SpreadsheetExample.php';
    expect(file_exists($source))->toBeTrue();

    $code = file_get_contents($source);

    // Guards example code that has no other test seam. setCells() reads inside its
    // transaction, so a bare deferred BEGIN reintroduces the starvation above.
    expect($code)->toContain("exec('BEGIN IMMEDIATE')");
    expect($code)->not->toContain("exec('BEGIN')");
});
