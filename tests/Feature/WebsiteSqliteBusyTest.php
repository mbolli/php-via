<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Testing\TestApp;
use Mbolli\PhpVia\Via;
use PhpVia\Website\Examples\ChatRoomExample;
use PhpVia\Website\Examples\SpreadsheetExample;
use PhpVia\Website\Support\Sqlite;

/*
 * Every worker opens chat.db and spreadsheet.db. SQLite's busy timeout waits inside the C library
 * and stalls all coroutines of the worker, so the examples keep one only while they set the file
 * up. Afterwards a write that meets another worker's lock waits with coroutine sleeps, and gives
 * up without an error once the lock outlasts its budget. The coroutine case runs in
 * Fixtures/website_sqlite_retry.php.
 */

$sqliteBusyAutoload = dirname(__DIR__, 2) . '/website/vendor/autoload.php';
$sqliteBusyReady = false;

if (is_file($sqliteBusyAutoload)) {
    require_once $sqliteBusyAutoload;
    $sqliteBusyReady = class_exists('PhpVia\\Website\\Examples\\ChatRoomExample');
}

if (!$sqliteBusyReady) {
    test('website SQLite busy handling (website dependencies not installed)')
        ->skip('website/vendor/autoload.php missing, run composer install in website/ to enable')
    ;

    return;
}

const SQLITE_BUSY_CHAT_SCHEMA = 'CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, room TEXT NOT NULL, username TEXT NOT NULL, message TEXT NOT NULL, timestamp TEXT NOT NULL)';
const SQLITE_BUSY_CELLS_SCHEMA = "CREATE TABLE IF NOT EXISTS cells (row INTEGER NOT NULL, col INTEGER NOT NULL, value TEXT NOT NULL DEFAULT '', PRIMARY KEY (row, col))";

/** A second connection that holds the write lock, as another worker's write does. */
function sqliteBusyLocker(string $path): SQLite3 {
    $locker = new SQLite3($path);
    $locker->exec('BEGIN IMMEDIATE');

    return $locker;
}

function sqliteBusyStatic(string $class, string $prop, mixed $value): void {
    (new ReflectionProperty($class, $prop))->setValue(null, $value);
}

function sqliteBusyCall(string $class, string $method, mixed ...$args): mixed {
    return (new ReflectionMethod($class, $method))->invoke(null, ...$args);
}

beforeEach(function (): void {
    $this->dbPath = sys_get_temp_dir() . '/via_busy_' . bin2hex(random_bytes(6)) . '.db';
    $this->app = null;
});

