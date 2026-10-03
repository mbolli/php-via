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

describe('ViewCache views', function (): void {
    test('two views of one scope keep separate entries', function (): void {
        $cache = new ViewCache();
        $cache->set('room:1', '<p>page</p>', true, ViewCache::viewKey('/lobby', null));
        $cache->set('room:1', '<p>side</p>', true, ViewCache::viewKey('/dashboard', null));
        $cache->set('room:1', '<p>comp</p>', true, ViewCache::viewKey('/lobby', 'chat'));

        expect($cache->get('room:1', true, ViewCache::viewKey('/lobby', null)))->toBe('<p>page</p>')
            ->and($cache->get('room:1', true, ViewCache::viewKey('/dashboard', null)))->toBe('<p>side</p>')
            ->and($cache->get('room:1', true, ViewCache::viewKey('/lobby', 'chat')))->toBe('<p>comp</p>')
            ->and($cache->getScopes())->toBe(['room:1'])
        ;
    });

    test('invalidating a scope drops every view in it', function (): void {
        $cache = new ViewCache();
        $cache->set('room:1', '<p>page</p>', true, ViewCache::viewKey('/lobby', null));
        $cache->set('room:1', '<p>comp</p>', true, ViewCache::viewKey('/lobby', 'chat'));
        $cache->set('room:2', '<p>other</p>', true, ViewCache::viewKey('/lobby', null));

        $cache->invalidate('room:1');

        expect($cache->get('room:1', true, ViewCache::viewKey('/lobby', null)))->toBeNull()
            ->and($cache->get('room:1', true, ViewCache::viewKey('/lobby', 'chat')))->toBeNull()
            ->and($cache->get('room:2', true, ViewCache::viewKey('/lobby', null)))->toBe('<p>other</p>')
        ;
    });
});
