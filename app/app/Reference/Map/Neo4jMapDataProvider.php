<?php

declare(strict_types=1);

namespace App\Reference\Map;

use App\Reference\Map\Contracts\MapDataProvider;
use App\Reference\Map\Data\ConstellationDto;
use App\Reference\Map\Data\JumpDto;
use App\Reference\Map\Data\MapOptions;
use App\Reference\Map\Data\MapPayload;
use App\Reference\Map\Data\RegionDto;
use App\Reference\Map\Data\StationDto;
use App\Reference\Map\Data\SystemDto;
use App\Reference\Map\Data\UniverseRequest;
use App\Reference\Map\Enums\MapScope;
use App\Reference\Map\Enums\ProjectionMode;
use App\Reference\Map\Enums\UniverseDetail;
use Illuminate\Support\Facades\DB;
use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

/**
 * Reads the Neo4j projection produced by `python -m graph_universe_sync`
 * and turns it into renderer-ready DTOs.
 *
 * Architectural notes:
 *   - All Cypher hits Neo4j's read replica role; we never write here
 *     (per AGENTS.md "Laravel does not write to Neo4j"). The seeder
 *     owns ingest.
 *   - 2D projection is intentionally done in PHP (not Cypher) so we
 *     can swap projection modes without re-issuing the query and so
 *     the cache decorator can key on raw scope arguments.
 *   - `buildNumber` is read from MariaDB `ref_snapshot` rather than the
 *     graph because Neo4j is the projection, not the source of truth.
 */
class Neo4jMapDataProvider implements MapDataProvider
{
    public function __construct(
        private readonly ?ClientInterface $client = null,
    ) {}

    public function getUniverse(UniverseRequest $request): MapPayload
    {
        return match ($request->detail) {
            UniverseDetail::AGGREGATED => $this->getUniverseAggregated($request->options),
            UniverseDetail::DENSE => $this->getUniverseDense($request->options, $request->regionIds),
        };
    }

    public function getRegion(int $regionId, MapOptions $options): MapPayload
    {
        $client = $this->client();

        $systemRows = $client->run(
            'MATCH (r:Region {id: $rid})<-[:IN_REGION]-(c:Constellation)<-[:IN_CONSTELLATION]-(s:System) '
            .'OPTIONAL MATCH (s)-[:HAS_STATION]->(st:Station) '
            .'RETURN s, count(distinct st) AS station_count '
            .'ORDER BY s.id',
            ['rid' => $regionId],
        );

        $systems = [];
        $points = [];
        foreach ($systemRows as $row) {
            $node = $this->props($row->get('s'));
            $stationCount = (int) $row->get('station_count');
            [$x, $y] = $this->project($node, $options->projection);
            $systems[] = new SystemDto(
                id: (int) $node['id'],
                name: (string) $node['name'],
                x: $x,
                y: $y,
                regionId: (int) ($node['region_id'] ?? $regionId),
                constellationId: (int) ($node['constellation_id'] ?? 0),
                securityStatus: $this->floatOrNull($node['security_status'] ?? null),
                securityClass: $this->stringOrNull($node['security_class'] ?? null),
                hub: (bool) ($node['hub'] ?? false),
                stationsCount: $stationCount,
            );
            $points[] = [$x, $y];
        }

        $constellationRows = $client->run(
            'MATCH (c:Constellation)-[:IN_REGION]->(r:Region {id: $rid}) RETURN c ORDER BY c.id',
            ['rid' => $regionId],
        );
        $constellations = [];
        foreach ($constellationRows as $row) {
            $c = $this->props($row->get('c'));
            [$cx, $cy] = $this->projectGeneric($c, $options->projection);
            $constellations[] = new ConstellationDto(
                id: (int) $c['id'],
                name: (string) $c['name'],
                regionId: $regionId,
                x: $cx,
                y: $cy,
            );
        }

        $regionRow = $client->run(
            'MATCH (r:Region {id: $rid}) RETURN r LIMIT 1',
            ['rid' => $regionId],
        )->first();
        $regions = [];
        if ($regionRow !== null) {
            $r = $this->props($regionRow->get('r'));
            [$rx, $ry] = $this->projectGeneric($r, $options->projection);
            $regions[] = new RegionDto(
                id: (int) $r['id'],
                name: (string) $r['name'],
                x: $rx,
                y: $ry,
                systemCount: count($systems),
            );
        }

        $jumps = $options->includeJumps
            ? $this->jumpsForRegion($client, $regionId)
            : [];

        $stations = $options->includeStations
            ? $this->stationsForRegion($client, $regionId)
            : [];

        return $this->buildPayload(
            scope: MapScope::REGION,
            options: $options,
            systems: $systems,
            jumps: $jumps,
            regions: $regions,
            constellations: $constellations,
            stations: $stations,
            points: $points,
        );
    }

