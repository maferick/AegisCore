# battle_graph

Spec 2 — battle-scoped Neo4j graph projection + metrics write-back.

Takes one `(battle_id, alliance_id)` pair, projects the alliance-side
pilot graph into Neo4j GDS from MariaDB data, runs graph algorithms
(weighted degree, PageRank, Louvain; optional betweenness / clustering
coefficient), and writes raw metrics to `battle_character_graph_metrics`
in MariaDB.

## Design bets

- **One alliance-side per invocation.** Role inference is per-side;
  cross-alliance edges are not informative for sub-fleet detection.
- **Neo4j as ephemeral compute.** Project → compute → drop, every
  run. MariaDB holds truth.
- **Profile-versioned.** Edge construction and algorithm toggles are
  both addressable by a profile version; every metrics row records
  the (edge_profile_version, algo_profile_version) it was produced
  under so different profiles coexist rather than overwrite.
- **Python owns ETL, Neo4j runs algorithms.** Bucket / victim /
  phase computation happens in Python from MariaDB inputs. Neo4j
  never sees raw event data.

## Invocation

```bash
# Default profile (v1_seed), one side of one battle:
docker compose --env-file .env -f infra/docker-compose.yml \
    --profile tools run --rm battle_graph \
    run --battle-id 101976 --alliance-id 1354830081

# Explicit profile versions (for A/B / rescoring):
docker compose ... --profile tools run --rm battle_graph \
    run --battle-id 101976 --alliance-id 1354830081 \
        --edge-profile-version 2 --algo-profile-version 1

# Dry run — no audit row, no writes, logs intent only:
docker compose ... --profile tools run --rm battle_graph \
    run --battle-id 101976 --alliance-id 1354830081 --dry-run
```

## Profiles

Both edge and algo profiles follow Spec 1's weight-version pattern:
auto-increment id, unique label, single-default enforcement via a
virtual generated column. The seeded `v1_seed` row is the default for
both and is uncalibrated.

**Edge profile** tunes:

- `bucket_seconds` — time resolution for co-presence (default 30)
- `phase_seconds` — minimum gap between phases (default 300)
- `same_bucket_coef` / `victim_overlap_coef` / `phase_cooccur_coef` —
  linear combination coefficients (must sum to 1.0 ±1e-4)
- `min_edge_weight` — edges below this aren't materialised

**Algo profile** toggles:

- `run_pagerank` / `run_louvain` (on by default)
- `run_betweenness` / `run_clustering_coefficient` (off by default;
  expensive at medium+ tiers)
- PageRank + Louvain tuning knobs
- Tier thresholds: `small_tier_max` / `medium_tier_max` / `large_tier_max`

## Tier policy

| Tier | Pilot count | Algorithms |
|------|---|---|
| small | ≤ `small_tier_max` (default 10) | skip — one row per pilot with `skip_reason='below_min_pilots'` |
| medium | ≤ `medium_tier_max` (default 500) | weighted degree + PageRank + Louvain |
| large | ≤ `large_tier_max` (default 2000) | weighted degree + PageRank + Louvain |
| huge | \> `large_tier_max` | weighted degree only; PageRank + Louvain behind algo-profile flags |

Clustering coefficient and betweenness run only when their profile
flag is set, regardless of tier.

## Idempotency

Metrics write is `INSERT ... ON DUPLICATE KEY UPDATE` keyed on
`(battle_id, alliance_id, character_id, edge_profile_version,
 algo_profile_version)`. Re-running overwrites rows under the same
profile combo only; rows under other combos are preserved.
`computed_at` is explicitly refreshed to the most recent run.

`community_id_raw` is a within-run identifier only — Louvain labels
aren't stable across runs even with a fixed seed. Consumers compare on
`community_rank_by_size` (0 = largest community, size-desc with
deterministic tie-break on lowest member character_id).

## Concurrency

The job rejects a second invocation when another run is already
`running` on the same `(battle_id, alliance_id, edge_profile_version,
algo_profile_version)` tuple. Different combinations can run
concurrently.

## Cleanup

GDS projection + Neo4j nodes / edges tagged with the run's `run_id`
are dropped on both the success and failure paths. Failed runs leave
the `battle_graph_projection_runs` row with `status='failed'` and an
error message; no partial metrics rows land in MariaDB.
