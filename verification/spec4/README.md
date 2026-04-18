# Spec 4 verification

Reproducible checks that Spec 4 (role feature extraction v1) is
computing correctly on live data. These exist so the next spec author
can re-run the same checks after any change to `battle_features/`,
the feature schema, or the partition upstream.

## How to run

Prerequisite: Specs 2 + 3 have run successfully for every
`(battle_id, alliance_id)` pair in `run_batch.sh`.

```
# 1. Re-extract features for the 8 validation battles
bash verification/spec4/run_batch.sh    # logs to /tmp/spec4_batch.log

# 2. Run bounds + semantic + per-battle SQL checks
docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u root -p"$MARIADB_ROOT_PASSWORD" aegiscore < verification/spec4/semantic_checks.sql
```

## Files

| File | Purpose |
|------|---------|
| `run_batch.sh`                | Fans `battle_features run` across the 8 cases |
| `semantic_checks.sql`         | Bounds + per-sub-fleet aggregates + per-battle expectations |
| `monitor_audit_40541.md`      | Hand-reconstruction of all 15 features for one pilot (Monitor FC, battle 40541) with stored-vs-computed deltas |

## Validation battles (2026-04-18 run)

Snapshot of what each checks:

| battle_id | alliance_id  | label                 | expectation                                           |
|-----------|-------------|-----------------------|-------------------------------------------------------|
| 40365     | 99011978    | Amamake-99011978      | 3 sub-fleets, mixed doctrines                         |
| 40228     | 99014027    | Aldranette-99014027   | 2 sub-fleets                                          |
| 40374     | 99003581    | 2E-ZR5 / Frat         | 4 sub-fleets, large roster (174 pilots)               |
| 40541     | 99011223    | U-L4KS / Sigma        | Monitor FC in sub-fleet 1; 18-pilot sub-fleet         |
| 40478     | 99003581    | Atioth / Frat         | Sub-fleet 2 = pure bomber wing (10 bombers + 1 other) |
| 40537     | 1900696668  | Komo-99011978         | 2 sub-fleets                                          |
| 40605     | 99012122    | 9S-GPT / 99012122     | Nightmare+Scimitar fleet (12 logi in sub-fleet 0)     |
| 40553     | 99011223    | 6RQ9-A / Sigma        | Single cohesive sub-fleet                             |

## What "pass" means

The `semantic_checks.sql` output should be all-zero in the violation
sections (empty mismatch result sets, 0 out-of-range rows). Per-battle
assertion outputs should match the narrative rows in this README.

Two notes on precision and intuition:

- `damage_share` and `subfleet_damage_share_of_side` store as
  `DECIMAL(5,4)`. Summation tolerance is `N * 5e-5 + 1e-6` (N = row
  count in the group), not `1e-6`. 7 of 8 sides sum to exactly 1.000000
  on the 2026-04-18 run; one rounds to 1.000100.
- The Monitor FC in battle 40541 has `presence_span = 0.0`,
  `early_presence = 0`, `late_presence = 0` and is in the **bottom** of
  sub-fleet 1 by `degree_centrality`. Spec 4 compute is correct
  (verified in `monitor_audit_40541.md`); the Monitor pilot is only
  attributed to one killmail in the killmail record because Monitors
  neither deal damage nor die. Contract intuition that "FCs are present
  throughout" does not hold for Monitor-class FCs in this data. Any
  downstream role-inference that keys on presence features alone will
  misclassify Monitor FCs and must use a different signal.