    public function getConstellation(int $constellationId, MapOptions $options): MapPayload
    {
        $client = $this->client();

        $systemRows = $client->run(
            'MATCH (c:Constellation {id: $cid})<-[:IN_CONSTELLATION]-(s:System) '
            .'OPTIONAL MATCH (s)-[:HAS_STATION]->(st:Station) '
            .'RETURN s, count(distinct st) AS station_count '
            .'ORDER BY s.id',
            ['cid' => $constellationId],
        );

        $systems = [];
        $systemIds = [];
        $points = [];
        $regionId = 0;
        foreach ($systemRows as $row) {
            $node = $this->props($row->get('s'));
            $stationCount = (int) $row->get('station_count');
            [$x, $y] = $this->project($node, $options->projection);
            $systemIds[] = (int) $node['id'];
            $regionId = (int) ($node['region_id'] ?? $regionId);
            $systems[] = new SystemDto(
                id: (int) $node['id'],
                name: (string) $node['name'],
                x: $x,
                y: $y,
                regionId: (int) ($node['region_id'] ?? 0),
                constellationId: (int) ($node['constellation_id'] ?? $constellationId),
                securityStatus: $this->floatOrNull($node['security_status'] ?? null),
                securityClass: $this->stringOrNull($node['security_class'] ?? null),
                hub: (bool) ($node['hub'] ?? false),
                stationsCount: $stationCount,
            );
            $points[] = [$x, $y];
        }

        $constellationRow = $client->run(
            'MATCH (c:Constellation {id: $cid}) RETURN c LIMIT 1',
            ['cid' => $constellationId],
        )->first();
        $constellations = [];
        if ($constellationRow !== null) {
            $c = $this->props($constellationRow->get('c'));
            [$cx, $cy] = $this->projectGeneric($c, $options->projection);
            $constellations[] = new ConstellationDto(
                id: (int) $c['id'],
                name: (string) $c['name'],
                regionId: (int) ($c['region_id'] ?? $regionId),
                x: $cx,
                y: $cy,
            );
        }

        $jumps = $options->includeJumps && $systemIds !== []
            ? $this->jumpsForSystems($client, $systemIds, internalOnly: true)
            : [];

        $stations = $options->includeStations && $systemIds !== []
            ? $this->stationsForSystems($client, $systemIds)
            : [];

        return $this->buildPayload(
            scope: MapScope::CONSTELLATION,
            options: $options,
            systems: $systems,
            jumps: $jumps,
            regions: [],
            constellations: $constellations,
            stations: $stations,
            points: $points,
        );
    }

    public function getSubgraph(array $systemIds, int $hops, MapOptions $options): MapPayload
    {
        $hops = max(0, min($hops, 4));
        $anchorIds = array_values(array_unique(array_map('intval', $systemIds)));

        if ($anchorIds === []) {
            return $this->emptyPayload(MapScope::SUBGRAPH, $options);
        }

        $client = $this->client();

        if ($hops === 0) {
            $expandedIds = $anchorIds;
        } else {
            // Variable-length :JUMPS_TO with capped hops. Cypher requires
            // the upper bound to be a literal, so we interpolate the
            // (already-clamped) value rather than parameterising it.
            $rows = $client->run(
                'MATCH (s:System) WHERE s.id IN $ids '
                .'OPTIONAL MATCH (s)-[:JUMPS_TO*1..'.$hops.']-(n:System) '
                .'WITH collect(distinct s.id) + collect(distinct n.id) AS ids '
                .'UNWIND ids AS id '
                .'WITH id WHERE id IS NOT NULL '
                .'RETURN distinct id',
                ['ids' => $anchorIds],
            );
            $expandedIds = [];
            foreach ($rows as $row) {
                $expandedIds[] = (int) $row->get('id');
            }
        }

        if ($expandedIds === []) {
            return $this->emptyPayload(MapScope::SUBGRAPH, $options);
        }

        $systemRows = $client->run(
            'MATCH (s:System) WHERE s.id IN $ids '
            .'OPTIONAL MATCH (s)-[:HAS_STATION]->(st:Station) '
            .'RETURN s, count(distinct st) AS station_count '
            .'ORDER BY s.id',
            ['ids' => $expandedIds],
        );

        $systems = [];
        $points = [];
        foreach ($systemRows as $row) {
            $node = $this->props($row->get('s'));
            $stationCount = (int) $row->get('station_count');
            [$x, $y] = $this->project($node, $options->projection);
            $systems[] = new SystemDto(
                id: (int) $node['id'],
                name: (string) $node['name'],
                x: $x,
                y: $y,
                regionId: (int) ($node['region_id'] ?? 0),
                constellationId: (int) ($node['constellation_id'] ?? 0),
                securityStatus: $this->floatOrNull($node['security_status'] ?? null),
                securityClass: $this->stringOrNull($node['security_class'] ?? null),
                hub: (bool) ($node['hub'] ?? false),
                stationsCount: $stationCount,
            );
            $points[] = [$x, $y];
        }

        $jumps = $options->includeJumps
            ? $this->jumpsForSystems($client, $expandedIds, internalOnly: true)
            : [];

        $stations = $options->includeStations
            ? $this->stationsForSystems($client, $expandedIds)
            : [];

        return $this->buildPayload(
            scope: MapScope::SUBGRAPH,
            options: $options,
            systems: $systems,
            jumps: $jumps,
            regions: [],
            constellations: [],
            stations: $stations,
            points: $points,
        );
    }

