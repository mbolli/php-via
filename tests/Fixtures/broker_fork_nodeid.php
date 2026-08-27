<?php

declare(strict_types=1);

/*
 * Fixture for BrokerNodeIdentityTest.
 *
 * Reproduces the real lifecycle: brokers are constructed in the master process
 * (user code, before $server->start()) and the workers are forked afterwards.
 * Prints "<parentNodeId> <childNodeId>" for the requested broker class.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

putenv('VIA_TEST_MODE=1');

/** @var class-string $class */
$class = 'Mbolli\\PhpVia\\Broker\\' . ($argv[1] ?? 'SwooleBroker');

// Master process: broker exists before any fork, exactly as in real usage.
$broker = new $class();

$pipe = tempnam(sys_get_temp_dir(), 'via_nodeid_');
$pid = pcntl_fork();

if ($pid === 0) {
    // Worker process.
    file_put_contents($pipe, $broker->getNodeId());
    posix_kill(posix_getpid(), SIGKILL); // leave without running the parent's shutdown path
}

pcntl_waitpid($pid, $status);
echo $broker->getNodeId() . ' ' . (string) file_get_contents($pipe) . "\n";
@unlink($pipe);
