<?php

declare(strict_types=1);

use Mbolli\PhpVia\State\SharedTable;
use Mbolli\PhpVia\State\SqliteSnapshot;

/*
 * GlobalState persisted write-behind rather than write-through.
 *
 * OpenSwoole\Table is memory-only: allocated in the master and inherited on fork, so it survives
 * a worker restart but not a server restart. SQLite fixes that, but putting it in FRONT of reads
 * would be the wrong trade — measured on a 50k-row table:
 *
 *   OpenSwoole\Table::get ....... 0.083 us
 *   SharedTable::get (wrapper) .. 0.193 us
 *   SQLite point SELECT ......... 2.757 us   (14x the wrapper)
 *
 * and every one of those microseconds is non-yielding CPU that stalls the whole worker's event
 * loop, not just the calling coroutine. So reads never touch SQLite. Writes land in the table and
 * set a dirty flag; a leader-worker timer drains the dirty set into one batched transaction:
 *
 *   dirty keys | flush     | per row
 *           10 |   11.5 us |  1.15 us
 *          100 |  102.5 us |  1.03 us
 *         1000 |  799.9 us |  0.80 us
 *
 *   wal_checkpoint(TRUNCATE) of a 4.3 MB WAL: 659.6 us
 *
 * Same shape as Anders Murphy's writer process — drain a queue on a fixed tick inside one
 * transaction — but using the leader-gated timer php-via already has, so no extra process and no
 * change to read semantics. The cost is a bounded loss window: writes since the last flush.
 */

beforeEach(function (): void {
    $this->path = sys_get_temp_dir() . '/via_gs_' . bin2hex(random_bytes(6)) . '.db';
});

afterEach(function (): void {
    foreach (['', '-wal', '-shm'] as $suffix) {
        if (is_file($this->path . $suffix)) {
            unlink($this->path . $suffix);
        }
    }
});

test('a flushed value survives into a fresh snapshot handle', function (): void {
    $table = new SharedTable(maxRows: 64);
    $table->set('answer', 42);
    $table->set('who', ['name' => 'ada']);

    $snapshot = new SqliteSnapshot($this->path);
    expect($snapshot->save($table->takeDirty()))->toBe(2);
    $snapshot->close();

    // A "restart": brand new table and handle, seeded from the file.
    $reopened = new SqliteSnapshot($this->path);
    $restored = new SharedTable(maxRows: 64);
    foreach ($reopened->load() as $key => $serialized) {
        $restored->seed($key, $serialized);
    }

    expect($restored->get('answer'))->toBe(42);
    expect($restored->get('who'))->toBe(['name' => 'ada']);
});

test('only changed keys are flushed', function (): void {
    $table = new SharedTable(maxRows: 64);
    $table->set('a', 1);
    $table->set('b', 2);

    $snapshot = new SqliteSnapshot($this->path);
    expect($snapshot->save($table->takeDirty()))->toBe(2);

    // Nothing written since — the next tick must be a no-op, not a full rewrite.
    expect($table->takeDirty())->toBe([]);

    $table->set('b', 22);
    $second = $table->takeDirty();

    expect(array_keys($second))->toBe(['b']);
    $snapshot->save($second);

    $restored = $snapshot->load();
    expect(unserialize($restored['a']))->toBe(1);
    expect(unserialize($restored['b']))->toBe(22);
});

test('seeded values are not immediately dirty', function (): void {
    // Otherwise every restart would rewrite the whole snapshot on its first tick.
    $table = new SharedTable(maxRows: 64);
    $table->seed('x', serialize('loaded'));

    expect($table->get('x'))->toBe('loaded');
    expect($table->takeDirty())->toBe([]);
});

test('a value written after takeDirty is caught by the next drain', function (): void {
    $table = new SharedTable(maxRows: 64);
    $table->set('k', 'first');
    $table->takeDirty();

    $table->set('k', 'second');

    expect($table->takeDirty())->toHaveKey('k');
});

test('an empty drain writes nothing', function (): void {
    $snapshot = new SqliteSnapshot($this->path);

    expect($snapshot->save([]))->toBe(0);
    expect($snapshot->load())->toBe([]);
});

test('checkpointing folds the WAL back and reports success', function (): void {
    $snapshot = new SqliteSnapshot($this->path, checkpointEvery: PHP_INT_MAX);

    $rows = [];
    for ($i = 0; $i < 500; ++$i) {
        $rows['k' . $i] = serialize(str_repeat('v', 500));
    }
    $snapshot->save($rows);

    expect($snapshot->checkpoint())->toBeTrue();

    clearstatcache();
    expect((int) (@filesize($this->path . '-wal') ?: 0))->toBe(0, 'TRUNCATE should empty the WAL');
});