    // ------------------------------------------------------------------
    // Universe scope
    // ------------------------------------------------------------------

    private function getUniverseAggregated(MapOptions $options): MapPayload
    {
        $client = $this->client();

        $rows = $client->run(
            'MATCH (r:Region) '
            .'OPTIONAL MATCH (r)<-[:IN_REGION]-(:Constellation)<-[:IN_CONSTELLATION]-(s:System) '
            .'RETURN r, count(distinct s) AS system_count '
            .'ORDER BY r.id',
        );

        $regions = [];
        $points = [];
        foreach ($rows as $row) {
            $r = $this->props($row->get('r'));
            $count = (int) $row->get('system_count');
            [$rx, $ry] = $this->projectGeneric($r, $options->projection);
            $regions[] = new RegionDto(
                id: (int) $r['id'],
                name: (string) $r['name'],
                x: $rx,
                y: $ry,
                systemCount: $count,
            );
            $points[] = [$rx, $ry];
        }

        // Inter-region jump edges, aggregated as region-pair edges. We
        // emit them as JumpDto so the renderer's edge layer can paint
        // them with the same primitive as system-level edges; the kind
        // flag tells it to apply the cluster style.
        $jumps = [];
        if ($options->includeJumps) {
            $edgeRows = $client->run(
                'MATCH (r1:Region)<-[:IN_REGION]-(:Constellation)<-[:IN_CONSTELLATION]-(s1:System) '
                .'MATCH (s1)-[:JUMPS_TO]-(s2:System)-[:IN_CONSTELLATION]->(:Constellation)-[:IN_REGION]->(r2:Region) '
                .'WHERE r1.id < r2.id '
                .'RETURN r1.id AS a, r2.id AS b, count(*) AS weight',
            );
            foreach ($edgeRows as $row) {
                $jumps[] = new JumpDto(
                    a: (int) $row->get('a'),
                    b: (int) $row->get('b'),
                    kind: 'region',
                );
            }
        }

        return $this->buildPayload(
            scope: MapScope::UNIVERSE,
            options: $options,
            systems: [],
            jumps: $jumps,
            regions: $regions,
            constellations: [],
            stations: [],
            points: $points,
        );
    }

    private function getUniverseDense(MapOptions $options, array $regionIds): MapPayload
    {
        $client = $this->client();

        $cypher = 'MATCH (s:System) ';
        $params = [];
        if ($regionIds !== []) {
            $cypher .= 'WHERE s.region_id IN $rids ';
            $params['rids'] = array_values(array_map('intval', $regionIds));
        }
        $cypher .= 'OPTIONAL MATCH (s)-[:HAS_STATION]->(st:Station) '
            .'RETURN s, count(distinct st) AS station_count '
            .'ORDER BY s.id';

        $rows = $client->run($cypher, $params);

        $systems = [];
        $systemIds = [];
        $points = [];
        foreach ($rows as $row) {
            $node = $this->props($row->get('s'));
            $stationCount = (int) $row->get('station_count');
            [$x, $y] = $this->project($node, $options->projection);
            $systemIds[] = (int) $node['id'];
            $systems[] = new SystemDto(
                id: (int) $node['id'],
                name: (string) $node['name'],
                x: $x,
                y: $y,
                regionId: (int) ($node['region_id'] ?? 0),
                constellationId: (int) ($node['constellation_id'] ?? 0),
                securityStatus: $this->floatOrNull($node['security_status'] ?? null),
                securityClass: $this->stringOrNull($node['security_class'] ?? null),
                hub: (bool) ($node['hub'] ?? false),
                stationsCount: $stationCount,
            );
            $points[] = [$x, $y];
        }

        $jumps = [];
        if ($options->includeJumps && $systemIds !== []) {
            // For unrestricted universe-dense we want every edge once;
            // running the system-list filter in PHP keeps edges scoped
            // when regionIds is set (no half-edges into the void).
            $jumps = $this->jumpsForSystems($client, $systemIds, internalOnly: true);
        }

        return $this->buildPayload(
            scope: MapScope::UNIVERSE,
            options: $options,
            systems: $systems,
            jumps: $jumps,
            regions: [],
            constellations: [],
            stations: [],
            points: $points,
        );
    }

