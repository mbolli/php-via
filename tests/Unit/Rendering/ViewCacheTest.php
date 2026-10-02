<?php

declare(strict_types=1);

use Mbolli\PhpVia\Rendering\ViewCache;

describe('ViewCache generations', function (): void {
    test('a render that started before an invalidation is not stored', function (): void {
        $cache = new ViewCache();
        $token = $cache->generation('room:1');
        $cache->invalidate('room:1');

        $cache->setIfCurrent('room:1', '<p>old</p>', true, $token);

        expect($cache->get('room:1', true))->toBeNull();
    });

    test('a render that started after the invalidation is stored', function (): void {
        $cache = new ViewCache();
        $cache->invalidate('room:1');
        $token = $cache->generation('room:1');

        $cache->setIfCurrent('room:1', '<p>new</p>', true, $token);

        expect($cache->get('room:1', true))->toBe('<p>new</p>');
    });

    test('per-entity scopes do not grow the generation map without bound', function (): void {
        $cache = new ViewCache();
        for ($i = 0; $i < 25_000; ++$i) {
            $cache->invalidate("visitor:{$i}");
        }
        $count = count((new ReflectionProperty($cache, 'generations'))->getValue($cache));

        expect($count)->toBeLessThanOrEqual(10_001);
    });

    test('a token taken before the map was reset is not stored', function (): void {
        $cache = new ViewCache();
        $token = $cache->generation('room:1');
        for ($i = 0; $i < 10_002; ++$i) {
            $cache->invalidate("visitor:{$i}");
        }

        $cache->setIfCurrent('room:1', '<p>old</p>', true, $token);

        expect($cache->get('room:1', true))->toBeNull();
    });
});