test('a batch that cannot take the write lock fails loudly instead of writing untransacted', function (): void {
    // SQLite3::exec() reports a failed BEGIN by returning false, not by throwing. Left
    // unchecked, the batch would proceed with no transaction open — every row committing on its
    // own, and a mid-batch failure leaving the snapshot half-applied with nothing to roll back.
    $snapshot = new SqliteSnapshot($this->path);
    $snapshot->save(['keep' => serialize('original')]);

    $contender = new SQLite3($this->path);
    $contender->exec('PRAGMA journal_mode=WAL');
    $contender->busyTimeout(0);
    $contender->exec('BEGIN IMMEDIATE');

    try {
        expect(fn () => $snapshot->save(['keep' => serialize('changed')]))
            ->toThrow(RuntimeException::class)
        ;
    } finally {
        $contender->exec('ROLLBACK');
        $contender->close();
    }

    // And the original value is untouched.
    expect(unserialize($snapshot->load()['keep']))->toBe('original');
    $snapshot->close();
});

/** Run the persistence fixture once and return what globalState('counter') held at start-up. */
function runPersistentServer(string $path, string $write, int $flushMs = 100, int $workers = 1, string $when = 'start'): string {
    $fixture = dirname(__DIR__) . '/Fixtures/persistent_global_state.php';
    $out = (string) shell_exec(
        'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture)
        . ' ' . escapeshellarg($path) . ' ' . escapeshellarg($write) . ' ' . $flushMs . ' ' . $workers
        . ' ' . escapeshellarg($when) . ' 2>&1'
    );
    expect($out)->toMatch('/value=/', 'fixture output: ' . var_export($out, true));
    preg_match('/value=(.*)/', $out, $m);

    return trim($m[1]);
}

test('GlobalState survives a real server restart', function (): void {
    // End to end through Via::start(): seed on boot, write-behind flush, drain on shutdown.
    expect(runPersistentServer($this->path, 'hello'))->toBe('NULL', 'the first server starts with nothing persisted');
    expect(runPersistentServer($this->path, '-'))->toBe("'hello'", 'a fresh server must restore what the previous one wrote');
});

test('a write the flush timer never saw survives a stop', function (int $workers): void {
    // No flush tick fires within the run, so only the stop path can persist it: the leader's
    // stop flush or the master's final drain. Before, onWorkerStop never ran on a stop.
    expect(runPersistentServer($this->path, 'late', flushMs: 60_000, workers: $workers))->toBe('NULL');
    expect(runPersistentServer($this->path, '-', flushMs: 60_000, workers: $workers))->toBe("'late'");
})->with([1, 2]);

test('a write made after the leader stopped is saved by the master', function (): void {
    // The leader's own stop flush has already run by then, so only the master's drain sees it.
    expect(runPersistentServer($this->path, 'last', flushMs: 60_000, workers: 2, when: 'shutdown'))->toBe('NULL');
    expect(runPersistentServer($this->path, '-', flushMs: 60_000, workers: 2))->toBe("'last'");
});

test('a write survives a double SIGTERM that ends the master before its shutdown event', function (int $workers): void {
    // The second signal ends the master before its drain runs, so the leader's stop flush is
    // the only thing that saves the write.
    $fixture = dirname(__DIR__) . '/Fixtures/persistent_global_state.php';
    $proc = proc_open(
        [PHP_BINARY, $fixture, $this->path, 'late', '60000', (string) $workers, 'external'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out = '';
    $deadline = microtime(true) + 10;
    while (!str_contains($out, 'value=') && microtime(true) < $deadline) {
        $out .= (string) fgets($pipes[1]);
    }
    expect($out)->toContain('value=NULL');

    $master = proc_get_status($proc)['pid'];
    usleep(300_000);
    posix_kill($master, SIGTERM);
    usleep(2_000);
    posix_kill($master, SIGTERM);

    $deadline = microtime(true) + 10;
    while (proc_get_status($proc)['running'] && microtime(true) < $deadline) {
        usleep(20_000);
    }
    if (proc_get_status($proc)['running']) {
        proc_terminate($proc, SIGKILL);
    }
    proc_close($proc);

    expect(runPersistentServer($this->path, '-', flushMs: 60_000, workers: $workers))->toBe("'late'");
})->with([1, 2]);

test('a snapshot reopens after close()', function (): void {
    $snapshot = new SqliteSnapshot($this->path);
    $snapshot->close();

    expect($snapshot->save(['k' => serialize('v')]))->toBe(1);
    expect(unserialize($snapshot->load()['k']))->toBe('v');
    $snapshot->close();
});

test('a connection opened before fork() is refused in the child', function (): void {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('ext-pcntl required');
    }

    $snapshot = new SqliteSnapshot($this->path);
    $result = sys_get_temp_dir() . '/via_gs_fork_' . bin2hex(random_bytes(6));

    $pid = pcntl_fork();
    if ($pid === 0) {
        try {
            $snapshot->save(['k' => serialize('child')]);
            file_put_contents($result, 'saved');
        } catch (LogicException) {
            file_put_contents($result, 'refused');
        }
        // Skip destructors: they would close the parent's inherited connection from here.
        posix_kill(getmypid(), SIGKILL);
    }
    pcntl_waitpid($pid, $status);

    try {
        expect(@file_get_contents($result))->toBe('refused');
        expect($snapshot->load())->toBe([]);
    } finally {
        @unlink($result);
        $snapshot->close();
    }
});
