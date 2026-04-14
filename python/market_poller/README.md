# market_poller

Pulls live order-book snapshots from ESI into the canonical
`market_orders` MariaDB table (per
[ADR-0003](../../docs/adr/0003-data-placement-freeze.md) and
[ADR-0004](../../docs/adr/0004-market-data-ingest.md)).

One-shot container: one invocation = one pass. The calling cadence
lives outside this package (Laravel scheduler, cron, systemd timer
— operator's call).

## Phase 1 scope

Phase 1 handles **NPC stations only**:

- Read enabled rows from `market_watched_locations` where
  `location_type = 'npc_station'`.
- For each row, fetch `/markets/{region_id}/orders/` (no auth), paginate
  via `X-Pages`, client-side filter to the row's `location_id`, bulk
  `INSERT IGNORE` into `market_orders`.
- Stamp `observation_kind = 'snapshot'`,
  `source = esi_region_<region_id>_<location_id>`,
  `observed_at = now(UTC)` (shared across the whole pass).
- Emit one `market.orders_snapshot_ingested` outbox event per
  successful location poll.

**Jita 4-4 is the seeded baseline** (region 10000002, location
60003760) and runs on every pass.

Admin-owned and donor-owned structure polling (auth'd via
`eve_service_tokens` / `eve_market_tokens`) lands in later rollout
steps of ADR-0004 without changing this package's shape — the runner
branches on `location_type`.

## Run

```sh
make market-poll                                 # one pass, all enabled rows
make market-poll MARKET_ARGS="--dry-run"         # fetch + log, no inserts
make market-poll MARKET_ARGS="--only-location-id=60003760"    # Jita only
make market-poll MARKET_ARGS="--log-level=DEBUG"
```

## Outbox

Emits `market.orders_snapshot_ingested` (producer `market_poller`,
version 1). Payload:

```json
{
  "source": "esi_region_10000002_60003760",
  "region_id": 10000002,
  "location_id": 60003760,
  "location_type": "npc_station",
  "observed_at": "2026-04-14T11:29:37Z",
  "rows_received": 154231,
  "rows_inserted": 9874,
  "duration_ms": 4821
}
```

`rows_received` counts orders across every ESI page of the region;
`rows_inserted` is the subset that matched the location filter AND
wasn't a duplicate against an existing
`(observed_at, source, location_id, order_id)` row. A wide delta for
an NPC row is normal and means the region-wide fetch had lots of
orders we discarded (minor hubs co-located in the same region).

## Configuration

All env-driven, no dotenv. `infra/docker-compose.yml` passes:

| Env                                   | Default                                        | Notes |
| ---                                   | ---                                            | --- |
| `DB_HOST`                             | —                                              | required |
| `DB_PORT`                             | `3306`                                         |  |
| `DB_DATABASE`                         | —                                              | required |
| `DB_USERNAME`                         | —                                              | required |
| `DB_PASSWORD`                         | —                                              | required |
| `ESI_BASE_URL`                        | `https://esi.evetech.net/latest`               | override for a mirror |
| `ESI_USER_AGENT`                      | `AegisCore/0.1 (+ops@example.com)`             | CCP-required identifier + contact |
| `ESI_TIMEOUT_SECONDS`                 | `15`                                           |  |
| `MARKET_POLL_RATE_LIMIT_SAFETY_MARGIN`| `5`                                            | bucket floor (per-group) |
| `MARKET_POLL_ERROR_LIMIT_SAFETY_MARGIN`| `10`                                          | global error-budget floor |
| `MARKET_POLL_MAX_CONSECUTIVE_403S`    | `3`                                            | auto-disable threshold (no-access) |
| `MARKET_POLL_MAX_CONSECUTIVE_5XX`     | `5`                                            | auto-disable threshold (upstream fail) |
| `MARKET_POLL_BATCH_SIZE`              | `5000`                                         | rows per `executemany` |

## Failure discipline

Per ADR-0004 § Failure handling:

- **Routine failures** (403 no-access, 5xx, timeout):
  `consecutive_failure_count` increments, `last_error` / `last_error_at`
  record the most recent. Auto-disable after 3 consecutive 403s or 5
  consecutive 5xx/timeouts; `disabled_reason` is set (`no_access`,
  `upstream_failing`, `upstream_unreachable`). A single success resets
  the counter.
- **Security-boundary failures** (token ↔ location ownership mismatch,
  required scope missing): immediate disable, no grace counter. Not
  reachable in phase 1 (no auth'd polling yet); the path exists in
  `persist.disable_immediately` for later steps.

## Plane boundary

Sustained ESI polling lives on the Python execution plane per
[ADR-0002](../../docs/adr/0002-eve-sso-and-esi-client.md). This is
the first concrete caller — the "proper" Python ESI client (per-group
bucket tracker, refresh handling, JWT signature verification) is
still tracked as ADR-0002 § phase-2 #12. Until it lands, this package
inlines exactly the ESI surface it needs: a minimal `httpx.Client`
wrapper with reactive rate-limit awareness. When a second caller
arrives (admin structure poller, donor structure poller), promote
the HTTP client to a shared module.

## Layout

```
python/
├── market_poller.Dockerfile
├── requirements-market.txt
└── market_poller/
    ├── __init__.py
    ├── __main__.py      # `python -m market_poller`
    ├── cli.py           # argparse
    ├── config.py        # env → Config dataclass
    ├── log.py           # stderr kv formatter
    ├── db.py            # pymysql connection helper (autocommit off)
    ├── esi.py           # httpx client: region-orders endpoint, pagination, rate-limit
    ├── persist.py       # market_orders bulk upsert + watched-locations bookkeeping
    ├── outbox.py        # emit market.orders_snapshot_ingested
    └── runner.py        # orchestrator: load locations → poll each → commit/rollback
```
