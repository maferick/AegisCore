# battle_role_scoring (Spec 5 v0)

One-shot worker that scores role candidates for one
`(battle_id, alliance_id)` under a given `weight_version` and writes
to `battle_character_role_scores` + `battle_character_role_inference`.

## Inputs

- `battle_character_role_features` (Spec 4 output)
- `battle_role_scoring_weights` (coefficient table)

## Outputs

- decomposed score rows per (character, role, score_class) — always
  written
- single-winner inference rows per character — only written when a
  candidate clears its role's threshold + gap

## Usage

```
docker compose --env-file .env -f infra/docker-compose.yml --profile tools \
    run --rm battle_role_scoring run \
    --battle-id 40541 --alliance-id 99011223
```

Optional:
- `--weight-version N` / `--weight-label LABEL`
- `--partition-algo-version N`
- `--dry-run`

Default `weight_label` is `v0_scoring_seed` (Spec 5 seed, `is_default = 0`).

## Concurrency

Holds three session-level MariaDB `GET_LOCK`s:
- `graph_metrics_lock_key` — shared with battle_graph/partition/features
- `partition_lock_key` — shared with battle_partition/features
- `scoring_lock_key` — Spec-5-specific, scoped by weight_version so two
  runs under different weight versions don't block each other

## Extensibility

Adding a future `historical` score component:
1. Implement `compute_historical_score(feature, coefs, prefix)` in `score.py`
2. Register it in `COMPUTE_REGISTRY`
3. Append `'historical'` to `ACTIVE_CLASSES`
4. Seed coefficient rows under a new `weight_version`
5. `final = sum(ACTIVE_CLASSES)` picks the new class up automatically

No schema change needed — the `score_class` CHECK already admits
`'historical'` (Spec 5 schema prep migration).
