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

**All 960 M rows are in `p2026_04`.** No data exists outside
the current month — historical retention has been silently
discarded (or simply never accumulated past the most recent
poll cycles). Either:

- pollers only began running this month (most likely; v1
  ramp-up), or
- prior partitions were dropped without telemetry.

Either way, the partition rotation discipline that
`docs/ADR-market-orders-partitioning.md` proposes is **not
necessary for historical reclaim** — there is no historical
data to drop. The audit's 30-300 GB reclaim estimate must be
revised: market_orders growth is **purely forward-looking**.

### Daily ingest rate

`SELECT COUNT(*) FROM market_orders WHERE observed_at >= NOW() - INTERVAL 1 DAY;`
returned **82.3 M rows in 24 h** — roughly 3.4 M/h, 57 K/min.
At this rate:
- 7 days = 575 M rows
- 30 days = 2.5 B rows
- p2026_04 alone projects to 1-2 B rows by month end if the
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
| `Domains/UsersCharacters/Services/PersonalOrderPredictor.php:705` | Jita 7-day ticker fallback when live ticker absent | **7 days** |
| `Console/Commands/DeriveMarketDailyCommand.php:113,138` | aggregator — reads yesterday's snapshots → writes market_history | **2-3 days** (close + 1 day overlap for safety) |
| `python/outbox_relay/projectors/market_orders.py:149` | InfluxDB projection from outbox event | latest snapshot of named region/structure only |
| `python/market_poller/persist.py` | the writer itself; never reads | n/a |

**Finding:** every direct `market_orders` consumer needs
**≤ 7 days** of live data. No analyst dashboard, no
prediction pipeline, no operational surface depends on
historical market_orders data older than a week.

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
| Purpose   | live order-book snapshot for stock checks, Jita 7-day ticker fallback, daily aggregator input |
| Granularity | per-snapshot, per-(observed_at, source, location, order) |
| Retention | **14 days target** (extra 7-day safety beyond the 7-day Jita ticker need) |
| Mechanism | monthly partition rotation: drop partitions whose `observed_at` is older than 14 days from the start-of-month boundary |
| Operational guard | aggregator (`market:derive-daily`) must be confirmed run for every day in the window before its partition is dropped |

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

Given ingest rate ~82 M rows/day and current ~960 M total:

| cutoff           | rows kept   | rows dropped  | data + idx kept | reclaim    | risk |
|------------------|------------:|--------------:|----------------:|-----------:|------|
| keep ≥ 14 days   | ~1.15 B     |  0 (currently)| ~456 GB         |  0 GB      | none — all current data inside window |
| keep ≥ 7 days    | ~575 M      | ~385 M        | ~220 GB         | ~236 GB    | low — Jita ticker fallback covered |
| keep ≥ 3 days    | ~245 M      | ~715 M        | ~95 GB          | ~361 GB    | medium — narrows aggregator safety overlap |

**Caveat:** the projection assumes uniform per-day row counts.
Real distribution is poller-cyclical; daily counts may vary
± 30%.

**Today's actual reclaim opportunity:** 0 GB. The single
populated partition (`p2026_04`) covers the past 27 days,
all within any reasonable HOT cutoff. **No partition is
currently dropable without losing data within the analysis
window.**

The reclaim opportunity becomes real on May 1, when:
- `p2026_04` becomes month-old → can be considered for drop
  if April data is no longer needed for the rolling 14-day
  window (it won't be, since May is in p2026_05)
- Or sooner if we move from monthly partitioning to **daily
  partitioning** (proposed in v2; see "Operational risk"
  below)

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
   current data is inside any reasonable HOT window).
2. **Partition rotation cron** ready to install once the
   first month is fully outside the 14-day window
   (early-mid May 2026). Until then, the cron would be a
   no-op.
3. **Existing aggregator** (`market:derive-daily`) is
   sufficient to support the hot→warm transition. No new
   aggregate columns or schema changes for v1.
4. **`docs/ADR-market-orders-partitioning.md` recommendations
   stand**, with the caveat that the 30-300 GB reclaim
   estimate was inflated by an incorrect assumption about
   historical data presence. **Real first-month rotation
   reclaim:** 0 GB until p2026_04 ages past the cutoff.
5. **v2 candidates** (NOT v1):
   - daily / weekly repartitioning for finer reclaim grain
   - additional aggregate metrics (spread, liquidity, depth)
   - InnoDB row compression for warm-tier columns
   - sub-region partitioning

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
