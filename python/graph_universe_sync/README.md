# graph_universe_sync

Projects EVE Online universe topology from MariaDB `ref_*` tables into
Neo4j. Companion to `python/sde_importer`: that one fills MariaDB; this
one mirrors a derived graph view to Neo4j (per
[ADR-0001](../../docs/adr/0001-static-reference-data.md)).

## Schema produced

| Label / Rel             | Source rows                              |
|-------------------------|-------------------------------------------|
| `(:Region)`             | `ref_regions`                             |
| `(:Constellation)`      | `ref_constellations`                      |
| `(:System)`             | `ref_solar_systems`                       |
| `(:Station)`            | `ref_npc_stations`                        |
| `(:Constellation)-[:IN_REGION]->(:Region)`            | `ref_constellations.region_id`            |
| `(:System)-[:IN_CONSTELLATION]->(:Constellation)`     | `ref_solar_systems.constellation_id`      |
| `(:System)-[:JUMPS_TO]-(:System)`                     | `ref_stargates` (deduped via LEAST/GREATEST) |
| `(:System)-[:HAS_STATION]->(:Station)`                | `ref_npc_stations.solar_system_id`        |

## Run

```sh
make neo4j-sync-universe                    # full projection
make neo4j-sync-universe GRAPH_ARGS="--dry-run"
make neo4j-sync-universe GRAPH_ARGS="--rebuild"
make neo4j-sync-universe GRAPH_ARGS="--only=jumps"
```

## Outbox

Emits `graph.universe_projected` (producer `graph_universe_sync`, version 1).
Payload:

```json
{
  "build_number": 3294658,
  "node_counts":  { "regions": 113, "constellations": 1156, ... },
  "edge_counts":  { "jumps": 7593, ... },
  "projected_at": "2026-04-14T11:29:37Z",
  "only_new_eden": true
}
```

## Phase-2 migration path

This package currently runs as a one-shot container (operator-triggered
or scheduled). Once the outbox-consumer scaffolding lands in the Python
plane, swap the entrypoint to a long-lived consumer of
`reference.sde_snapshot_loaded` and drop the Makefile target.
