# Market storage architecture audit

**Snapshot:** 2026-04-27.
**Status:** audit + recommendations only. **No destructive
work.**

`market_orders` is 939 M rows / 456 GB and dominates the
database footprint (>70% of total). This audit determines
what aggregate infrastructure already exists, maps query
dependencies, and proposes a hot/warm/cold model. Stage 3 of
the broader DB storage plan (`db_storage_audit.md`)
references this audit; both documents apply.

---

## MKT-1 — inventory

### Tables

| table                       |   rows | data GB | idx GB | total GB | notes |
|-----------------------------|-------:|--------:|-------:|---------:|-------|
| **market_orders**           | 960 M  | 180.72  | 275.23 | **455.95** | live order-book snapshots; partitioned RANGE COLUMNS(observed_at) by month |
| **market_history**          | 23.3 M |   2.50  |   1.19 |   3.70 | daily aggregates per (region, type); partitioned RANGE COLUMNS(trade_date) by month |
| ref_market_groups           | 2,165  |   0.00  |   0.00 |   0.00 | SDE static |
| personal_market_orders      | 2,364  |   0.00  |   0.00 |   0.00 | per-user ESI personal orders |
| market_hub_catchments       | 3,739  |   0.00  |   0.00 |   0.00 | hub→system mapping |
| market_hub_collectors       | 3      |   0.00  |   0.00 |   0.00 | hub poller config |
| market_hubs                 | 4      |   0.00  |   0.00 |   0.00 | hub registry |
| market_watched_locations    | 4      |   0.00  |   0.00 |   0.00 | watch list |
| market_hub_entitlements     | 1      |   0.00  |   0.00 |   0.00 | ABAC |
| eve_market_tokens           | 2      |   0.00  |   0.00 |   0.00 | ESI tokens |

Two tables matter for retention planning: **market_orders**
and **market_history**.

### market_orders schema

```
PRIMARY KEY (observed_at, source, location_id, order_id)

Secondary indexes:
  idx_market_orders_type_time      (type_id, observed_at)
  idx_market_orders_location_type  (location_id, type_id, observed_at)
  idx_market_orders_region_type    (region_id, type_id, observed_at)

Columns:
  observed_at, source, location_id, order_id  ← PK
  region_id, type_id, is_buy
  price, volume_remain, volume_total, min_volume
  range, duration, issued_at
  observation_kind ∈ snapshot / incremental_poll / historical_dump
  http_last_modified, created_at, updated_at
```

Index/data ratio 1.52 — three secondary indexes (each
~80-100 GB) cover the common query lookup patterns
(by type, by location+type, by region+type), all with
observed_at trailing for time-bounded range scans.

### Partition state — critical finding

```
p2025_01 → p2025_12  : 0 rows each (12 monthly partitions)
p2026_01 → p2026_03  : 0 rows each
p2026_04             : 960,701,818 rows
p2026_05 → p2026_12  : 0 rows each
p_future             : 0 rows
```

**All 960 M rows are in `p2026_04`.** Confirmed time range:
`MIN(observed_at) = 2026-04-16 21:14`, `MAX = 2026-04-27 06:10`.
**11 days of data** — pollers ramped up mid-April. There is
no pre-April history; the empty 2025 + 2026-Q1 partitions
were created proactively by the schema, never populated.

This rules out the partition-rotation reclaim path:

- Audit's prior 30-300 GB reclaim estimate **does not apply**
  to the immediate first cycle. Steady-state reclaim materializes
  once the platform crosses the 14d HOT-window boundary on
  partitions outside the current month.
- All current data (10.5 days) is inside any reasonable HOT
  window — **0 GB drop opportunity today**.
- Growth is purely forward-looking (~87 M/day).

### Daily ingest rate

`SELECT COUNT(*) FROM market_orders WHERE observed_at >= NOW() - INTERVAL 1 DAY;`
returned **82.3 M rows in 24 h** — roughly 3.4 M/h, 57 K/min.

