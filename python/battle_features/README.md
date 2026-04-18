# battle_features (Spec 4 v1)

One-shot worker that extracts 15 role-inference features per
character for one `(battle_id, alliance_id)`.

- Reads `battle_character_sub_fleet_membership` (Spec 3)
- Reads `battle_sub_fleets` (Spec 3)
- Reads `battle_character_graph_metrics` (Spec 2)
- Reads `battle_theater_killmails` + `killmail_attackers` + `killmails`
- Reads `ship_class_category_mapping`
- Writes `battle_character_role_features`

## Lock protocol

Holds two session-level MariaDB `GET_LOCK`s shared with the producers:

- `graph_metrics_lock_key` — shared with `battle_graph` / `battle_partition`
- `partition_lock_key` — shared with `battle_partition`

Both keys are derived by `sha1` on canonical tuples and MUST stay in
sync with the other modules (see `db.py` docstring).

## Usage

```
docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
    run --rm battle_features run --battle-id 40365 --alliance-id 99011978
```

Optional pinning flags:
- `--partition-algo-version N`
- `--edge-profile-version N --algo-profile-version N`
- `--dry-run`

## Feature set

See `docs/spec4_feature_manifest.md` for the authoritative formulas
and data sources of each feature.
