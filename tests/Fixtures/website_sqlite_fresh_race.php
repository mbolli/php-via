<?php

declare(strict_types=1);

/*
 * Fixture for WebsiteSqliteBusyTest: two forked processes open the same fresh database file with
 * Sqlite::open() at the same instant, as two workers do on a first start.
 *
 * Prints the rounds and the rounds in which a process failed as one JSON line.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

require dirname(__DIR__, 2) . '/website/vendor/autoload.php';

use PhpVia\Website\Support\Sqlite;

$rounds = (int) ($argv[1] ?? 40);
$path = sys_get_temp_dir() . '/via_fresh_' . bin2hex(random_bytes(6)) . '.db';
$schema = 'CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, room TEXT NOT NULL)';
$failed = 0;
$errors = [];

for ($round = 0; $round < $rounds; ++$round) {
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    $at = microtime(true) + 0.02;
    $pids = [];
    for ($worker = 0; $worker < 2; ++$worker) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            while (microtime(true) < $at) {
                // Spin, so both processes open the file at the same instant.
            }

            try {
                Sqlite::open($path, $schema)->close();

                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage() . "\n");

                exit(1);
            }
        }
        $pids[] = $pid;
    }
    $roundFailed = false;
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $roundFailed = $roundFailed || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
    }
    $failed += (int) $roundFailed;
}

foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

echo json_encode(['rounds' => $rounds, 'failed' => $failed]), "\n";