Empirical 5-day distribution:

| date       | rows           |
|------------|---------------:|
| 2026-04-27 | 21,415,843 (partial day) |
| 2026-04-26 | 81,988,099     |
| 2026-04-25 | 85,412,389     |
| 2026-04-24 | 88,261,650     |
| 2026-04-23 | 89,060,134     |
| 2026-04-22 | 66,732,930 (ramp-up) |

Steady-state ~85 M/day. At this rate:
- 7 days = 595 M rows
- 30 days = 2.55 B rows
- p2026_04 projects to ~2.0-2.2 B rows by month end if the
  rate continues

This is the budget pressure: the current single-month
partition will hit several billion rows by month end if no
rotation/retention applies. **Storage is growing faster than
intelligence demand.**

### market_history schema

```
PRIMARY KEY (trade_date, region_id, type_id)

Secondary indexes:
  idx_market_history_region_type  (region_id, type_id)
  idx_market_history_type         (type_id)

Columns:
  trade_date, region_id, type_id  ← PK
  average, highest, lowest
  volume, order_count
  source ∈ historical_dump / incremental_poll / esi_derived_daily
  observation_kind, http_last_modified, created_at, updated_at
```

23 M rows / 3.7 GB / 25 monthly partitions cover **2025-01 to
2026-04** (16 months of daily aggregates).

---

## MKT-2 — existing aggregates

`market_history` is the existing daily aggregate. Source
breakdown:

| source                    | rows       | meaning |
|---------------------------|-----------:|---------|
| **everef_market_history** | 23,985,046 | bulk-imported from EveRef historical dumps; canonical history pre-platform |
| **esi_derived_daily**     |     23,516 | derived locally from market_orders snapshots via `php artisan market:derive-daily` |

The `esi_derived_daily` source is **already in production**
via `app/app/Console/Commands/DeriveMarketDailyCommand.php`,
scheduled hourly via `Schedule::command('market:derive-daily')`
in `routes/console.php`. It writes daily rollups from
market_orders into market_history, **closing the EveRef 3-4
day lag with our own snapshots**. The aggregator is the
contract that makes a future market_orders TTL safe.

Aggregate metrics covered today:
- daily average price (volume-weighted approximation)
- daily highest / lowest price
- daily volume (total ISK moved)
- daily order_count

Aggregate metrics **not** covered today:
- weighted_avg_price (true volume-weighted)
- order_count by buy/sell split
- spread metrics (bid/ask)
- liquidity_score / depth-of-book
- regional unique-order-count
- percentile ranges

Decision: **v1 keeps existing aggregate columns**. Adding
new metrics is a v2 capability concern, not a storage
concern. The current aggregate corpus is sufficient to
support every existing market query path (see MKT-3).

---

## MKT-3 — query dependency audit

Code paths that touch market_orders or market_history.

### Direct `market_orders` reads (HOT data dependency)

| caller | purpose | minimum lookback needed |
|--------|---------|------------------------|
| `Filament/Portal/Pages/DoctrineMarket.php:574,610` | "stock from market_orders across stock hub(s)" — **live order book snapshot** | latest snapshot only (≤ a few hours) |
| `Domains/UsersCharacters/Services/PersonalOrderPredictor.php:705` | live Jita 4-4 sell floor lookup; aggregate fallback already in `market_history` | **6 hours** (`now()->subHours(6)`) |
| `Console/Commands/DeriveMarketDailyCommand.php:113,138` | aggregator — reads yesterday's snapshots → writes market_history | **2-3 days** (close + 1 day overlap for safety) |
| `python/outbox_relay/projectors/market_orders.py:149` | InfluxDB projection from outbox event | latest snapshot of named region/structure only |
| `python/market_poller/persist.py` | the writer itself; never reads | n/a |

**Finding:** every direct `market_orders` consumer needs
**≤ 3 days** of live data. The PersonalOrderPredictor already
falls back to `market_history` (the aggregate) for the 7-day
window when the 6h live lookup misses — the 7-day need is
served by the aggregate, not by raw market_orders. No analyst
dashboard, no prediction pipeline, no operational surface
depends on raw market_orders past 3 days.

