<?php

declare(strict_types=1);

namespace App\Reference\Map\Support;

use App\Reference\Map\Contracts\MapDataProvider;
use App\Reference\Map\Data\MapOptions;
use App\Reference\Map\Data\MapPayload;
use App\Reference\Map\Data\UniverseRequest;
use App\Reference\Map\Enums\MapScope;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;

/**
 * Decorator that caches map payloads keyed by the SDE build number.
 *
 * Cache invalidation is implicit: the key embeds `ref_snapshot.build_number`
 * fetched by the inner provider, so a new SDE projection produces a new
 * key and old entries simply age out (or get bumped by `forever()` LRU).
 *
 * If the inner payload reports a null build number (e.g. ref_snapshot is
 * empty), we cache under a stable `dev` tag instead — useful for early
 * boot states and unit tests.
 */
class MapCache implements MapDataProvider
{
    public function __construct(
        private readonly MapDataProvider $inner,
        private readonly CacheManager $cache,
        private readonly string $store = 'redis',
        /** Cache TTL in seconds. 0 = forever (rely on key rotation). */
        private readonly int $ttlSeconds = 0,
    ) {}

    public function getUniverse(UniverseRequest $request): MapPayload
    {
        return $this->remember(
            scope: MapScope::UNIVERSE->value,
            args: [
                'detail' => $request->detail->value,
                'options' => $this->fingerprintOptions($request->options),
                'region_ids' => $request->regionIds,
            ],
            resolve: fn () => $this->inner->getUniverse($request),
        );
    }

    public function getRegion(int $regionId, MapOptions $options): MapPayload
    {
        return $this->remember(
            scope: MapScope::REGION->value,
            args: [
                'region_id' => $regionId,
                'options' => $this->fingerprintOptions($options),
            ],
            resolve: fn () => $this->inner->getRegion($regionId, $options),
        );
    }

    public function getConstellation(int $constellationId, MapOptions $options): MapPayload
    {
        return $this->remember(
            scope: MapScope::CONSTELLATION->value,
            args: [
                'constellation_id' => $constellationId,
                'options' => $this->fingerprintOptions($options),
            ],
            resolve: fn () => $this->inner->getConstellation($constellationId, $options),
        );
    }

    public function getSubgraph(array $systemIds, int $hops, MapOptions $options): MapPayload
    {
        $normalised = array_values(array_unique(array_map('intval', $systemIds)));
        sort($normalised);

        return $this->remember(
            scope: MapScope::SUBGRAPH->value,
            args: [
                'system_ids' => $normalised,
                'hops' => $hops,
                'options' => $this->fingerprintOptions($options),
            ],
            resolve: fn () => $this->inner->getSubgraph($systemIds, $hops, $options),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function remember(string $scope, array $args, \Closure $resolve): MapPayload
    {
        $store = $this->store();

        $argHash = sha1(json_encode($args, JSON_THROW_ON_ERROR));

        // First-pass key without build number — we need to call the
        // provider once to learn the current build, then re-key.
        // To avoid that double round-trip, we look up the build number
        // through a tiny TTL'd cache entry (1 minute) so subsequent
        // requests inside the same SDE epoch hit the build-keyed entry
        // directly.
        $build = $store->remember(
            'map:build_number',
            now()->addMinute(),
            fn () => $this->probeBuildNumber($resolve),
        );
        $buildSegment = $build ?? 'dev';

        $key = "map:{$scope}:{$argHash}:{$buildSegment}";

        if ($this->ttlSeconds > 0) {
            return $store->remember($key, $this->ttlSeconds, $resolve);
        }

        return $store->rememberForever($key, $resolve);
    }

    /**
     * Resolve the payload once (uncached) just to harvest its build
     * number. The result is thrown away — the next `remember()` call
     * with the proper key will fetch fresh.
     *
     * In practice this only fires on boot / build rollover (every
     * minute at most); the cost is one extra graph round-trip after a
     * new SDE pull.
     */
    private function probeBuildNumber(\Closure $resolve): ?int
    {
        try {
            return $resolve()->buildNumber;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fingerprintOptions(MapOptions $options): array
    {
        return [
            'projection' => $options->projection->value,
            'jumps' => $options->includeJumps,
            'stations' => $options->includeStations,
            'label_limit' => $options->labelLimit,
        ];
    }

    private function store(): Repository
    {
        return $this->cache->store($this->store);
    }
}
