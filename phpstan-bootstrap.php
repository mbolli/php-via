<?php

declare(strict_types=1);

// Global constants defined by the OpenSwoole C extension at runtime.
// PHPStan does not load the extension, so it crashes when parsing the
// openswoole/ide-helper stubs that reference these constants. Defining
// them here via bootstrapFiles lets PHPStan process the stubs safely.
if (!defined('SWOOLE_EVENT_READ')) {
    define('SWOOLE_EVENT_READ', 512);
}
if (!defined('SWOOLE_IPC_NONE')) {
    define('SWOOLE_IPC_NONE', 0);
}
