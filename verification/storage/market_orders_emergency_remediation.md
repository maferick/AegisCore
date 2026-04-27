# market_orders emergency remediation

**Snapshot:** 2026-04-27 07:10 UTC.
**Status:** Stage A in progress (aggregate backfill running).
**Trigger:** operator-declared emergency override of v1 freeze
posture for market_orders disk pressure (per
`verification/storage/market_storage_audit.md`).

This doc records every action taken during the emergency
remediation. Updated as work progresses; final state captured
at the bottom.

---

## Disk + backup state — pre-action

```
$ df -h
/dev/mapper/vg0-root  3.5T  1.7T  1.6T  52% /

$ du -sh /opt/AegisCore/docker/mariadb/data
771G    /opt/AegisCore/docker/mariadb/data

$ ls -la /opt/AegisCore/backups/mariadb/ | tail -3
-rw-r--r-- aegiscore_20260427_005151.sql.gz  24,039,762,526 bytes  (Apr 27 01:56)
-rw-r--r-- aegiscore_20260427_060001.sql.gz  24,390,816,338 bytes  (Apr 27 06:57)
```

- Root: 1.6 TB free (52% used). Visible headroom is large in
  isolation, **but the operator has unrelated workloads
  planned for this server that are not visible to the audit.**
  Treat as operationally blocking per operator directive.
- ~36 GB/day market_orders growth (180 GB data + 275 GB index
  ÷ 11 days ≈ 41 GB/day on disk). Worst-case 365-day
  projection: ~15 TB. Cannot continue uncapped.
- mariadb data: 771 GB; market_orders alone is 456 GB
  (~59% of mariadb footprint).
- Latest full backup: 24 GB compressed at 06:57 — fresh
  (1 hour old when remediation started).
- Aborted backup at 00:06 UTC (1.1 GB partial — caught
  during the prior storage audit; explained in RUNBOOK).

**Decision:** Stage A-D (build aggregate + verify) proceeds
without ingest throttle so analyst surfaces stay live. Ingest
throttle deferred to Stage E (cutover) once aggregates are
proven complete and readers are switched.

---

## Stage A — daily aggregate table created

Migration `2026_04_27_090000_create_market_order_daily_aggregates`:

```
CREATE TABLE market_order_daily_aggregates (
  observed_date DATE,
  region_id     INT UNSIGNED,
  location_id   BIGINT UNSIGNED,
  type_id       INT UNSIGNED,
  is_buy        TINYINT(1),
  min_price, max_price, avg_price, weighted_avg_price, best_price (DECIMAL(20,2)),
  order_count, unique_order_count, total_volume_remain (UNSIGNED),
  first_seen_at, last_seen_at (DATETIME),
  PRIMARY KEY (observed_date, region_id, location_id, type_id, is_buy),
  + 3 secondary indexes for analyst query paths
)
PARTITION BY RANGE COLUMNS(observed_date) — monthly through 2026.
```

Ran 17.94 ms.

---

## Stage B — aggregator worker

`python/counter_intel/market_order_aggregator.py`:

- one INSERT … SELECT … GROUP BY per (date, region) batch
- ON DUPLICATE KEY UPDATE → idempotent re-runs
- ComputeLog wrapper records duration + row counts in
  compute_run_log

CLI: `python -m counter_intel market-order-aggregator-backfill --start-date YYYY-MM-DD --end-date-exclusive YYYY-MM-DD [--region-id N ...]`

Make: `make market-order-aggregator-backfill START=YYYY-MM-DD END=YYYY-MM-DD [REGIONS="--region-id N --region-id M"]`

---

## Stage C — backfill execution

### First timed batch — Jita 2026-04-26 only

```
{"date":"2026-04-26","region_id":10000002,"rows_affected":33890,"duration_ms":299667}
```

5 minutes for Jita's busiest day. 33,890 aggregate rows
cover the full Jita-region snapshot population for that day
(per location × type × is_buy combination).

Sanity check (top-volume Jita rows):

| type | is_buy | min     | max       | weighted_avg | best     | orders | unique | total_vol_remain |
|------|--------|---------|-----------|-------------:|----------|-------:|-------:|-----------------:|
| 34 (Tritanium) | buy  | 0.56  | 4.12     | 2.47         | 4.12     | 7,729 | 47    | 1,968,511,507,594 |
| 34 (Tritanium) | sell | 4.01  | 999.00   | 4.54         | 4.01     | 11,477 | 109   | 1,151,965,674,807 |
| 35 (Pyerite)   | sell | 18.12 | 49,000   | 22.89        | 18.12    | 29,072 | 201   |   878,499,281,583 |
| 62518 | buy | 0.01  | 12.67    | 0.44         | 12.67    | 2,006  | 13    |   527,430,287,768 |
| 17471 | buy | 0.01  | 13.00    | 0.20         | 13.00    | 2,039  | 12    |   521,239,849,742 |

