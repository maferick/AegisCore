# battle_partition

Spec 3 — sub-fleet partitioning and membership materialization.

Consumes `battle_character_graph_metrics` rows produced by Spec 2 for
one `(battle_id, alliance_id, edge_profile_version,
algo_profile_version)` combo, applies a deterministic partition rule,
and writes:

- `battle_sub_fleets` header rows (one per sub-fleet)
- `battle_character_sub_fleet_membership` rows (one per pilot)

Every pilot on a processed alliance-side ends up with exactly one
membership row under the active `partition_algo_version`.

## Invocation

```bash
# Default rule (v1_seed: min_community_size=10, orphans → sub_fleet_id=0).
# Graph profile combo is auto-resolved to the most recent successful
# Spec 2 run for the (battle, alliance) pair.
docker compose --env-file .env -f infra/docker-compose.yml \
    --profile tools run --rm battle_partition \
    run --battle-id 142837 --alliance-id 1354830081

# Pin a specific partition rule version (e.g. to re-run under v2 with
# a tighter min_community_size):
docker compose ... --profile tools run --rm battle_partition \
    run --battle-id 142837 --alliance-id 1354830081 \
        --partition-algo-version 2

# Pin a specific Spec 2 profile combo (e.g. to compare two partition
# passes over the same graph):
docker compose ... --profile tools run --rm battle_partition \
    run --battle-id 142837 --alliance-id 1354830081 \
        --edge-profile-version 1 --algo-profile-version 1

# Dry run — computes the partition, logs counts, writes nothing.
docker compose ... --profile tools run --rm battle_partition \
    run --battle-id 142837 --alliance-id 1354830081 --dry-run
```

## Partition rule

The Spec 3 seed (`v1_seed`) configures:

- `min_community_size = 10` — communities smaller than this are
  absorbed into sub-fleet 0 rather than becoming their own sub-fleet
- `orphan_reassignment_rule = 'absorb_into_sub_fleet_zero'` — every
  orphan pilot lands in `sub_fleet_id = 0`, no heuristic
  reassignment

## Sub-fleet ID assignment

Ranking order: `community_size DESC, min_character_id ASC`.

- Ranked promoted communities (size ≥ `min_community_size`) become
  `sub_fleet_id = 0, 1, 2, ...`
- Orphan pilots merge into `sub_fleet_id = 0`
- Sub-fleet 0 is always the largest promoted community plus any
  absorbed orphans; its `absorbed_orphan_count` records how many

## Edge cases

**Small-tier battles** (Spec 2 wrote `skip_reason='below_min_pilots'`):
one sub-fleet with `sub_fleet_id=0`, all pilots assigned with
`assignment_method='small_tier_single_fleet'`.

**Empty-promoted-set battles** (every community below threshold): one
sub-fleet with `sub_fleet_id=0`, all pilots assigned with
`assignment_method='orphan_absorbed'`, `was_orphan=1`.

**Null community_id_raw in a non-small-tier row** (upstream data bug):
treated as a singleton orphan so the pilot still lands in sub-fleet 0
rather than vanishing.

## Concurrency

Spec 3 acquires two MariaDB advisory locks:

1. **Graph-metrics lock** — key shared with Spec 2, derived from
   `(battle_id, alliance_id, edge_profile_version,
    algo_profile_version)`. Blocks up to 30s so mid-write
   Spec 2 runs finish before we read. The sha1 derivation MUST be
   byte-identical to `battle_graph/runs.py::_lock_key`; see the
   cross-reference comment in `battle_partition/db.py`.
2. **Partition lock** — key derived from
   `(battle_id, alliance_id, partition_algo_version)`. Prevents two
   concurrent Spec 3 runs under the same rule.

Both are session-scoped — MariaDB auto-releases on connection close,
so a crashed worker doesn't wedge the tuple.

## Idempotency

UPSERTs keyed on `(battle_id, alliance_id, sub_fleet_id,
partition_algo_version)` for headers and `(battle_id, alliance_id,
character_id, partition_algo_version)` for memberships. Re-running
under the same partition rule produces identical output; runs under
other rule versions coexist.

`computed_at` is explicitly refreshed in every ON DUPLICATE KEY UPDATE
so it tracks the most recent compute pass.

## Partition version immutability

Per Spec 3 § 9: once a `partition_algo_version` row has been used to
produce membership, do NOT modify its configuration in place. Any
rule change requires a new row with a new version id so old
membership remains reproducible from its original rule + the
original graph metrics.

The schema does not enforce this; it is operational discipline.
