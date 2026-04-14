# `App\Reference\Map`

EVE map renderer module — fetches cosmic geography from the Neo4j
projection (`graph_universe_sync`) and hands the JS frontend a small,
self-describing JSON payload (`MapPayload`) that the D3 renderer can
draw without further round-trips.

## Layered structure

```
Contracts/  -- interface (MapDataProvider) + DTO contracts.
Data/       -- spatie/laravel-data DTOs (MapPayload, SystemDto, ...).
Enums/      -- MapScope, ProjectionMode, UniverseDetail.
Support/    -- MapCache (decorator), helpers.
Providers/  -- MapServiceProvider; registers the bind.

Neo4jMapDataProvider.php  -- production implementation.
```

## Why a provider interface?

Three forces:

1. **Testability** — `MapDataController` depends on the interface, so
   feature tests inject an array-backed fake without spinning up Neo4j.
2. **Phase migration** — when ESI live data lands we'll layer a
   `LiveOverlayMapDataProvider` that decorates Neo4j with kill / fleet
   activity. The renderer doesn't change.
3. **Fallback** — if Neo4j is down for maintenance we can flip the bind
   to a thinner `MariaDbMapDataProvider` that reads from the same
   `ref_*` tables; output shape is identical.

## Adding a new provider

1. Implement `Contracts\MapDataProvider`.
2. Register it in `Providers\MapServiceProvider::register()`, ideally
   wrapped by `MapCache` to inherit free invalidation on SDE rollover.
3. Add a Mockery-backed unit test mirroring `Neo4jMapDataProviderTest`.

## Projection modes

| Mode            | Source columns                      | Used when                             |
|-----------------|-------------------------------------|---------------------------------------|
| `TOP_DOWN_XZ`   | `position_x`, `-position_z`         | Default, always works.                |
| `POSITION_2D`   | `position2d_x`, `position2d_y`      | When CCP schematic positions exist.   |
| `AUTO`          | Picks `POSITION_2D` if populated.   | Renderer + Filament page default.     |

The provider applies the projection in PHP so a single Cypher result
can be re-served under any mode without re-querying. Region and
constellation centroids always use top-down — CCP doesn't ship 2D
positions for those.

## Cache key shape

```
map:{scope}:{sha1(args_json)}:{build_number|dev}
```

`build_number` comes from `ref_snapshot.build_number`. New SDE pull →
new build → fresh keys; old keys age out via Redis LRU. The decorator
lives in `Support\MapCache` and is opt-out (test environments use the
`array` store).

## LY-overlay extension point

Phase 2 will add capital jump-drive ranges (9.46 × 10^15 m per LY).
The hook is intentionally left unconnected: extend `MapPayload` with
an `overlays` field and have the renderer's overlay layer read it.
The provider stays unchanged; `LyOverlayDecorator` does the math.

## See also

- `python/graph_universe_sync/README.md` — projection details + outbox
  payload.
- `app/public/js/map-renderer/README.md` — vendored D3, render pipeline.
- `docs/adr/0001-static-reference-data.md` — graph projection contract.