### Direct `market_history` reads (WARM data dependency)

| caller | purpose | window |
|--------|---------|--------|
| `JitaValuationService.php:63` | killmail valuation → daily Jita avg | 30-365 days |
| `PersonalOrderPredictor.php:329, 365, 521, 607, 692, 725` | regional volume baselines, price baselines, predictions | 7-30 days (`REGIONAL_DAYS = 30`) |
| `Filament/Portal/Pages/MarketItemHistory.php` | "lowest/average/highest/volume for the own hub's region" history chart | open-ended (UX dependent) |

All historical price / volume / charting goes through
`market_history`. **No charting page, no historical analysis,
no killmail valuation** depends on raw `market_orders` past
the live snapshot window.

### Indirect dependencies

`MarketHubComparisonService.php` line 16 explicitly notes:
> "skipping the 186M-row `market_orders` MariaDB ..."

The hub comparison service was built to **avoid** market_orders
in the hot path. Confirms aggregate-first design intent.

---

## MKT-4 — hot / warm / cold strategy

The platform's current architecture already implements hot
+ warm. Cold is supplied by EveRef bulk historical, not by
the platform. Therefore the strategy is: **enforce the
hot-tier window**, leave warm + cold alone.

### HOT — `market_orders`

| dimension | value |
|-----------|-------|
| Purpose   | live order-book snapshot for stock checks, 6-hour live Jita lookup, daily aggregator input |
| Granularity | per-snapshot, per-(observed_at, source, location, order) |
| Retention | **3 days / 72 hours target** (operator-set 2026-04-27, tightened from prior 14d after MKT-3 confirmed every direct reader needs ≤ 3d) |
| Mechanism | partition rotation. Monthly partitions are too coarse for a 3-day window; **daily or weekly partitioning required** as a prerequisite. ADR `ADR-market-orders-partitioning.md` updated to call this out. |
| Operational guards | five prereqs (see Migration strategy below) before activation |

**Migration strategy — must execute in order:**

1. **Build aggregate layer** — confirm the existing
   `market:derive-daily` aggregator covers every region the
   pollers watch + every analytical metric currently read
   from raw market_orders (price floor, daily avg, daily
   high/low, volume). Add any missing aggregate columns
   *before* dropping raw data they would have computed.
2. **Backfill aggregates from current raw window** — run
   `php artisan market:derive-daily` (already scheduled
   hourly, but force a full sweep) over the entire current
   11-day market_orders window. Confirm
   `market_history` row count for `source='esi_derived_daily'`
   has rows for every (date, region, type) combination that
   appears in market_orders.
3. **Verify historical pages** — analyst loads
   `/portal/market`, `/portal/market/items/{id}/history`,
   `/portal/market/doctrines` against the aggregate-only
   view. No 404s, no missing data warnings.
4. **Verify recommendations** — PersonalOrderPredictor /
   DoctrineMarket prediction outputs match a baseline
   captured before retention kicks in. Tolerance: < 5 %
   prediction-value drift; > 5 % blocks activation.
5. **Verify prediction inputs** — every input to the
   prediction pipeline either reads raw market_orders within
   the 3-day window, or reads market_history. Anything that
   reads raw market_orders > 3 days back is a blocker; the
   reader must migrate to market_history before retention
   activates.
6. **Backup ≤ 24 h old** + restore-tested.

Only after all six prereqs hold may the rolling 3-day
retention be activated. Activation is operator-led, not
automated.

### WARM — `market_history`

| dimension | value |
|-----------|-------|
| Purpose   | charts, valuations, predictions, regional baselines |
| Granularity | per-(trade_date, region, type) |
| Retention | **3 years (1095 days)** — covers analyst retro windows + provides forward training corpus for v2 |
| Mechanism | already partitioned by month; no rotation needed at v1 — table is small (~3.7 GB, projected ~6-8 GB at full 3y) |
| Operational guard | EveRef historical re-import on operator demand if any window appears thin |

