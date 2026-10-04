<?php

declare(strict_types=1);

// public/devbar.js in node, against the stub DOM of Fixtures/devbar_script.mjs.

test('the Dev Bar closes its stream while the page sits in the back/forward cache, its Scopes panel names the worker, and it styles itself without inline style', function (): void {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node required to run public/devbar.js');
    }

    $out = (string) shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/Fixtures/devbar_script.mjs') . ' 2>&1');

    expect(json_decode($out, true))->toBe([
        'streamsAtStart' => 1,
        'stylesAdopted' => true,
        'closedOnHide' => true,
        'streamsAfterReturn' => 2,
        'newStreamOpen' => true,
        'streamsAfterFirstShow' => 2,
        'scopesNameWorker' => true,
        'tracesWithoutStyleAttribute' => true,
        'pageListenersLeft' => 0,
    ], $out);
});
