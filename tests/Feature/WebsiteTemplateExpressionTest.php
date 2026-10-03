<?php

declare(strict_types=1);

/*
 * Datastar runs a data-* expression with the event in `evt`. `$event` there reads a signal named
 * "event", which no page declares, so the expression throws when the event fires.
 */

test('website templates read the event as evt, not as a $event signal', function (): void {
    $dir = dirname(__DIR__, 2) . '/website/templates';
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file instanceof SplFileInfo || !str_ends_with($file->getFilename(), '.twig')) {
            continue;
        }

        preg_match_all('/\sdata-[\w:.-]+="([^"]*)"/', (string) file_get_contents($file->getPathname()), $attributes);
        foreach ($attributes[1] as $expression) {
            if (preg_match('/\$event\b/', $expression) === 1) {
                $offenders[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