afterEach(function (): void {
    // Also after a failed assertion: a TestApp left running breaks the next test's event loop.
    $this->app?->shutdown();
    sqliteBusyStatic(ChatRoomExample::class, 'db', null);
    sqliteBusyStatic(SpreadsheetExample::class, 'db', null);
    sqliteBusyStatic(SpreadsheetExample::class, 'rangeCache', []);
    sqliteBusyStatic(SpreadsheetExample::class, 'extentCache', null);
    foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

test('the examples wait for locks only while they set their database up', function (string $class): void {
    sqliteBusyStatic($class, 'db', null);
    $db = sqliteBusyCall($class, 'db');

    expect($db->querySingle('PRAGMA busy_timeout'))->toBe(0)
        ->and($db->querySingle('PRAGMA journal_mode'))->toBe('wal')
    ;

    $db->close();
})->with([ChatRoomExample::class, SpreadsheetExample::class]);

test('a spreadsheet write that meets a lock waits without stalling the worker\'s other coroutines', function (): void {
    $out = trim((string) shell_exec(
        'timeout 30 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Fixtures/website_sqlite_retry.php') . ' 2>&1'
    ));
    $lines = explode("\n", $out);
    $data = json_decode((string) end($lines), true);

    expect($data)->toBeArray('fixture output: ' . $out)
        ->and($data)->not->toHaveKey('error')
        ->and($data['value'])->toBe('after the lock')
        // The lock holder commits after 60 ms; a busy wait would freeze the ticking coroutine meanwhile.
        ->and($data['ticks'])->toBeGreaterThanOrEqual(5)
        ->and($data['elapsedMs'])->toBeGreaterThanOrEqual(50.0)
    ;
});

test('a write gives up with null once the lock outlasts its budget, and other errors still throw', function (): void {
    $db = Sqlite::open($this->dbPath, SQLITE_BUSY_CELLS_SCHEMA);
    $locker = sqliteBusyLocker($this->dbPath);

    $start = hrtime(true);
    $result = Sqlite::retry(static fn (): bool => $db->exec("INSERT INTO cells VALUES (0, 0, 'x')"), budgetMs: 100);
    $elapsedMs = (hrtime(true) - $start) / 1e6;

    expect($result)->toBeNull()
        ->and($elapsedMs)->toBeLessThan(400)
        ->and(static fn () => Sqlite::retry(static fn (): bool => $db->exec('INSERT INTO nowhere VALUES (1)')))->toThrow(SQLite3Exception::class)
    ;

    $locker->exec('ROLLBACK');
    $db->close();
    $locker->close();
});

test('a chat message the lock keeps out stays in the input, and Enter sends it once the lock is gone', function (): void {
    $db = Sqlite::open($this->dbPath, SQLITE_BUSY_CHAT_SCHEMA);
    sqliteBusyStatic(ChatRoomExample::class, 'db', $db);
    $this->app = $app = new TestApp(
        (new Config())->withLogLevel('error')->withTemplateDir(dirname(__DIR__, 2) . '/website/templates'),
        static function (Via $via): void {
            foreach (['assetVersion' => '1', 'workerVersion' => '1', 'siteUrl' => 'https://example.test/'] as $name => $value) {
                $via->getTwig()->addGlobal($name, $value);
            }
            ChatRoomExample::register($via);
        },
    );
    $tab = $app->open('/examples/chat-room');
    $locker = sqliteBusyLocker($this->dbPath);

    $tab->action('sendMessage', signals: ['messageInput' => 'held back']);

    expect($tab->signal('messageInput'))->toBe('held back')
        ->and($db->querySingle('SELECT COUNT(*) FROM messages'))->toBe(0)
    ;

    $locker->exec('ROLLBACK');
    $tab->action('sendMessage', signals: ['messageInput' => 'held back']);

    expect($tab->signal('messageInput'))->toBe('')
        ->and($db->querySingle('SELECT message FROM messages'))->toBe('held back')
    ;

    $locker->close();
});

test('spreadsheet writes the lock keeps out leave the cells as they were, without an error', function (): void {
    $db = Sqlite::open($this->dbPath, SQLITE_BUSY_CELLS_SCHEMA);
    $db->exec("INSERT INTO cells VALUES (0, 0, 'before')");
    sqliteBusyStatic(SpreadsheetExample::class, 'db', $db);
    $locker = sqliteBusyLocker($this->dbPath);

    sqliteBusyCall(SpreadsheetExample::class, 'setCell', 0, 0, 'edit');
    sqliteBusyCall(SpreadsheetExample::class, 'setCells', [['row' => 0, 'col' => 0, 'value' => 'paste'], ['row' => 0, 'col' => 1, 'value' => 'paste']]);

    $locker->exec('ROLLBACK');
    expect($db->querySingle('SELECT value FROM cells WHERE row = 0 AND col = 0'))->toBe('before')
        ->and($db->querySingle('SELECT COUNT(*) FROM cells'))->toBe(1)
    ;

    sqliteBusyCall(SpreadsheetExample::class, 'setCell', 0, 0, 'edit');
    expect($db->querySingle('SELECT value FROM cells WHERE row = 0 AND col = 0'))->toBe('edit');

    $db->close();
    $locker->close();
});