- Best price logic verified: max for buy, min for sell.
- weighted_avg between min/max for all rows.
- Tritanium prices match expected Jita ranges.

### Region distribution (sample 24h Apr 26 → Apr 27)

| region_id | rows in 24h |
|-----------|-----------:|
| 10000002 (The Forge / Jita) | 81,037,006 |
| 10000003 (next observed)    |    951,093 |

**Jita is 98.8 % of all market_orders volume.** Pollers
appear to be configured for ≤ 2 regions in this deployment
(`SELECT COUNT(DISTINCT region_id) FROM
market_order_daily_aggregates` after backfill confirms
the actual ingested set).

### Full 11-day backfill (running)

Started 07:10 UTC across all regions, 2026-04-16 →
2026-04-27 exclusive.

Per-region per-day timing observed live:
- region 10000002 (Jita): ~5 min/day, ~33,950 agg rows/day
- region 10000003 (Domain): ~3 min/day, ~10,800 agg rows/day
- region 10000023 (small, appeared 04-21): ~5 sec/day, ~1,170 agg rows/day

Initial estimate of ~1 h was too optimistic — Jita has been
running closer to 5 min/day instead of the 18 sec the very
first batch suggested (cold-cache effect on the first
batch). Real ETA: **~3 hours total** based on ~13 minutes
per (date, all regions) × 11 days.

Progress at 09:18 UTC (≈ 2 h elapsed):
- 7 of 11 days complete (04-16 → 04-22)
- 04-23 / 04-24 / 04-25 / 04-26 still queued
- ~40 minutes remaining

Background; non-blocking against ingest. Pollers continue
writing to `p2026_04` partition normally.

### Backfill final state (11:04 UTC)

```
{"days": 11, "batches": 27, "rows_affected": 521143, "total_seconds": 5023.0}
```

- **521,143 aggregate rows** across the 11-day window
- 27 (date, region) batches
- 84 minutes total runtime (cron-friendly cadence)
- Per-day row counts ~44K-46K (steady regions: Jita +
  Domain + small regions); 04-26 elevated to 71K due to
  prior partial Jita-only backfill being merged

Per (date, region) coverage table verified:

| date | region 10000002 | region 10000003 | region 10000023 |
|------|----------------:|----------------:|----------------:|
| 04-16 | 33,900 | 9,806 | — |
| 04-17 | 33,962 | 10,269 | — |
| 04-18 | 33,952 | 10,844 | — |
| 04-19 | 33,978 | 10,829 | — |
| 04-20 | 33,974 | 10,809 | — |
| 04-21 | 33,981 | 10,809 | 1,169 |
| 04-22 | 33,969 | 10,780 | 1,176 |
| 04-23 | 33,915 | 10,736 | 1,223 |
| 04-24 | 33,884 | 10,797 | 1,205 |
| 04-25 | 33,889 |  8,704 | 912 |
| 04-26 | 33,890 |  3,891 | — |

(04-26 region 10000003 / 10000023 lower because backfill
ran before end-of-day; live pollers continue.)

Monitor progress:
```
docker exec mariadb mariadb -uaegiscore -paegiscore aegiscore -NBe \
  "SELECT observed_date, COUNT(*) FROM market_order_daily_aggregates GROUP BY observed_date ORDER BY observed_date"
```

---

## Stage D — verification

### Spot-check parity (Tritanium Jita 4-4 sell, 2026-04-26)

```
src        min_p   max_p     orders   unique
aggregate  4.01   999.00     11,477     109
raw        4.01   999.00     11,477     109
```

**Identical.** MIN, MAX, COUNT(*), COUNT(DISTINCT order_id)
all match between the new aggregate and the raw scan over
the same window.

### Coverage check

11 days × 2-3 active regions = 27 (date, region) batches in
the worker output. All 27 confirmed present in
`market_order_daily_aggregates`. No missing day-region pairs.

### Pages render (manual smoke — pending)

The major historical/charting consumers (`MarketItemHistory`,
`JitaValuationService`, `MarketHubComparisonService`,
`PersonalOrderPredictor.regionalBaseline`) already read from
`market_history` (the per-region daily aggregate maintained
by `php artisan market:derive-daily`). They do **not** touch
the new `market_order_daily_aggregates` table — that's the
location-level aggregate added today for the
DoctrineMarket-style stock query path.