### COLD — EveRef

| dimension | value |
|-----------|-------|
| Purpose   | pre-platform historical baseline; archival re-import |
| Granularity | per-(trade_date, region, type) |
| Retention | external (EveRef) |
| Mechanism | `make sde-import` triggers EveRef pulls; no platform storage |

### Aggregate gaps

None block v1. The `esi_derived_daily` aggregator covers the
hot → warm transition every hour. The schema already supports
the analytical needs documented in MKT-3.

---

## MKT-5 — storage impact

### Reclaim estimates

Given ingest rate ~85 M rows/day and current ~960 M total
(11-day window):

| cutoff           | rows kept   | rows dropped  | data + idx kept | reclaim    | risk |
|------------------|------------:|--------------:|----------------:|-----------:|------|
| keep ≥ 14 days   | 960 M       |  0            | 456 GB          |  0 GB      | none — current span is only 11 d |
| keep ≥ 7 days    | 595 M       | 365 M         | ~283 GB         | ~173 GB    | low — once daily partitioning lands |
| keep ≥ 3 days    | 255 M       | 705 M         | ~121 GB         | ~335 GB    | low — confirmed by MKT-3 query map |
| keep ≥ 24 h      |  85 M       | 875 M         |  ~40 GB         | ~416 GB    | high — narrows aggregator window beyond derive-daily safety |

**Operator-selected target: 3 days.** Reclaim ~335 GB at
steady state. Caveats:

- Per-day projection assumes uniform distribution. Real
  daily counts vary ±10 % (April 22 ramp-up was 66 M; April
  23-26 averaged 86 M).
- **Reclaim only materialises after daily/weekly
  repartitioning lands.** Monthly partitions cannot resolve
  a 3-day cutoff; the entire current month (~2 B rows by
  month end) lives in one partition.

**Today's actual reclaim opportunity:** 0 GB. All current
data inside any reasonable HOT cutoff (the platform's whole
market_orders dataset is only 11 days old).

Reclaim opportunity becomes real once **two milestones**
hit:
- daily/weekly partitioning ALTER TABLE completes (v2 ADR);
- aggregate-coverage prereqs (steps 1-5 in HOT migration
  strategy above) are signed off.

Until both, the partition rotation cron is a no-op and the
3-day target is theoretical.

### Migration complexity

| change                                     | complexity | downtime |
|--------------------------------------------|-----------:|---------:|
| install monthly rotation cron              | low        | none (ALTER DROP PARTITION is metadata only) |
| switch to **weekly** partitioning          | medium     | one ALTER TABLE pass to repartition (~minutes-hours, blocking) |
| switch to **daily** partitioning           | medium     | same as weekly |
| add column-level compression (InnoDB ROW_FORMAT=COMPRESSED) | medium-high | requires OPTIMIZE TABLE per partition |
| sub-partition by region                    | high       | full ALTER TABLE; downtime hours+ |

For v1: **monthly rotation only**. Daily/weekly partitioning
is a v2 schema change requiring its own ADR.

### Rollback complexity

`DROP PARTITION` is destructive. Rollback paths:

1. **Restore from backup** — 24 GB compressed; 30 min restore
   to alt schema. Surgical INSERT pull-back per
   `docs/ADR-market-orders-partitioning.md` § Rollback.
2. **Re-poll from ESI** — pollers re-fill within hours. Only
   the dropped poll-snapshot timeline is lost; live state
   recovers in ≤ 2 polling cycles (~30 min).
3. **Re-derive from market_history** — aggregates are kept;
   we cannot reconstruct order-level granularity but every
   downstream consumer that needs aggregates is fine.

### Operational guards (must be in place before any drop)

1. **Aggregator confirmation per day to drop**: query
   `SELECT COUNT(*) FROM market_history WHERE trade_date=<day> AND source='esi_derived_daily'` returns rows
   for every region currently watched.
