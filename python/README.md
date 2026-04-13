# python/

Python workers that live on the **import plane** of AegisCore, outside
the Laravel monolith. See [ADR-0001](../docs/adr/0001-static-reference-data.md)
for the plane boundary that puts them here.

Phase 1 ships one worker:

## `sde_importer/`

One-shot importer for CCP's EVE Static Data Export. Invoked from the
host via `make sde-import`, which boots the container from the `tools`
compose profile:

```
make sde-import                 # download + load (normal path)
make sde-import SDE_ARGS="--only-download"   # fetch + extract, skip DB
make sde-import SDE_ARGS="--skip-download"   # reuse last extract dir
```

### What it does

1. Streams the SDE zip from `SDE_SOURCE_URL` to disk (defaults to the
   `eve-online-static-data-latest-jsonl.zip` endpoint).
2. Unzips into `SDE_WORK_DIR` (defaults to `/tmp/sde`).
3. Reads `_sde.jsonl` for the build identity (`buildNumber`,
   `releaseDate`).
4. Opens **one** MariaDB transaction:
   - `DELETE` all 44 `ref_*` tables.
   - For each of the 56 JSONL files, streams rows and bulk-INSERTs in
     batches of `SDE_BATCH_SIZE` (default 2000).
   - Replaces the `ref_snapshot` row.
   - Inserts a `reference.sde_snapshot_loaded` row into `outbox`.
   - `COMMIT`.
5. Writes the upstream ETag (or build number) to
   `/var/www/sde/version.txt` so the drift-check widget has something
   to diff against.

Any error before step 5 → `ROLLBACK`; nothing leaks.

### Why Python (and not a Laravel queue job)?

The import touches ~664k rows across 56 files in a single transaction.
That violates the plane boundary policy for Laravel queues (<2s per
job, <100 rows per job — `docs/CONTRACTS.md`). Rather than shred the
load into chunks and lose the "one atomic snapshot" guarantee, we keep
it as one Python process with one MariaDB connection.

The Laravel side consumes the outbox event — `reference.sde_snapshot_loaded`
— asynchronously, and that's where any downstream cache invalidation,
Horizon fan-out, or user-facing notification happens.

### Configuration

All env-driven. No YAML, no dotenv-in-image. `infra/docker-compose.yml`
passes:

| Env                   | Default                                                                          | Notes                                                       |
| ---                   | ---                                                                              | ---                                                         |
| `DB_HOST`             | —                                                                                | required                                                    |
| `DB_PORT`             | `3306`                                                                           |                                                             |
| `DB_DATABASE`         | —                                                                                | required                                                    |
| `DB_USERNAME`         | —                                                                                | required                                                    |
| `DB_PASSWORD`         | —                                                                                | required                                                    |
| `SDE_SOURCE_URL`      | `https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip` | override for a mirror                                       |
| `SDE_VERSION_FILE`    | `/var/www/sde/version.txt`                                                       | written on success                                          |
| `SDE_WORK_DIR`        | `/tmp/sde`                                                                       | scratch — not persisted                                     |
| `SDE_DOWNLOAD_TIMEOUT`| `600`                                                                            | seconds, full-download timeout                              |
| `SDE_BATCH_SIZE`      | `2000`                                                                           | rows per `executemany`                                      |

### Adding a new SDE table

When CCP adds a new JSONL file you want imported:

1. Add a Laravel migration under `app/database/migrations/` for the new
   `ref_*` table (follow the shape rules in the migration header comments).
2. Append a `TableSpec` to `SPECS` in
   [`sde_importer/schema.py`](sde_importer/schema.py). No loader code
   changes — the spec is declarative.
3. `make sde-import`. On success the new table is populated and the
   outbox event's `tables` payload includes the new name.

### Layout

```
python/
├── Dockerfile              # python:3.12-slim, non-root app user
├── requirements.txt        # httpx, pymysql, python-ulid
└── sde_importer/
    ├── __init__.py
    ├── __main__.py         # `python -m sde_importer`
    ├── cli.py              # argparse; entrypoint
    ├── config.py           # env → Config dataclass
    ├── log.py              # stderr kv formatter
    ├── db.py               # pymysql connection helper
    ├── download.py         # stream + unzip SDE
    ├── schema.py           # declarative spec: file → table → columns
    ├── loader.py           # generic spec-driven bulk loader
    ├── outbox.py           # emit `reference.sde_snapshot_loaded`
    └── runner.py           # orchestrator: acquire → load → commit → pin
```
