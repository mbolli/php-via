<?php

declare(strict_types=1);

/*
 * Fixture for SharedSessionDataTest: least-recently-used eviction seen from two workers.
 *
 * Two Via instances share one store, as two forked workers do. The clock of the State namespace
 * is stepped per session so each gets its own last-access second, as client_registry_clock.php does.
 *
 * Prints one "<session>=<value or none>" line per probed session, then "rows=<n> removed=<n>".
 */

namespace Mbolli\PhpVia\State {
    // Not time(): inside this namespace that would call itself.
    function time(): int {
        return (int) microtime(true) + (int) ($GLOBALS['clockSkew'] ?? 0);
    }
}

namespace {
    use Mbolli\PhpVia\Config;
    use Mbolli\PhpVia\State\SharedSessionStore;
    use Mbolli\PhpVia\Via;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    putenv('VIA_TEST_MODE=1');

    $store = new SharedSessionStore(maxRows: 64);
    $writer = new Via((new Config())->withLogLevel('error'));
    $writer->getApp()->setSessionStore($store);
    $leader = new Via((new Config())->withLogLevel('error'));
    $leader->getApp()->setSessionStore($store);

    for ($i = 0; $i < 64; ++$i) {
        $GLOBALS['clockSkew'] = $i;
        $writer->setSessionData("s{$i}", 'n', $i);
    }

    // Read on the other worker: that alone must keep the oldest session.
    $GLOBALS['clockSkew'] = 100;
    $leader->getSessionData('s0', 'n');

    $GLOBALS['clockSkew'] = 101;
    for ($i = 64; $i < 70; ++$i) {
        $writer->setSessionData("s{$i}", 'n', $i);
    }

    // 70 rows over a cap of 64: down to 1% below it, so the 7 least recently used go.
    $removed = $store->count();
    $leader->getApp()->evictSharedSessions();
    $removed -= $store->count();

    foreach (['s0', 's1', 's7', 's8', 's63', 's69'] as $session) {
        echo $session, '=', var_export($writer->getSessionData($session, 'n', 'none'), true), "\n";
    }
    echo 'rows=', $store->count(), ' removed=', $removed, "\n";
}
