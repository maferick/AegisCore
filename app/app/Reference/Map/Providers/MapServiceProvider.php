<?php

declare(strict_types=1);

namespace App\Reference\Map\Providers;

use App\Reference\Map\Contracts\MapDataProvider;
use App\Reference\Map\Neo4jMapDataProvider;
use App\Reference\Map\Support\MapCache;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Wires {@see MapDataProvider} to the cached Neo4j-backed implementation.
 *
 * Test environments and CI runs swap the default `redis` cache store for
 * an in-memory `array` store automatically — this lets the cache wrapper
 * behave correctly under PHPUnit without provisioning Redis.
 */
class MapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MapDataProvider::class, function ($app) {
            $cacheStore = $this->resolveCacheStore($app);

            return new MapCache(
                inner: new Neo4jMapDataProvider(),
                cache: $app->make(CacheManager::class),
                store: $cacheStore,
                ttlSeconds: 0,
            );
        });
    }

    public function boot(): void
    {
        // Blade auto-discovery picks up `App\View\Components\Map\Renderer`
        // as `<x-map.renderer>` without explicit registration — leaving
        // boot() empty keeps that path clean.
    }

    /**
     * Pick a cache store that's actually available. We default to redis
     * (matches docker-compose); `array` falls in for unit tests; any
     * other configured default is honoured if redis is missing.
     */
    private function resolveCacheStore($app): string
    {
        $configured = (string) $app['config']->get('cache.default', 'array');
        $stores = (array) $app['config']->get('cache.stores', []);

        if (Str::lower($app->environment()) === 'testing') {
            return 'array';
        }

        if (isset($stores['redis'])) {
            return 'redis';
        }

        return $configured;
    }
}
