<?php

declare(strict_types=1);

/*
 * The methods 0.14 removed throw when called, so the docs, the README, llms-full.txt and the website's
 * examples must not tell readers to call them.
 */

/**
 * The method names src/ passes to Removed::method().
 *
 * @return list<string>
 */
function removedMethodNames(): array {
    $names = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        preg_match_all("/Removed::method\\('\\w+::(\\w+)\\(\\)'/", (string) file_get_contents((string) $file), $m);
        array_push($names, ...$m[1]);
    }

    return array_values(array_unique($names));
}

/**
 * @return array<string, string> contents by path relative to the repository
 */
function readerFacingFiles(): array {
    $root = dirname(__DIR__, 2);
    $paths = [
        'README.md',
        'website/public/llms.txt',
        'website/public/llms-full.txt',
        ...array_map(static fn (string $p): string => substr($p, strlen($root) + 1), [
            ...(glob($root . '/website/templates/docs/*.twig') ?: []),
            ...(glob($root . '/website/templates/examples/*.twig') ?: []),
            ...(glob($root . '/website/src/Examples/*.php') ?: []),
        ]),
    ];

    $files = [];
    foreach ($paths as $path) {
        $files[$path] = (string) file_get_contents($root . '/' . $path);
    }

    return $files;
}

test('the docs name no method that 0.14 removed', function (): void {
    $names = removedMethodNames();
    expect($names)->toContain('onDisconnect', 'withContextCleanupDelay', 'text');

    $found = [];
    foreach (readerFacingFiles() as $path => $content) {
        foreach ($names as $name) {
            // A lowercase name such as text() is an ordinary word, so only a call of it counts.
            $pattern = preg_match('/[A-Z]/', $name) === 1
                ? '/\b' . $name . '\b/'
                : '/(->|::|<code>|`)' . $name . '\(/';
            if (preg_match($pattern, $content) === 1) {
                $found[] = "{$path}: {$name}";
            }
        }
    }

    expect($found)->toBe([]);
});