2. **Polling stable for prior 24 h**: no
   `compute_run_log status='failed'` rows for
   pipeline=market-poll in the prior 24 h.
3. **Backup ≤ 24 h old**.
4. **Operator dry-run** of the DROP PARTITION sequence in a
   test schema first.
5. **Rollback rehearsal** — restore one dropped partition
   from backup into a test schema, confirm the surgical
   pull-back SQL works.

### Risk-bounded path forward

**Phase 1 (v1, 14d watch window):** install the monthly
partition rotation cron. Cron does **not drop** the current
month's partition or the prior month's partition. Drops only
partitions whose entire month is more than 14 days outside
the rolling HOT window (i.e. older than the second-prior
month). At today's data distribution, the cron is a no-op —
sets the discipline, doesn't reclaim anything yet.

**Phase 2 (early v2, daily partitions):** repartition to
daily. Requires ADR. Reclaim becomes precise per-day.

**Phase 3 (v2, sub-region):** sub-partitioning by region.
Operational complexity high; defer until query patterns
demand it.

---

## Decision: V1 outcomes

1. **No destructive market_orders work** during v1 freeze.
   Confirmed: there is no actionable reclaim today (all
   current data is inside any reasonable HOT window — the
   platform only has 11 days of market_orders data).
2. **HOT target tightened to 3 days / 72 hours**
   (operator decision 2026-04-27). MKT-3 confirmed every
   raw market_orders consumer needs ≤ 3 days; the 7-day
   Jita ticker need is served by `market_history` (the
   aggregate), not by raw orders.
3. **Activation gated on six prereqs:** aggregate layer
   built, aggregate backfill complete, historical pages
   verified, recommendations verified, prediction inputs
   verified, backup ≤ 24 h. Operator-led execution.
4. **Partition rotation cron** cannot be installed at
   monthly grain — daily/weekly partitioning is a
   prerequisite for 3-day cutoff. Move to daily partitions
   in v2 ADR before any rotation cron lands.
5. **Existing aggregator** (`market:derive-daily`) is
   architecturally sufficient. v1 task: confirm coverage
   completeness across the current 11-day window before
   trusting it for hot→warm migration.
6. **`docs/ADR-market-orders-partitioning.md` recommendations
   stand**, but reclaim estimates corrected:
   - first-cycle reclaim: 0 GB (no historical data exists)
   - steady-state reclaim at 3-day cutoff: ~335 GB
   - steady-state reclaim at 14-day cutoff: ~50 GB
7. **v2 candidates** (NOT v1):
   - daily / weekly repartitioning (prereq for 3d cutoff)
   - additional aggregate metrics (spread, liquidity, depth)
   - InnoDB row compression for warm-tier columns
   - sub-region partitioning
   - `market_aggregate_lag` quality detector

V1 freeze posture per `docs/V1_FREEZE.md` allows this audit
+ documentation work. Implementation of any partition
rotation is deferred to operator decision based on May 2026
observed-state.

---

## Open follow-ups

- **Confirm whether prior-month data ever existed** for
  `market_orders`. If pollers only started ramping in
  April 2026, the empty 2025 partitions are expected and
  cosmetic. If prior data was silently dropped, that's an
  operational note for RUNBOOK.
- **Repartition to daily** evaluation — would require ALTER
  TABLE downtime estimate against current 960 M rows.
  Probably hours. Preserve for post-freeze planning.
- **Per-region partition sub-key** — useful only when
  per-region drops become a real requirement. Not today.
- **Aggregator coverage gap test** — operator should run
  `SELECT MAX(trade_date) FROM market_history WHERE source='esi_derived_daily';`
  daily and alert if it falls > 36 h behind. Could be a new
  quality detector — `market_aggregate_lag` — added per
  Phase 4.9E.1 governance after v1 freeze lifts (calibration
  cycle).

This audit closes MKT-1 → MKT-5. No code change.