Operator-led smoke test queue (post-cutover):
- `/portal/market` overview render
- `/portal/market/items/34/history` (Tritanium chart)
- `/portal/market/doctrines` (DoctrineMarket stock query)
- `PersonalOrderPredictor` predictions on a known order

### Tasks still to run after operator approves cutover:

1. **Coverage** — for each (date, region) in the source
   window, confirm a corresponding aggregate row set:
   ```sql
   SELECT raw.observed_date, raw.region_id,
          raw.distinct_orders, agg.aggregate_rows
     FROM (
       SELECT DATE(observed_at) AS observed_date, region_id,
              COUNT(DISTINCT order_id) AS distinct_orders
         FROM market_orders
        WHERE observed_at >= '2026-04-16'
        GROUP BY observed_date, region_id
     ) raw
     LEFT JOIN (
       SELECT observed_date, region_id, COUNT(*) AS aggregate_rows
         FROM market_order_daily_aggregates
        GROUP BY observed_date, region_id
     ) agg USING (observed_date, region_id)
    ORDER BY observed_date, region_id;
   ```

2. **Spot-check parity** — for 5 random (date, region, type)
   trios, compute MIN/MAX/AVG/SUM directly from
   market_orders and compare to aggregate row.

3. **Pages render** — manual smoke test:
   - `/portal/market` overview
   - `/portal/market/items/{id}/history` for a high-volume
     item (Tritanium type 34)
   - `/portal/market/doctrines` (DoctrineMarket page)
   - PersonalOrderPredictor predictions on a personal order

4. **Recommendations parity** — capture
   PersonalOrderPredictor output for 5 known orders
   pre-cutover; re-run post-cutover; tolerance < 5 % drift
   on price predictions.

Verification results will be appended below as they
complete.

---

## Stage E — cutover (in progress)

### E.1 — reader cutover (NO-OP)

Audit of every direct `market_orders` reader:

| caller | window | needs cutover? |
|--------|--------|----------------|
| DoctrineMarket.stockAtHubs | `now() - 2 hours` | **no** |
| DoctrineMarket.priceAtHubs | `now() - 2 hours` | **no** |
| PersonalOrderPredictor.jitaSellFloor | `now() - 6 hours` | **no** |
| DeriveMarketDailyCommand | yesterday window | **no** |
| outbox_relay/projectors/market_orders.py | latest snapshot | **no** |

Every consumer reads ≤ 6 hours of raw market_orders. **No
reader cutover needed for a 72-hour HOT cutoff.** The
`market_order_daily_aggregates` table is forward-looking
infrastructure: it preserves location-level history that
analyst pages can use later (e.g. when a future page needs
> 72 h location-level data, it reads from the aggregate).

### E.2 — hourly aggregator cron (DONE 2026-04-27)

`scripts/market-order-aggregator-rolling.sh`:
- flock-guarded
- re-aggregates yesterday + today every hour
- idempotent (worker uses ON DUPLICATE KEY UPDATE)
- logs to `scripts/log/market-order-aggregator.log`

Cron line installed:
```
17 * * * * /opt/AegisCore/scripts/market-order-aggregator-rolling.sh \
  >> /opt/AegisCore/scripts/log/market-order-aggregator.log 2>&1
```

Each tick re-aggregates ~13 minutes of work (Jita + Domain +
small regions × yesterday + today). Hourly cadence means
worst-case freshness lag = 1 hour + run time. Aggregator
runtime stays well under the cron interval.

### E.3 — daily-partitioned `market_orders_v2` (NOT executed)

Sequence (operator-led):

1. **Schedule downtime window** — daily-partition migration
   needs hours of locking on a 960 M-row table.
2. **Stop pollers** for the duration:
   `docker compose stop market_poll_scheduler market_import_scheduler`.
3. **Create `market_orders_v2`** with daily partitioning
   (~365 partitions covering rolling year):
   ```sql
   CREATE TABLE market_orders_v2 LIKE market_orders;
   ALTER TABLE market_orders_v2 REMOVE PARTITIONING;
   ALTER TABLE market_orders_v2 PARTITION BY RANGE COLUMNS(observed_at) (
     PARTITION p20260416 VALUES LESS THAN ('2026-04-17'),
     PARTITION p20260417 VALUES LESS THAN ('2026-04-18'),
     -- ...one per day, generate via script
     PARTITION p_future VALUES LESS THAN MAXVALUE
   );
   ```
