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

- Root: 1.6 TB free (52% used). **Not critical**, but ~36
  GB/day market_orders growth = ~44 days runway.
- mariadb data: 771 GB; market_orders alone is 456 GB
  (~59% of mariadb footprint).
- Latest full backup: 24 GB compressed at 06:57 — fresh
  (1 hour old when remediation started).
- Aborted backup at 00:06 UTC (1.1 GB partial — caught
  during the prior storage audit; explained in RUNBOOK).

**Decision:** disk not critical → ingest NOT throttled.
Aggregate work proceeds while pollers continue.

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

Estimate revised given Jita-only distribution:
- Jita: 5 min/day × 11 days = ~55 min
- other regions: <1 min/day × 11 days = trivial

**Total backfill: ~1 hour** (was 10-15 h estimate before
region distribution was visible).

Background; non-blocking against ingest.

Monitor progress:
```
docker exec mariadb mariadb -uaegiscore -paegiscore aegiscore -NBe \
  "SELECT observed_date, COUNT(*) FROM market_order_daily_aggregates GROUP BY observed_date ORDER BY observed_date"
```

---

## Stage D — verification (in progress)

Tasks still to run after backfill completes:

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

## Stage E — cutover plan (NOT executed yet)

Sequence (operator-led):

1. **Pause /portal/market historical reads** by
   feature-flagging analytics queries to the aggregate
   table. Code work, not a DB change.
2. **Switch `JitaValuationService`, `MarketItemHistory`,
   `DoctrineMarket`, `PersonalOrderPredictor`** to read
   `market_order_daily_aggregates` for any window > 72 h.
3. **Confirm `market:derive-daily` aggregator + new
   aggregator both populate `market_history` for current
   day** (overlapping safety).
4. **Wait 24 h** to confirm no analyst surface fell over.
5. **Create `market_orders_v2`** with daily partitioning
   (separate ALTER TABLE, hours of locking on 960 M rows —
   schedule downtime window).
6. **Point market_poller writes to v2.**
7. **Keep old `market_orders` read-only for 7 days** as
   rollback window.
8. **Drop old `market_orders` partitions older than 72 h**
   via DROP PARTITION (metadata-only on InnoDB).
9. **OPTIMIZE TABLE market_orders** to reclaim free space
   from data files.

Each step has an explicit rollback documented in the
`Rollback` section below.

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
