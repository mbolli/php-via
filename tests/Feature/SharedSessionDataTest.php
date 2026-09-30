<?php

declare(strict_types=1);

use Mbolli\PhpVia\Config;
use Mbolli\PhpVia\Context;
use Mbolli\PhpVia\State\SharedSessionStore;
use Mbolli\PhpVia\Via;
use OpenSwoole\Coroutine\Http\Client;
use OpenSwoole\Table;

/*
 * Session data across workers.
 *
 * A tab's requests land on any worker, so session data kept in each worker's memory went missing
 * whenever the next request reached another one. Two Via instances stand in for two workers here
 * and are handed one store the way a fork inherits it; the fixtures cover forked processes and a
 * real multi-worker server.
 */

beforeEach(function (): void {
    if (!class_exists(Table::class)) {
        $this->markTestSkipped('OpenSwoole\Table required for shared memory');
    }
});

function sessionWorker(?SharedSessionStore $store): Via {
    $via = new Via((new Config())->withLogLevel('error'));
    $via->getApp()->setSessionStore($store);

    return $via;
}

/** Run a fixture and return its "name=value" pairs. */
function sessionFixture(string $name, string $args = ''): array {
    $fixture = dirname(__DIR__) . '/Fixtures/' . $name;
    $out = (string) shell_exec('timeout 60 ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' ' . $args . ' 2>&1');

    preg_match_all('/(\w+)=(\S+)/', $out, $m, PREG_SET_ORDER);
    $pairs = [];
    foreach ($m as [, $key, $value]) {
        $pairs[$key] = $value;
    }

    return $pairs + ['_output' => $out];
}

test('without a shared store each worker keeps its own session data', function (): void {
    $a = sessionWorker(null);
    $b = sessionWorker(null);

    $a->setSessionData('ses_1', 'auth', 'ada');

    expect($b->getSessionData('ses_1', 'auth'))->toBeNull();
});

test('a value written on one worker is read on another', function (): void {
    $store = new SharedSessionStore(maxRows: 64);
    $a = sessionWorker($store);
    $b = sessionWorker($store);

    $a->setSessionData('ses_1', 'auth', ['user' => 'ada']);
    $b->setSessionData('ses_1', 'step', 2);

    expect($b->getSessionData('ses_1', 'auth'))->toBe(['user' => 'ada']);
    expect($a->getSessionData('ses_1', 'step'))->toBe(2);
    expect($b->getSessionData('ses_2', 'auth', 'none'))->toBe('none');

    $onB = new Context(testContextId(), '/test', $b, null, 'ses_1');
    expect($onB->sessionData('auth'))->toBe(['user' => 'ada']);
});

test('values of every type come back from the other worker', function (): void {
    $store = new SharedSessionStore(maxRows: 64);
    $a = sessionWorker($store);
    $b = sessionWorker($store);
    $values = ['string' => 'hello', 'int' => 42, 'float' => 3.14, 'bool' => true, 'array' => ['x' => 1]];

    foreach ($values as $key => $value) {
        $a->setSessionData('ses_1', $key, $value);
    }

    foreach ($values as $key => $value) {
        expect($b->getSessionData('ses_1', $key))->toBe($value);
    }
});

test('a key cleared on one worker is gone on the other and the rest stay', function (): void {
    $store = new SharedSessionStore(maxRows: 64);
    $a = sessionWorker($store);
    $b = sessionWorker($store);

    $a->setSessionData('ses_1', 'cart', [1, 2]);
    $a->setSessionData('ses_1', 'auth', 'ada');
    $b->clearSessionData('ses_1', 'cart');

    expect($a->getSessionData('ses_1', 'cart'))->toBeNull();
    expect($a->getSessionData('ses_1', 'auth'))->toBe('ada');
});

test('clearing a whole session on one worker empties it on the other', function (): void {
    $store = new SharedSessionStore(maxRows: 64);
    $a = sessionWorker($store);
    $b = sessionWorker($store);

    $a->setSessionData('ses_1', 'cart', [1, 2]);
    $a->setSessionData('ses_1', 'auth', 'ada');
    $a->setSessionData('ses_2', 'auth', 'bob');
    $b->clearSessionData('ses_1');

    expect($a->getSessionData('ses_1', 'cart'))->toBeNull();
    expect($a->getSessionData('ses_1', 'auth'))->toBeNull();
    expect($a->getSessionData('ses_2', 'auth'))->toBe('bob');

    $a->setSessionData('ses_1', 'auth', 'ada again');
    expect($b->getSessionData('ses_1', 'auth'))->toBe('ada again');
});

test('reads and clears of a session with no data take no row', function (): void {
    $store = new SharedSessionStore(maxRows: 64);
    $via = sessionWorker($store);

    $via->getSessionData('ses_new', 'auth');
    $via->clearSessionData('ses_new', 'auth');
    $via->clearSessionData('ses_new');

    expect($store->count())->toBe(0);
});

test('a session over the byte cap throws, names the Config method and keeps its data', function (): void {
    $store = new SharedSessionStore(maxRows: 64, maxSessionBytes: 256);
    $via = sessionWorker($store);
    $via->setSessionData('ses_1', 'auth', 'ada');

    expect(fn () => $via->setSessionData('ses_1', 'blob', str_repeat('x', 300)))
        ->toThrow(OverflowException::class, 'Config::withSessionTableSize()')
    ;
    expect($via->getSessionData('ses_1', 'auth'))->toBe('ada');
    expect($via->getSessionData('ses_1', 'blob'))->toBeNull();
});

test('a value that cannot be serialized names its key and leaves the session as it was', function (): void {
    $store = new SharedSessionStore(maxRows: 64);
    $via = sessionWorker($store);
    $via->setSessionData('ses_1', 'auth', 'ada');

    expect(fn () => $via->setSessionData('ses_1', 'callback', fn (): int => 1))
        ->toThrow(InvalidArgumentException::class, '"callback"')
    ;
    expect($via->getSessionData('ses_1', 'auth'))->toBe('ada');

    $via->setSessionData('ses_1', 'step', 2);
    expect($via->getSessionData('ses_1', 'step'))->toBe(2);
});

test('more new sessions than the table holds between eviction passes can still store data', function (): void {
    // Measured before: the 121st new session threw "The shared session table is full".
    $store = new SharedSessionStore(maxRows: 64);
    $via = sessionWorker($store);

    for ($i = 0; $i < 300; ++$i) {
        $via->setSessionData("ses_{$i}", 'n', $i);
    }

    expect($via->getSessionData('ses_299', 'n'))->toBe(299);
    expect($store->count())->toBeLessThan(128);
});

test('sessions without data are evicted before sessions with data', function (): void {
    $store = new SharedSessionStore(maxRows: 64);
    $a = sessionWorker($store);
    $b = sessionWorker($store);

    for ($i = 0; $i < 60; ++$i) {
        $a->setSessionData("kept{$i}", 'n', $i);
    }
    for ($i = 0; $i < 10; ++$i) {
        $a->setSessionData("emptied{$i}", 'n', $i);
        $a->clearSessionData("emptied{$i}");
    }

    $b->getApp()->evictSharedSessions();

    expect($store->count())->toBe(63);
    for ($i = 0; $i < 60; ++$i) {
        expect($a->getSessionData("kept{$i}", 'n'))->toBe($i);
    }
});

test('the least recently used sessions are evicted and a read keeps one', function (): void {
    // Runs in its own process: it moves the clock of the State namespace.
    $r = sessionFixture('session_store_eviction.php');

    expect($r['removed'] ?? null)->toBe('7', $r['_output']);
    expect($r['rows'])->toBe('63');
    expect($r['s0'])->toBe('0', 'read on the other worker, so it must stay');
    expect($r['s1'])->toBe("'none'");
    expect($r['s7'])->toBe("'none'");
    expect($r['s8'])->toBe('8');
    expect($r['s69'])->toBe('69');
});

test('eviction waits for a write in progress instead of racing it', function (): void {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl required to reproduce the worker fork');
    }

    // Measured before, in 2 s runs: a write stalled 4-5 s and threw RuntimeException in 2 of 3.
    $r = sessionFixture('session_store_race.php', 'race 2');

    expect($r['errors'] ?? null)->toBe('0', $r['_output']);
    expect((int) $r['max_ms'])->toBeLessThan(1000, $r['_output']);
});