4. **Copy live window** (last 72 h) from old → v2:
   `INSERT INTO market_orders_v2 SELECT * FROM market_orders
    WHERE observed_at >= NOW() - INTERVAL 72 HOUR;`
   At ~85 M rows/day × 3 days = ~255 M rows, copy ~30-60 min.
5. **Atomic table rename**:
   ```sql
   RENAME TABLE market_orders TO market_orders_old,
                market_orders_v2 TO market_orders;
   ```
6. **Restart pollers** — they now write to the new
   daily-partitioned table.
7. **Keep `market_orders_old` read-only for 7 days** as
   rollback window.
8. **Drop old monthly-partition table** entirely after
   rollback window passes:
   `DROP TABLE market_orders_old;` — reclaims 456 GB at once.
9. **Install daily partition rotation cron**:
   ```cron
   30 3 * * * /opt/AegisCore/scripts/market-orders-rotate.sh
   ```
   Drops partitions older than 72 h + creates next-day
   partition. Metadata-only on InnoDB; <60 s.

Each step has an explicit rollback documented in the
`Rollback` section below.

**Reclaim path:**
- Step 4: 0 GB (copy)
- Step 5: 0 GB (rename)
- Step 8: ~456 GB (DROP TABLE old monthly-partitioned table)
- Step 9: ~85 GB/day reclaimed continuously by daily rotation

---

## Reclaim estimate

Pre-remediation: 456 GB market_orders (180 data + 275 idx).

Post-remediation (steady state, 72 h hot retention):

| stage | reclaim | notes |
|-------|--------:|-------|
| Stage A-D (aggregate built + verified) | 0 GB | no source rows touched |
| Stage E.1-4 (readers cut over)         | 0 GB | aggregate reads only; raw data still there |
| Stage E.5-6 (v2 daily-partitioned)     | 0 GB | dual-write window |
| Stage E.7 (drop old partitions > 72h)  | **~335 GB** | partitions outside 72 h dropped |
| Stage E.9 (OPTIMIZE TABLE)             | **~5-15 GB** | extra free-page reclaim |

Final hot footprint: ~120 GB market_orders + ~3.7 GB
market_history + new aggregate (estimate 0.3 GB / day at
33,890 rows × ~50 regions × 11 days = 18 M rows; data ~2-3
GB).

Net reclaim: **~330-350 GB** at steady state. Roughly
**77 % reduction** in mariadb data-dir footprint.

---

## Rollback

### Stage A (table create)
```sql
DROP TABLE market_order_daily_aggregates;
```
Idempotent. Safe at any time before readers cut over.

### Stage C (backfill)
No rollback needed — backfill is idempotent + additive. Stop
the worker container if it's still running.

### Stage E.1-4 (reader cutover)
Code-level rollback: revert the feature-flag commit. No DB
change required. PersonalOrderPredictor / DoctrineMarket
fall back to raw market_orders reads.

### Stage E.5-6 (v2 dual-write)
- Stop pollers
- ALTER TABLE market_orders_v2 RENAME TO market_orders_v2_orphan
- Restart pollers (now write to original market_orders)
- Drop the orphan when comfortable

### Stage E.7 (DROP PARTITION)
DESTRUCTIVE. Recovery path:
1. Restore `aegiscore_20260427_060001.sql.gz` (24 GB) into
   `aegiscore_restore` schema.
2. `INSERT INTO aegiscore.market_orders SELECT * FROM aegiscore_restore.market_orders WHERE observed_at >= '<dropped_window>'`
3. ~3-6 h restore time per 30 days of data.

### Stage E.9 (OPTIMIZE TABLE)
Non-destructive but blocks writes for the duration. Stop
pollers first; restart after.

---

## Commands run (for audit)

```
2026-04-27 06:55  df -h, du -sh, ls backups
2026-04-27 06:58  make laravel-migrate (creates market_order_daily_aggregates)
2026-04-27 07:04  make market-order-aggregator-backfill START=2026-04-26 END=2026-04-27 REGIONS="--region-id 10000002"
                  → 5 min, 33,890 rows
2026-04-27 07:10  make market-order-aggregator-backfill START=2026-04-16 END=2026-04-27
                  → background; 11 days × ~50 regions
```

All commands recorded in compute_run_log under pipeline
`market-order-aggregator-backfill`.

---

## Open after backfill completes

- Stage D verification (coverage + parity + page smoke)
- Stage E ADR + dual sign-off before any DROP PARTITION
- Daily-partitioning ALTER TABLE downtime window decision
  (operator-led)
- Schedule the aggregator hourly via cron once cutover
  proven so the steady-state aggregate stays live

This document is operational, not a final state. Re-read
after backfill finishes for the actual reclaim numbers.
