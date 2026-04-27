# ADR: market_orders monthly partitioning + 90-day retention

**Status:** proposed (2026-04-27). Not implemented.

**Update 2026-04-27** — see
`verification/storage/market_storage_audit.md` for the
follow-up audit. Critical corrections:

1. All 960 M rows live in a single `p2026_04` partition;
   the platform's market_orders dataset is only 11 days
   old (2026-04-16 → 2026-04-27). Empty 2025 + 2026-Q1
   partitions were never populated. **First-cycle reclaim
   is 0 GB** because there is no historical data to drop.
2. **HOT retention target tightened to 3 days / 72 hours**
   (operator decision 2026-04-27). MKT-3 confirmed every
   raw `market_orders` consumer needs ≤ 3 days; the 7-day
   Jita ticker need is served by `market_history` (the
   aggregate), not raw orders.
3. **Monthly partitioning cannot resolve a 3-day cutoff.**
   This ADR's monthly-rotation strategy must be revised
   before activation: either move to **daily** or **weekly**
   partitioning (each requires a separate ALTER TABLE,
   hours of locking on a 960 M-row table). Decision deferred
   to v2; a follow-up ADR will document the daily-partition
   migration plan.
4. Steady-state reclaim at 3-day cutoff: **~335 GB**
   (705 M rows dropped of the projected 960 M steady state).
   Steady-state reclaim at 14-day cutoff: ~50 GB. The
   30-300 GB estimate below was wrong on both ends — too
   small for 3-day, too large for 14-day.

**Context:** the `market_orders` table dominates the AegisCore
data directory at 456 GB (180 GB data + 275 GB indexes, 939 M
rows). Range-partitioned scaffolding already exists on the PK
prefix `observed_at`, but no monthly rotation cron has been
attached and no rows are dropped. The full v1 storage audit
(`verification/storage/db_storage_audit.md`) identifies this as
the single biggest reclaim lever (estimate 30-300 GB depending
on chosen retention window).

**Decision:** v1 freeze gates this work. The cost of getting
partition rotation wrong is high (data loss, missed market
history, downtime during ALTER TABLE) and the cost of waiting
is bounded (storage is large but stable). v2 entry criteria
include retention/partitioning operational; this ADR will
gate that work.

---

## Current state

```sql
CREATE TABLE market_orders (
  observed_at DATETIME NOT NULL,
  source VARCHAR(64) NOT NULL,
  location_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL,
  region_id INT UNSIGNED,
  type_id INT UNSIGNED,
  ...
  PRIMARY KEY (observed_at, source, location_id, order_id)
)
PARTITION BY RANGE COLUMNS(observed_at) (
  PARTITION p2025_01 VALUES LESS THAN ('2025-02-01'),
  PARTITION p2025_02 VALUES LESS THAN ('2025-03-01'),
  ...
);
```

- 939 M rows
- 180 GB data, 275 GB indexes
- 4 indexes:
  - PRIMARY (observed_at, source, location_id, order_id)
  - idx_market_orders_type_time (type_id, observed_at)
  - idx_market_orders_location_type (location_id, type_id, observed_at)
  - idx_market_orders_region_type (region_id, type_id, observed_at)
- Existing partitions: monthly back to 2025-01; 2025-01..2025-03
  reportedly empty (already-rolled-off historical period).

## Monthly partition strategy

Keep RANGE-by-month. Rolling window = 90 days = 3 partitions.
Drop partitions older than the window; create new ones ahead
of the current month.

### Steady-state rotation cron

```sql
-- run monthly on day 1 at low traffic
ALTER TABLE market_orders DROP PARTITION pYYYY_MM;       -- N-3 month
ALTER TABLE market_orders REORGANIZE PARTITION pmax INTO (
  PARTITION pYYYY_MM_NEW VALUES LESS THAN ('YYYY-MM_NEW+1-01'),
  PARTITION pmax VALUES LESS THAN MAXVALUE
);
```

DROP PARTITION is metadata-only on InnoDB → no row-by-row
delete, no undo bloat. This is the key reason monthly
partitioning is preferred over a row-based TTL sweep on
market_orders.

## Retention target

**90 days** of market_orders kept hot. Beyond that:

- daily aggregates already materialised in `market_history`
  (preserved 24-month minimum).
- ESI history endpoints + EveRef bulk dumps are authoritative
  for >90-day historical pricing if needed for backfill.

This means losing market_orders > 90d does not lose any
recoverable signal — the aggregates are already computed and
the source can be re-fetched.

## Migration path

### Phase A — pre-migration

1. **Full backup** (already daily; verify the latest
   aegiscore_*.sql.gz is fresh and test-restorable).
2. **Confirm market_history aggregates** cover the 90-day
   target window with no gaps:
   ```sql
   SELECT MIN(trade_date), MAX(trade_date), COUNT(*)
     FROM market_history
    WHERE trade_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY);
   ```
3. **Dry-count** rows scheduled for drop:
   ```sql
   SELECT TABLE_ROWS, PARTITION_NAME
     FROM information_schema.partitions
    WHERE table_schema='aegiscore' AND table_name='market_orders'
      AND partition_method='RANGE COLUMNS'
    ORDER BY partition_name;
   ```
