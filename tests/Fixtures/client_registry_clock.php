<?php

declare(strict_types=1);

/*
 * Fixture for ClientRegistryTest: a client whose stream never goes idle, three hours on.
 *
 * A stream that gets a patch at least every 100 ms never reaches the idle branch of the SSE loop,
 * so nothing heartbeats its row. The clock of the State namespace is moved forward instead of
 * waiting; code there that expires rows by time() sees the jump.
 *
 * Prints before=<count> after=<count> again=<count> rows=<registry rows>.
 */

namespace Mbolli\PhpVia\State {
    // Not time(): inside this namespace that would call itself.
    function time(): int {
        return (int) microtime(true) + (int) ($GLOBALS['clockSkew'] ?? 0);
    }
}

namespace {
    use Mbolli\PhpVia\Config;
    use Mbolli\PhpVia\State\SharedClientRegistry;
    use Mbolli\PhpVia\Via;

    require dirname(__DIR__, 2) . '/vendor/autoload.php';

    putenv('VIA_TEST_MODE=1');

    $via = new Via((new Config())->withLogLevel('error'));
    $registry = new SharedClientRegistry(64);
    $via->getApp()->setClientRegistry($registry);
    $via->getApp()->registerClient('ctx-busy', [
        'id' => 'client-busy',
        'identicon' => $via->generateIdenticon('client-busy'),
        'connected_at' => time(),
        'ip' => '127.0.0.1',
    ]);

    $before = count($via->getClients());
    $GLOBALS['clockSkew'] = 3 * 3600;
    $after = count($via->getClients());
    $again = count($via->getClients());

    echo "before={$before} after={$after} again={$again} rows={$registry->count()}\n";
}
