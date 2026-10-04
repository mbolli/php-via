<?php

declare(strict_types=1);

/*
 * Fixture for WebsiteSqliteBusyTest: a website example's write meets another connection's lock
 * inside Coroutine::run, which cannot run in the Pest process (see client_snapshot_cases.php).
 * A second coroutine ticks every 5 ms meanwhile; a third releases the lock after 60 ms.
 *
 * Prints what it observed as one JSON line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

require dirname(__DIR__, 2) . '/website/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

use OpenSwoole\Coroutine;
use PhpVia\Website\Examples\SpreadsheetExample;
use PhpVia\Website\Support\Sqlite;

$path = sys_get_temp_dir() . '/via_retry_' . bin2hex(random_bytes(6)) . '.db';
$result = [];

try {
    $db = Sqlite::open($path, "CREATE TABLE IF NOT EXISTS cells (row INTEGER NOT NULL, col INTEGER NOT NULL, value TEXT NOT NULL DEFAULT '', PRIMARY KEY (row, col))");
    (new ReflectionProperty(SpreadsheetExample::class, 'db'))->setValue(null, $db);
    $locker = new SQLite3($path);
    $locker->exec('BEGIN IMMEDIATE');

    $waiting = true;
    $ticks = 0;
    $elapsedMs = 0.0;

    Coroutine::run(static function () use ($locker, &$waiting, &$ticks, &$elapsedMs): void {
        Coroutine::create(static function () use (&$waiting, &$ticks): void {
            while ($waiting) {
                ++$ticks;
                Coroutine::usleep(5000);
            }
        });
        Coroutine::create(static function () use ($locker): void {
            Coroutine::usleep(60_000);
            $locker->exec('COMMIT');
        });
        $start = hrtime(true);
        (new ReflectionMethod(SpreadsheetExample::class, 'setCell'))->invoke(null, 0, 0, 'after the lock');
        $elapsedMs = (hrtime(true) - $start) / 1e6;
        $waiting = false;
    });

    $result = [
        'ticks' => $ticks,
        'elapsedMs' => $elapsedMs,
        'value' => $db->querySingle('SELECT value FROM cells WHERE row = 0 AND col = 0'),
    ];
    $locker->close();
    $db->close();
} catch (Throwable $e) {
    $result = ['error' => $e::class . ': ' . $e->getMessage()];
} finally {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}

echo json_encode($result), "\n";