4. **Stop ingest** writers (market_poll_scheduler,
   market_import_scheduler) — DROP PARTITION is fast but
   blocks DDL contention during the operation.

### Phase B — execution

1. `ALTER TABLE market_orders DROP PARTITION pYYYY_MM;` for
   each partition older than 90 days.
2. Verify free space: `SHOW TABLE STATUS LIKE 'market_orders';`
3. `OPTIMIZE TABLE market_orders;` is **not** required after
   DROP PARTITION (each partition is its own ibd file).
4. Restart ingest writers.
5. Run a smoke poll cycle to confirm new rows land:
   `make market-poll && SELECT MAX(observed_at) FROM market_orders;`

### Phase C — install rotation cron

```cron
0 3 1 * * cd /opt/AegisCore && bash scripts/market-orders-rotate.sh \
  >> /opt/AegisCore/scripts/log/market-orders-rotate.log 2>&1
```

Script creates the next month's partition + drops the oldest
that's outside the 90-day window. Idempotent. Single-instance
flock-guarded.

## Downtime risk

- **DROP PARTITION**: ~seconds per partition (metadata
  operation). Total <60 s for 9 partitions.
- **Concurrent reads**: blocked briefly during the partition
  catalog update. Most market dashboard queries hit
  `market_history` (already aggregated), not `market_orders`,
  so analyst-facing impact is small.
- **Concurrent writes**: market pollers will fail their next
  insert during the catalog flip. Acceptable — pollers retry
  via Phase 4.9D retry policy.

**Recommended downtime window:** 5 minutes. Schedule on a
Sunday during EU off-hours.

## Rollback plan

DROP PARTITION is destructive; cannot un-drop without restore.
Rollback paths:

1. **Pre-flight backup-only restore** to a separate
   `aegiscore_restore` schema. Pull the dropped rows back via
   `INSERT INTO aegiscore.market_orders SELECT * FROM
   aegiscore_restore.market_orders WHERE
   observed_at < cutoff;`. ~3-6 hours per 30 days of data.
2. **EveRef + ESI re-backfill.** ESI doesn't expose historical
   `/markets/{region}/orders/`, so this only recovers daily
   summaries (which we already have in market_history).
3. **Resume from a known-good backup.** Standard procedure;
   acceptable for v1.

For v2 we may add a "cold tier" archive table with
monthly-aggregate-only rows that mirror dropped partitions.
v1 doesn't need that — `market_history` is the cold tier.

## Index impact

- **Existing indexes on market_orders are local per partition**
  in InnoDB RANGE partitioning, so DROP PARTITION reclaims
  both data and index space proportionally. The audit's 275 GB
  of index space is roughly 2/3 reclaimable on first rotation
  (older partitions hold proportionally more).
- **No new indexes** needed for v1.

## Expected reclaim

| scenario                                  | rows dropped | space reclaimed |
|-------------------------------------------|-------------:|----------------:|
| Drop partitions older than 90 days        |  ~700 M      |  180-300 GB     |
| Drop partitions older than 180 days       |  ~500 M      |  130-220 GB     |
| Drop partitions older than 365 days       |  ~300 M      |   80-130 GB     |

Stated estimates assume the row distribution is roughly
proportional to the time window. Real distribution will
differ — the partition sizes from the audit are unknown
because they're not exposed in `information_schema.partitions`
without root access. **Confirmed 0-row partitions:**
p2025_01, p2025_02, p2025_03 (already rolled off).

## Test plan

1. **Stage in test schema** first. Restore the latest backup
   to `aegiscore_test_partition` schema; run the DROP PARTITION
   sequence; measure data + index sizes; confirm queries still
   pass.
2. **EXPLAIN every dashboard query** that touches
   market_orders BEFORE and AFTER. Specifically:
   - `/portal/market` overview
   - `/portal/market/items/{id}/history`
   - any operator predict pages
3. **Replay** a known market dashboard interaction in the test
   schema to confirm no row-not-found errors after the drop.
4. **Time the operation** end-to-end. If >5 minutes, schedule
   a maintenance window; if <60 s, can run during business
   hours.
5. **Confirm market_history aggregates** still cover the
   pre-drop window post-operation.

## Open questions

- **Partition size measurement** — `mysql.innodb_index_stats`
  is needed for accurate per-partition / per-index byte counts.
  Currently only readable via root. Add `aegiscore` SELECT
  grant on that table before execution.
- **EveRef coordination** — if a deployment re-bulk-imports
  EveRef historical, do we want those rows in market_orders or
  market_history-only? Decision: market_history only. EveRef
  bulk import already targets market_history.
- **Per-region partition sub-key** — would partitioning by
  (observed_at, region_id) give better drop granularity
  (drop one region's 365d data without affecting others)?
  v1 decision: no, simpler is better. Re-evaluate in v2 with
  observed query patterns.

## v1 → v2 boundary

This ADR is **for v2 execution.** v1 closure does not require
the partition rotation. v1 closure requires:

1. The audit (done — `verification/storage/db_storage_audit.md`).
2. This ADR (done — this file).
3. A restore-tested backup younger than 24 hours
   (operational, ongoing).
4. No destructive market_orders work between now and v2.

When v2 entry is approved per
`memory/project_v1_v2_split.md`, this ADR's Phase A → C
sequence becomes the implementation plan. Not before.
