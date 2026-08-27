<?php

declare(strict_types=1);

/*
 * What actually blocks a WAL checkpoint, and the one place php-via got it wrong.
 *
 * The backlog recorded checkpoint starvation as an unsolved blocker on the strength of
 * "php-via always has long-lived readers, because every SSE connection is one", measured as a
 * WAL growing to 200 MB in four seconds while wal_checkpoint reported no error.
 *
 * That premise is wrong. An SSE connection is a coroutine, not an open SQLite snapshot. What
 * holds a snapshot — and so pins the WAL — is a statement left MID-SCAN, or an explicit read
 * transaction. Measured, writes churned between each reader state and then TRUNCATE attempted:
 *
 *   range query drained to EOF ................ busy=0, WAL truncated
 *   single row, one fetchArray, not drained ... busy=1, WAL held at 17 MB
 *   the same plus an explicit finalize() ...... busy=0
 *   querySingle(), either form ................ busy=0
 *   partial scan, loop broken early ........... busy=1, WAL held
 *   INSERT via execute(), result discarded .... busy=0
 *
 * So the requirement is reader hygiene, not a reader-quiescent window the design cannot
 * provide: never hold a partially consumed cursor across a checkpoint. That is enforceable, and
 * it unblocks the single-writer design.
 *
 * SpreadsheetExample::getCell() was exactly the failing shape — one fetchArray on a one-row
 * query, which never steps to DONE, so the statement stayed active until PHP's
 * garbage collector happened to finalize it. Non-deterministically pinning the WAL open is the
 * likely cause of the 200 MB measurement.
 */

/** @return array{busy: int, walKb: int} */
function checkpointAfterChurn(SQLite3 $writer, string $path): array {
    static $seq = 0;
    // Few rows, fat payloads: enough WAL to make truncation observable without the runtime
    // cost of thousands of inserts per assertion.
    for ($i = 0; $i < 200; ++$i) {
        $writer->exec('INSERT INTO cells VALUES (' . (100000 + $seq++) . ", 9, '" . str_repeat('y', 4000) . "')");
    }

    $result = $writer->query('PRAGMA wal_checkpoint(TRUNCATE)');
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $result->finalize();

    clearstatcache();

    return ['busy' => (int) ($row['busy'] ?? -1), 'walKb' => (int) ((@filesize($path . '-wal') ?: 0) / 1024)];
}

beforeEach(function (): void {
    $this->path = sys_get_temp_dir() . '/via_wal_' . bin2hex(random_bytes(6)) . '.db';

    $this->writer = new SQLite3($this->path);
    $this->writer->exec('PRAGMA journal_mode=WAL');
    $this->writer->exec('CREATE TABLE cells (row INTEGER, col INTEGER, value TEXT, PRIMARY KEY (row, col))');
    for ($i = 0; $i < 200; ++$i) {
        $this->writer->exec("INSERT INTO cells VALUES ({$i}, 1, 'v{$i}')");
    }

    $this->reader = new SQLite3($this->path);
    $this->reader->exec('PRAGMA journal_mode=WAL');
});

afterEach(function (): void {
    $this->reader->close();
    $this->writer->close();
    foreach (['', '-wal', '-shm'] as $suffix) {
        // is_file first: unlink() on a missing path emits a warning that Pest reports even
        // when suppressed with @.
        if (is_file($this->path . $suffix)) {
            unlink($this->path . $suffix);
        }
    }
});

test('a fully drained range query does not block a checkpoint', function (): void {
    $result = $this->reader->query('SELECT row, value FROM cells WHERE row < 20');
    while ($result->fetchArray(SQLITE3_ASSOC));

    expect(checkpointAfterChurn($this->writer, $this->path)['busy'])->toBe(0);
});

test('a cursor left mid-scan pins the WAL open', function (): void {
    // The shape to avoid, and the one the "unsolved blocker" was actually measuring.
    $result = $this->reader->query('SELECT row FROM cells');
    $result->fetchArray(SQLITE3_ASSOC);

    $after = checkpointAfterChurn($this->writer, $this->path);

    expect($after['busy'])->toBe(1, 'an active scan must block the checkpoint');
    expect($after['walKb'])->toBeGreaterThan(500, 'and the WAL keeps growing while it does');
});

test('finalising the cursor releases the checkpoint immediately', function (): void {
    $result = $this->reader->query('SELECT row FROM cells');
    $result->fetchArray(SQLITE3_ASSOC);
    $result->finalize();

    $after = checkpointAfterChurn($this->writer, $this->path);

    expect($after['busy'])->toBe(0);
    expect($after['walKb'])->toBe(0, 'TRUNCATE should leave the WAL empty');
});

test('an idle connection does not block a checkpoint at all', function (): void {
    // The claim that "every SSE connection is a long-lived reader" rests on this being false.
    $this->reader->querySingle('SELECT count(*) FROM cells');

    expect(checkpointAfterChurn($this->writer, $this->path)['busy'])->toBe(0);
});

test('an explicit read transaction does block, and releases on COMMIT', function (): void {
    $this->reader->exec('BEGIN');
    $this->reader->querySingle('SELECT count(*) FROM cells');

    expect(checkpointAfterChurn($this->writer, $this->path)['busy'])->toBe(1);

    $this->reader->exec('COMMIT');

    expect(checkpointAfterChurn($this->writer, $this->path)['busy'])->toBe(0);
});

test('a result local to a function is finalised when that function returns', function (): void {
    // The load-bearing fact for all of the above. PHP frees SQLite3Result by REFCOUNT, so a
    // result held only in a local is finalised the moment the function returns — deterministic,
    // not at the mercy of the cycle collector. That is why php-via's read helpers
    // (SpreadsheetExample::getCell(), which fetches one row and never steps to DONE) do not
    // pin the WAL despite never calling finalize() explicitly.
    $reader = $this->reader;

    $readOneRow = static function () use ($reader): string {
        $stmt = $reader->prepare('SELECT value FROM cells WHERE row = :r AND col = :c');
        $stmt->bindValue(':r', 5, SQLITE3_INTEGER);
        $stmt->bindValue(':c', 1, SQLITE3_INTEGER);
        $result = $stmt->execute();
        $data = $result->fetchArray(SQLITE3_ASSOC);

        return $data !== false ? (string) $data['value'] : '';
    };

    expect($readOneRow())->toBe('v5');
    expect(checkpointAfterChurn($this->writer, $this->path)['busy'])
        ->toBe(0, 'a helper that returns a scalar must not leave a snapshot behind')
    ;
});

test('the same read DOES pin the WAL when the result outlives the call', function (): void {
    // The distinction that matters: it is the lifetime of the result, not the query shape.
    $stmt = $this->reader->prepare('SELECT value FROM cells WHERE row = 5 AND col = 1');
    $held = $stmt->execute();
    $held->fetchArray(SQLITE3_ASSOC);

    expect(checkpointAfterChurn($this->writer, $this->path)['busy'])->toBe(1);

    $held->finalize();

    expect(checkpointAfterChurn($this->writer, $this->path)['busy'])->toBe(0);
});
