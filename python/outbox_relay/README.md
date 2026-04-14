# outbox_relay

Claims rows from the MariaDB `outbox` table, dispatches each to a
typed projector, writes the projection to the appropriate derived
store, and acks the row. First concrete consumer of the outbox per
[CONTRACTS.md § Plane boundary](../../docs/CONTRACTS.md#plane-boundary--laravel--python).

## Phase-1 routing surface

| event_type                            | projector module                                         | sink                                  |
| ---                                   | ---                                                      | ---                                   |
| `market.history_snapshot_loaded`      | [`projectors/market_history.py`](market_history.py)      | InfluxDB measurement `market_history`   |
| `market.orders_snapshot_ingested`     | [`projectors/market_orders.py`](market_orders.py)        | InfluxDB measurement `market_orderbook` |

Unknown event types are **left in place** (claim WHERE filters by
known types only) so a future projector deployment — Neo4j,
OpenSearch, etc. — can pick them up. No event ever needs to be
re-emitted to add a new consumer.

## How a pass works

1. **Claim** up to `OUTBOX_RELAY_BATCH_SIZE` rows under
   `SELECT ... FOR UPDATE SKIP LOCKED` filtered by:
   - `processed_at IS NULL` (not already done)
   - `attempts < OUTBOX_RELAY_MAX_ATTEMPTS` (not a dead letter)
   - `event_type IN (known projectors)` (this relay can handle it)
2. **Project** each claimed row by looking up the projector via
   the dispatch registry and calling it with `(read_conn, influx,
   payload, log)`.
3. **Ack:**
   - On success → `processed_at = NOW(6)`, clear `last_error`.
   - On failure → `attempts += 1`, store error excerpt in
     `last_error`, leave `processed_at` NULL (re-claim next pass).
4. **Commit** the batch transaction (releases the SKIP LOCKED
   holds; other relay instances can claim disjoint batches in
   parallel).

## Run

### Scheduled (default)

The `outbox_relay` long-lived compose service runs on a 5-second
poll cadence (when the queue is empty; immediate re-poll when
there's work). Starts automatically with `docker compose up`.

```sh
make logs-outbox_relay
```

Override the cadence per deployment via env:

```
OUTBOX_RELAY_POLL_INTERVAL_SECONDS=5     # default
OUTBOX_RELAY_BATCH_SIZE=50               # rows per claim
OUTBOX_RELAY_MAX_ATTEMPTS=5              # dead-letter threshold
```

### Ad-hoc drain (operator)

```sh
make outbox-relay                                     # drain backlog once + exit
make outbox-relay OUTBOX_RELAY_ARGS="--log-level=DEBUG"
make outbox-relay OUTBOX_RELAY_ARGS="--batch-size=200"
```

The one-shot runs in a separate transient container under the
`tools` profile. No conflict with the long-lived service —
`SELECT FOR UPDATE SKIP LOCKED` lets both pull disjoint batches
without blocking each other.

## Failure handling

A projector that throws **does not poison the batch.** The relay
records the failure on the row (bumps `attempts`, stores the
error excerpt in `last_error`), commits, and proceeds to the next
event in the same claim. After `MAX_ATTEMPTS` the row stops being
claimed and is parked as a dead letter.

### Dead letter recovery

Operator workflow when a row hits the dead-letter threshold:

```sh
make artisan CMD='tinker --execute="
    DB::table(\"outbox\")
        ->where(\"processed_at\", null)
        ->where(\"attempts\", \">=\", 5)
        ->get([\"id\", \"event_type\", \"last_error\"])
        ->each(fn (\$r) => print_r(\$r));
"'
```

After fixing the root cause (projector bug, payload schema drift,
InfluxDB outage), reset the offending rows to retry:

```sh
make artisan CMD='tinker --execute="
    DB::table(\"outbox\")
        ->where(\"processed_at\", null)
        ->where(\"attempts\", \">=\", 5)
        ->update([\"attempts\" => 0, \"last_error\" => null]);
"'
```

The next relay pass will pick them up.

## Schemas written to InfluxDB

### `market_history`

One point per `(region_id, type_id)` per `trade_date`. Tags
support efficient filtering for "show me Tritanium price in The
Forge over time".

```
measurement = market_history
tags        = region_id (str), type_id (str)
fields      = average (float), highest (float), lowest (float),
              volume (int), order_count (int)
time        = trade_date at 00:00:00 UTC
```

Cardinality: ~100 regions × ~14k types ≈ 1.4M unique series.
Well within InfluxDB 2.x's millions-of-series ceiling.

### `market_orderbook`

One point per `(type_id, side)` per snapshot per location.
**Aggregates**, not per-order — raw order points would push
cardinality past sane limits via per-`order_id` series.

```
measurement = market_orderbook
tags        = region_id (str), location_id (str), type_id (str),
              side ("buy" | "sell")
fields      = best_price (float),         # MAX(price) for buy, MIN for sell
              weighted_avg_price (float), # Σ(price·vol)/Σ(vol)
              total_volume_remain (int),  # Σ(volume_remain)
              order_count (int)           # COUNT(*)
time        = observed_at
```

Cardinality per snapshot: ~10k distinct types × 2 sides ≈ 20k
unique series per location. Comfortable for InfluxDB's tsi index.

## Configuration

All env-driven, no dotenv. `infra/docker-compose.yml` passes:

| Env                                 | Default                        | Notes |
| ---                                 | ---                            | --- |
| `DB_HOST`                           | —                              | required |
| `DB_PORT`                           | `3306`                         |  |
| `DB_DATABASE`                       | —                              | required |
| `DB_USERNAME`                       | —                              | required |
| `DB_PASSWORD`                       | —                              | required |
| `INFLUXDB_HOST`                     | `http://influxdb2:8086`        |  |
| `INFLUXDB_TOKEN`                    | —                              | required (write access to bucket) |
| `INFLUXDB_ORG`                      | `aegiscore`                    |  |
| `INFLUXDB_BUCKET`                   | `primary`                      |  |
| `OUTBOX_RELAY_BATCH_SIZE`           | `50`                           | rows claimed per pass |
| `OUTBOX_RELAY_MAX_ATTEMPTS`         | `5`                            | dead-letter threshold |
| `OUTBOX_RELAY_POLL_INTERVAL_SECONDS`| `5`                            | sleep when queue is empty |

## Adding a new projector

1. Land `projectors/<event_short>.py` with a `project(read_conn,
   influx, payload, log) -> int` function.
2. Register it in `projectors/dispatch.py`'s
   `PROJECTOR_REGISTRY` dict.
3. Restart the relay container (`docker compose restart
   outbox_relay`).

The relay framework itself is projector-agnostic. No CLI changes,
no schema changes, no special DB columns — just a registry entry.

## Plane boundary

This is the first sustained outbox consumer (CONTRACTS.md § Plane
boundary anticipated this). Production-grade fan-out (multiple
relay instances per partitioned event_type, push transport via
Redis Streams or MariaDB CDC, etc.) is deferred to a real load
problem; phase-1 single-process polling is plenty for the
~one-event-per-5-min steady state market events produce.

## Layout

```
python/
├── outbox_relay.Dockerfile
├── requirements-outbox-relay.txt
└── outbox_relay/
    ├── __init__.py
    ├── __main__.py                   # `python -m outbox_relay`
    ├── cli.py                        # argparse: --interval / --once
    ├── config.py                     # env → Config dataclass
    ├── log.py                        # stderr kv formatter
    ├── db.py                         # pymysql connections (outbox + read)
    ├── influx.py                     # InfluxDB write_api wrapper
    ├── relay.py                      # claim / project / ack loop
    └── projectors/
        ├── __init__.py
        ├── dispatch.py               # event_type → projector registry
        ├── market_history.py         # → measurement market_history
        └── market_orders.py          # → measurement market_orderbook (aggregated)
```