test('a session whose writer died holding its lock is still evicted', function (): void {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl required to reproduce the worker fork');
    }

    // Once the lease runs out, 2 s. Measured before: never evicted.
    $r = sessionFixture('session_store_race.php', 'dead');

    expect($r['victim'] ?? null)->toBe('evicted', $r['_output']);
});

test('forked workers writing different keys of one session lose nothing', function (): void {
    if (!function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl required to reproduce the worker fork');
    }

    // Measured without the lock: 657-696 of 2000 kept.
    $r = sessionFixture('session_store_fork.php', '4 500');

    expect($r['kept'] ?? null)->toBe('2000', $r['_output']);
});

test('session data set by actions on any worker is seen from every worker', function (): void {
    if (!class_exists(Client::class)) {
        $this->markTestSkipped('OpenSwoole coroutine HTTP client required');
    }

    // Measured before: every read saw 10 of the 40 keys, the ones its own worker had stored.
    $r = sessionFixture('session_data_workers.php', '4 40 8');

    expect($r['failed'] ?? null)->toBe('0', $r['_output']);
    expect($r['ok'])->toBe('40');
    expect(explode(',', $r['seen']))->each->toBe('40');
});

test('the leader evicts sessions past the cap on a multi-worker server', function (): void {
    $r = sessionFixture('session_eviction_workers.php', '90');

    expect($r['peak'] ?? null)->toBe('90', $r['_output']);
    expect((int) $r['rows'])->toBeLessThanOrEqual(64, $r['_output']);
});
