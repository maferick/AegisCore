# market_importer

Imports [EVE Ref's daily market-history CSV dumps](https://data.everef.net/market-history/)
into the canonical `market_history` MariaDB table (per
[ADR-0003](../../docs/adr/0003-data-placement-freeze.md) +
[ADR-0004](../../docs/adr/0004-market-data-ingest.md)). Ports the
logic of EVE Ref's Java/PostgreSQL
[`import-market-history`](https://docs.everef.net/commands/import-market-history.html)
command to MariaDB + Python — their importer doesn't support MariaDB,
and we have a Python execution plane already.

One-shot container: one invocation = one reconcile pass. Idempotent
by construction — once a day is locally complete, the reconcile
check skips it.

## What it does

1. `GET https://data.everef.net/market-history/totals.json` —
   `{YYYY-MM-DD: row_count}` manifest.
2. Local query: `SELECT trade_date, COUNT(*) FROM market_history GROUP BY trade_date`.
3. Any day inside `[MARKET_IMPORT_MIN_DATE, MARKET_IMPORT_MAX_DATE]`
   where local count < published total (or `--force-redownload`) is
   a target.
4. Per target day, inside one transaction:
   - `GET https://data.everef.net/market-history/{YYYY}/market-history-{YYYY-MM-DD}.csv.bz2`
     (~600 KB compressed, ~2-4 MB uncompressed; held in memory).
   - Parse via `csv.DictReader` — column order in the CSV is not
     load-bearing, we key by the header row.
   - Bulk upsert via `INSERT ... ON DUPLICATE KEY UPDATE` on the
     `(trade_date, region_id, type_id)` PK.
   - Emit one `market.history_snapshot_loaded` outbox event.
   - Commit.

## Run

### Scheduled (default)

The `market_import_scheduler` compose service runs the reconcile
in a loop on a 6-hour cadence. EVE Ref updates the day's CSV
archive throughout the day as their ESI scrape completes; 6h
catches those updates without hammering their server. Starts
automatically with `docker compose up` — no operator action
needed. Tail its logs via:

```sh
make logs-market_import_scheduler
```

Override the cadence per deployment via env:

```
MARKET_IMPORT_INTERVAL_SECONDS=21600   # default — 6h
```

First-run backfill (2025-01-01 → yesterday) still fires on first
tick of the scheduler after it starts; expect an hour or two of
steady-state downloading (~470 days × ~700 KB each ≈ 330 MB). The
per-day-transaction boundary means restarting the container
mid-backfill loses at most one day's progress.

### Ad-hoc (operator)

```sh
make market-import                                           # reconcile from 2025-01-01
make market-import MARKET_IMPORT_ARGS="--dry-run"            # fetch + count, rollback
make market-import MARKET_IMPORT_ARGS="--only-date=2026-04-14"
make market-import MARKET_IMPORT_ARGS="--from=2024-06-01 --to=2024-12-31"
make market-import MARKET_IMPORT_ARGS="--force-redownload"   # bypass reconcile
make market-import MARKET_IMPORT_ARGS="--log-level=DEBUG"
```

## Outbox

Emits `market.history_snapshot_loaded` (producer `market_importer`,
version 1). Payload:

```json
{
  "trade_date":        "2025-01-01",
  "rows_received":     47123,
  "rows_affected":     47123,
  "source":            "everef_market_history",
  "observation_kind":  "historical_dump",
  "loaded_at":         "2026-04-14T11:29:37Z"
}
```

`rows_received` is the count from the CSV; `rows_affected` is
MariaDB's raw `rowcount` under `ON DUPLICATE KEY UPDATE`, which
counts 1 per new insert and 2 per actual update (a
MariaDB/MySQL quirk). Useful as a sanity signal in logs and an audit
number in the outbox; not a clean inserts-vs-updates split.

## Configuration

All env-driven, no dotenv. `infra/docker-compose.yml` passes:

| Env                               | Default                                         | Notes |
| ---                               | ---                                             | --- |
| `DB_HOST`                         | —                                               | required |
| `DB_PORT`                         | `3306`                                          |  |
| `DB_DATABASE`                     | —                                               | required |
| `DB_USERNAME`                     | —                                               | required |
| `DB_PASSWORD`                     | —                                               | required |
| `EVEREF_BASE_URL`                 | `https://data.everef.net/market-history`        | override for a mirror (none currently published) |
| `EVEREF_TOTALS_URL`               | `https://data.everef.net/market-history/totals.json` |  |
| `ESI_USER_AGENT`                  | `AegisCore/0.1 (+ops@example.com)`              | sent as `User-Agent` on EVE Ref fetches |
| `MARKET_IMPORT_MIN_DATE`          | `2025-01-01`                                    | ADR-0004 baseline; rewind for full history |
| `MARKET_IMPORT_MAX_DATE`          | _yesterday (UTC)_                               | today's file is mid-scrape most of the day |
| `MARKET_IMPORT_BATCH_SIZE`        | `5000`                                          | rows per `executemany` |
| `MARKET_IMPORT_DOWNLOAD_TIMEOUT`  | `600`                                           | seconds per daily file |

## First-run expectations

A 2025-01-01 → yesterday backfill is roughly:

- ~470 days × ~700 KB compressed ≈ **330 MB download**.
- ~470 days × ~50 000 rows ≈ **24 M `market_history` rows**.
- Wall-clock: dominated by DB commit latency. On a sane local
  MariaDB, expect single-digit minutes per day. Initial backfill
  runs an hour or two; subsequent runs complete in seconds because
  the reconcile check skips already-loaded days.

Each day is its own transaction, so interrupting + restarting loses
only the in-flight day. Restart picks up exactly where it stopped
via the totals.json reconcile.

## Schema alignment

`market_history` PK is `(trade_date, region_id, type_id)`, monthly
`RANGE` partitioned on `trade_date`. The importer stamps every row
with:

- `source = 'everef_market_history'` — free-text provenance.
- `observation_kind = 'historical_dump'` — typed enum for downstream
  retention/aggregation rules.
- `created_at` / `updated_at` — UTC, set on every upsert.

Partitions cover 2025-01 through 2026-12 explicitly plus a
`p_future VALUES LESS THAN MAXVALUE` catch-all (see the migration).
An operator rewinding `MARKET_IMPORT_MIN_DATE` to before 2025-01
would pile pre-2025 data into the first partition — functional, but
defeats partition pruning for those rows. Add earlier partitions if
you want full history.

## Why our own port

EVE Ref's importer supports PostgreSQL and H2 only; MySQL/MariaDB is
"planned" but not shipped. Rather than stand up PostgreSQL alongside
our MariaDB (breaking ADR-0003 § canonical store) or wait on EVE Ref
to ship MariaDB support, we ported the logic (reconcile against
`totals.json`, per-day transactions, idempotent upserts) in ~500
lines of Python.

## Layout

```
python/
├── market_importer.Dockerfile
├── requirements-market-import.txt
└── market_importer/
    ├── __init__.py
    ├── __main__.py         # `python -m market_importer`
    ├── cli.py              # argparse
    ├── config.py           # env → Config dataclass
    ├── log.py              # stderr kv formatter
    ├── db.py               # pymysql connection helper (autocommit off)
    ├── everef.py           # httpx: totals.json + daily CSV fetches
    ├── parse.py            # bz2 → csv.DictReader → HistoryRow
    ├── persist.py          # INSERT ... ON DUPLICATE KEY UPDATE into market_history
    ├── outbox.py           # emit market.history_snapshot_loaded
    └── runner.py           # orchestrator: reconcile → loop days → commit/rollback
```