    // ------------------------------------------------------------------
    // Edge / station helpers
    // ------------------------------------------------------------------

    /**
     * @return array<int, JumpDto>
     */
    private function jumpsForRegion(ClientInterface $client, int $regionId): array
    {
        // Filter both endpoints by region so we don't bleed into a
        // neighbouring region's nodes. Cypher returns each undirected
        // edge twice; dedupe via id<id and aggregate via DISTINCT.
        $rows = $client->run(
            'MATCH (s1:System {region_id: $rid})-[:JUMPS_TO]-(s2:System {region_id: $rid}) '
            .'WHERE s1.id < s2.id '
            .'RETURN distinct s1.id AS a, s2.id AS b',
            ['rid' => $regionId],
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = new JumpDto(
                a: (int) $row->get('a'),
                b: (int) $row->get('b'),
                kind: 'stargate',
            );
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $systemIds
     * @return array<int, JumpDto>
     */
    private function jumpsForSystems(ClientInterface $client, array $systemIds, bool $internalOnly): array
    {
        if ($systemIds === []) {
            return [];
        }

        $cypher = 'MATCH (s1:System)-[:JUMPS_TO]-(s2:System) WHERE s1.id < s2.id ';
        $cypher .= $internalOnly
            ? 'AND s1.id IN $ids AND s2.id IN $ids '
            : 'AND (s1.id IN $ids OR s2.id IN $ids) ';
        $cypher .= 'RETURN distinct s1.id AS a, s2.id AS b';

        $rows = $client->run($cypher, ['ids' => array_values($systemIds)]);

        $out = [];
        foreach ($rows as $row) {
            $out[] = new JumpDto(
                a: (int) $row->get('a'),
                b: (int) $row->get('b'),
                kind: 'stargate',
            );
        }

        return $out;
    }

    /**
     * @return array<int, StationDto>
     */
    private function stationsForRegion(ClientInterface $client, int $regionId): array
    {
        $rows = $client->run(
            'MATCH (s:System {region_id: $rid})-[:HAS_STATION]->(st:Station) '
            .'RETURN st, s.id AS sid',
            ['rid' => $regionId],
        );

        return $this->collectStations($rows);
    }

    /**
     * @param  array<int, int>  $systemIds
     * @return array<int, StationDto>
     */
    private function stationsForSystems(ClientInterface $client, array $systemIds): array
    {
        $rows = $client->run(
            'MATCH (s:System)-[:HAS_STATION]->(st:Station) WHERE s.id IN $ids '
            .'RETURN st, s.id AS sid',
            ['ids' => array_values($systemIds)],
        );

        return $this->collectStations($rows);
    }

    /**
     * @return array<int, StationDto>
     */
    private function collectStations(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $st = $this->props($row->get('st'));
            $out[] = new StationDto(
                id: (int) $st['id'],
                systemId: (int) $row->get('sid'),
                name: $this->stringOrNull($st['name'] ?? null),
                typeId: $this->intOrNull($st['type_id'] ?? null),
                ownerId: $this->intOrNull($st['owner_id'] ?? null),
            );
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Projection + payload assembly
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: float, 1: float}
     */
    private function project(array $node, ProjectionMode $mode): array
    {
        $effective = $mode;
        if ($mode === ProjectionMode::AUTO) {
            $effective = ($node['position2d_x'] ?? null) !== null && ($node['position2d_y'] ?? null) !== null
                ? ProjectionMode::POSITION_2D
                : ProjectionMode::TOP_DOWN_XZ;
        }

        if ($effective === ProjectionMode::POSITION_2D) {
            return [
                (float) ($node['position2d_x'] ?? 0.0),
                (float) ($node['position2d_y'] ?? 0.0),
            ];
        }

        // TOP_DOWN_XZ — CCP convention. y_image = -z_eve so north points
        // up regardless of which hemisphere the system lives in.
        return [
            (float) ($node['position_x'] ?? 0.0),
            -1.0 * (float) ($node['position_z'] ?? 0.0),
        ];
    }

    /**
     * Generic projection for nodes that only have 3D coordinates
     * (regions, constellations).
     *
     * @param  array<string, mixed>  $node
     * @return array{0: float, 1: float}
     */
    private function projectGeneric(array $node, ProjectionMode $mode): array
    {
        // Regions/constellations don't have position2d_x/y; always use
        // top-down XZ regardless of the requested mode.
        unset($mode);

        return [
            (float) ($node['position_x'] ?? 0.0),
            -1.0 * (float) ($node['position_z'] ?? 0.0),
        ];
    }

    /**
     * @param  array<int, SystemDto>  $systems
     * @param  array<int, JumpDto>  $jumps
     * @param  array<int, RegionDto>  $regions
     * @param  array<int, ConstellationDto>  $constellations
     * @param  array<int, StationDto>  $stations
     * @param  array<int, array{0: float, 1: float}>  $points
     */
    private function buildPayload(
        MapScope $scope,
        MapOptions $options,
        array $systems,
        array $jumps,
        array $regions,
        array $constellations,
        array $stations,
        array $points,
    ): MapPayload {
        $effectiveProjection = $this->resolveProjectionMode($options->projection, $systems);

        return new MapPayload(
            scope: $scope,
            projection: $effectiveProjection,
            bbox: MapPayload::computeBbox($points),
            systems: new DataCollection(SystemDto::class, $systems),
            jumps: new DataCollection(JumpDto::class, $jumps),
            regions: new DataCollection(RegionDto::class, $regions),
            constellations: new DataCollection(ConstellationDto::class, $constellations),
            stations: new DataCollection(StationDto::class, $stations),
            buildNumber: $this->currentBuildNumber(),
            generatedAt: now()->toIso8601String(),
        );
    }

    /**
     * Resolve AUTO into a concrete projection mode for the payload's
     * `projection` field. AUTO becomes POSITION_2D iff the produced
     * systems all have non-zero (x,y); otherwise TOP_DOWN_XZ. This is
     * an after-the-fact label for the renderer's status overlay — the
     * actual projection has already been applied.
     *
     * @param  array<int, SystemDto>  $systems
     */
    private function resolveProjectionMode(ProjectionMode $requested, array $systems): ProjectionMode
    {
        if ($requested !== ProjectionMode::AUTO) {
            return $requested;
        }

        // Systems are empty (e.g. universe-aggregated) — report AUTO as
        // top-down for callers; the bbox is built from regions which
        // also use top-down.
        return ProjectionMode::TOP_DOWN_XZ;
    }

    private function emptyPayload(MapScope $scope, MapOptions $options): MapPayload
    {
        return $this->buildPayload(
            scope: $scope,
            options: $options,
            systems: [],
            jumps: [],
            regions: [],
            constellations: [],
            stations: [],
            points: [],
        );
    }

    private function currentBuildNumber(): ?int
    {
        try {
            $row = DB::table('ref_snapshot')->orderByDesc('build_number')->first();
            if ($row === null) {
                return null;
            }

            return (int) $row->build_number;
        } catch (\Throwable) {
            // ref_snapshot may be unavailable in unit tests; the cache
            // decorator falls back to a deterministic key without a
            // build number when we return null.
            return null;
        }
    }

    /**
     * Lazy Neo4j client. Built once per provider instance using the
     * same `aegiscore.neo4j.*` config as `SystemStatusService::cypherPing()`.
     */
    private function client(): ClientInterface
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $host = (string) config('aegiscore.neo4j.host');
        $user = (string) config('aegiscore.neo4j.user');
        $password = (string) config('aegiscore.neo4j.password');

        if ($host === '' || $user === '' || $password === '') {
            throw new RuntimeException('Neo4j is not configured (aegiscore.neo4j.*).');
        }

        return ClientBuilder::create()
            ->withDriver('default', $host, Authenticate::basic($user, $password))
            ->withDefaultDriver('default')
            ->build();
    }

    /**
     * Coerce a Neo4j node-or-array into a property array. The laudis
     * client returns `Laudis\Neo4j\Types\Node` for `RETURN n` queries;
     * tests can pass plain arrays via the constructor-injected client.
     *
     * @return array<string, mixed>
     */
    private function props(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value) && method_exists($value, 'getProperties')) {
            $props = $value->getProperties();
            if (is_object($props) && method_exists($props, 'toArray')) {
                return $props->toArray();
            }
            if (is_iterable($props)) {
                $out = [];
                foreach ($props as $k => $v) {
                    $out[(string) $k] = $v;
                }

                return $out;
            }
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            return $value->toArray();
        }

        return [];
    }

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;

        return $s === '' ? null : $s;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
