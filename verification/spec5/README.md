# Spec 5 v0 verification

Structural + determinism checks for the role-scoring worker. Does
**not** verify assignment accuracy — the v0 coefficients are
explicitly uncalibrated.

## Files

| File                          | Purpose                                           |
|-------------------------------|---------------------------------------------------|
| `run_batch.sh`                | Run battle_role_scoring on the 8 validation battles |
| `semantic_checks.sql`         | Structural / determinism queries                  |
| `last_run_output.txt`         | Captured output from the most recent check run   |
| `diagnostic_first_run.md`     | Operator-written observations — **Spec 7 input**  |

## How to run

```
# 1. Run scoring on all 8 validation battles
bash verification/spec5/run_batch.sh           # logs to /tmp/spec5_batch.log

# 2. Run structural checks
docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u root -p"$MARIADB_ROOT_PASSWORD" aegiscore < verification/spec5/semantic_checks.sql
```

## Pass criteria

- structural queries (1–6) return zero violations
- outcome summary (7) matches the counts in `diagnostic_first_run.md`
- re-running `run_batch.sh` produces byte-identical score values

Accuracy is **not** a pass criterion for Spec 5. See
`diagnostic_first_run.md` for calibration observations.
