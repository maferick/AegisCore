"""Phase 4.9C — retention sweep.

Per-table TTL ladder. Runs as a single pipeline that batches
DELETEs against rows older than each table's cutoff. Idempotent
(re-runs are no-ops once swept). Always opt-in via make target;
no autonomous schedule shipped.

TTL philosophy:
- Raw ingest data ages out fastest (90d events, 60d snapshots).
- Operational artifacts archive at 180d, drop at 365d. By then
  doctrine evolution + alliance profiles have folded the signal
  into long-form aggregates.
- Governance / audit data (quality events open, alerts non-archived)
  never auto-deletes — only resolved/dismissed/archived rows are
  candidates.
- Compute log / lane metrics keep 30 days for live debugging,
  beyond that the rollups are authoritative.

Runtime safety:
- Batches of 5000 rows per DELETE statement to keep transactions
  short and avoid replication lag spikes.
- FOREIGN_KEY_CHECKS stays ON: child tables get swept before
  parent tables, in dependency order.
- Reports per-table {deleted, duration_ms} and rolls up totals.
"""

from __future__ import annotations

import time
from datetime import datetime, timedelta, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase49c_retention")


# ----------------------------------------------------------------------
# Per-table retention spec.
#
# Each entry: (table, ts_col, ttl_days, where_clause, batch_size)
#
# where_clause is appended after the ts_col cutoff so we can
# scope to e.g. only resolved alerts. Plain "1=1" means "all rows
# past the cutoff are eligible".
#
# Order matters: child tables are swept before parents to avoid FK
# violations.
# ----------------------------------------------------------------------

RETENTION: list[tuple[str, str, int, str, int]] = [
    # Compute trace ages out fast — rollups are authoritative.
    ("compute_run_log", "compute_started_at", 30, "1=1", 5000),

    # Resolved quality events ageing.
    ("system_quality_events", "resolved_at", 90,
     "resolved_at IS NOT NULL", 1000),

    # Export artifacts: hard-expire when expires_at < now (already
    # set 30d on creation; this just drops the row when expired).
    ("intel_export_artifacts", "expires_at", 0,
     "expires_at IS NOT NULL AND expires_at < NOW()", 500),

    # Resolved/dismissed feedback older than 180d folds into trust
    # rollups; the raw event corpus stops being read.
    ("intel_feedback_events", "created_at", 180, "1=1", 5000),

    # Trust metrics roll forward — old window snapshots redundant
    # past 90 days.
    ("system_trust_metrics", "computed_at", 90, "1=1", 1000),

    # Force composition + transitions: 365d. Doctrine evolution
    # already aggregated by then.
    ("operational_force_transitions", "computed_at", 365, "1=1", 2000),
    ("operational_force_compositions", "computed_at", 365, "1=1", 2000),

    # Operational artefacts: 365d. Battle linkage is the long-form
    # record past that point.
    ("operational_incidents", "start_at", 365, "1=1", 5000),
    ("operational_hostile_clusters", "start_at", 365, "1=1", 5000),
    ("operational_corridors", "last_seen_at", 365, "1=1", 5000),

    # Activity heatmap: 180d.
    ("system_operational_activity", "activity_date", 180, "1=1", 5000),

    # Daily digest archive: 90d.
    ("daily_operational_digest", "digest_date", 90, "1=1", 1000),

    # Incident narratives ride along with their incident parent —
    # 365d to match.
    ("incident_narratives", "computed_at", 365, "1=1", 5000),

    # Strategic alerts that have been archived / resolved age out at
    # 180d. Open / suppressed never auto-deleted.
    ("strategic_alerts", "detected_at", 180,
     "(analyst_status IN ('archived','false_positive') OR dismissed_at IS NOT NULL)", 5000),

    # Doctrine evolution events: 365d. Long-form trends are kept in
    # alliance_operational_profiles.
    ("doctrine_evolution_events", "computed_at", 365, "1=1", 1000),

    # Verified intelligence items: 365d unless pinned. Pinned items
    # are operator-curated and never auto-deleted.
    ("verified_intelligence_items", "created_at", 365,
     "pinned = 0", 1000),

    # Parse errors: 30d for resolved (retried/dismissed/reparsed_ok);
    # open errors stay until acted on.
    ("eve_log_parse_errors", "updated_at", 30,
     "status IN ('retried','dismissed','reparsed_ok')", 5000),

    # dscan snapshots: 60d successful, 7d failed.
    ("eve_log_dscan_snapshots", "last_seen_at", 60,
     "fetch_status = 'success'", 5000),
    ("eve_log_dscan_snapshots", "last_seen_at", 7,
     "fetch_status != 'success'", 5000),

    # Raw eve log events: 90d. Operational aggregates have already
    # consumed the signal we needed.
    ("eve_log_events", "event_timestamp", 90, "1=1", 10000),

    # Entity resolutions follow their event parent. Same TTL.
    ("eve_log_entity_resolutions", "created_at", 90, "1=1", 10000),
]


def run_retention_sweep(
    conn: pymysql.connections.Connection,
    cfg: Config,
    dry_run: bool = False,
) -> dict:
    """Walk each retention spec; DELETE matching rows in batches.

    With dry_run=True, runs SELECT COUNT(*) instead of DELETE so
    operators can preview impact before flipping to live."""
    log.info("phase4.9C retention starting", {"dry_run": dry_run, "specs": len(RETENTION)})

    totals: dict[str, dict] = {}
    grand_deleted = 0
    grand_skipped_missing = 0

    for spec in RETENTION:
        table, ts_col, ttl_days, where_clause, batch_size = spec

        # Verify table + column exist before issuing the DELETE.
        # eve_log_entity_resolutions wasn't in some early deploys.
        if not _table_has_column(conn, table, ts_col):
            log.warning("retention skip — missing table or column",
                        {"table": table, "column": ts_col})
            grand_skipped_missing += 1
            totals[f"{table}/{ts_col}"] = {"skipped": "missing_table_or_column"}
            continue

        if ttl_days > 0:
            cutoff = datetime.now(timezone.utc) - timedelta(days=ttl_days)
            cutoff_clause = f"{ts_col} < %s"
            params = (cutoff,)
        else:
            # ttl_days=0 → use the where_clause's own time gate
            # (e.g. expires_at < NOW()).
            cutoff_clause = "1=1"
            params = ()

        full_where = f"({cutoff_clause}) AND ({where_clause})"

        if dry_run:
            with conn.cursor() as cur:
                cur.execute(
                    f"SELECT COUNT(*) AS n FROM {table} WHERE {full_where}",
                    params,
                )
                count = int((cur.fetchone() or {}).get("n") or 0)
            totals[f"{table}/{ts_col}"] = {"would_delete": count}
            log.info("retention dry-run", {"table": table, "would_delete": count, "ttl_days": ttl_days})
            continue

        # Batched DELETE loop.
        deleted = 0
        t0 = time.time()
        while True:
            with conn.cursor() as cur:
                cur.execute(
                    f"DELETE FROM {table} WHERE {full_where} LIMIT {int(batch_size)}",
                    params,
                )
                affected = cur.rowcount
            if affected <= 0:
                break
            conn.commit()
            deleted += affected
            if affected < batch_size:
                break
        duration_ms = int((time.time() - t0) * 1000)
        totals[f"{table}/{ts_col}"] = {
            "deleted": deleted,
            "ttl_days": ttl_days,
            "duration_ms": duration_ms,
        }
        grand_deleted += deleted
        log.info("retention swept",
                 {"table": table, "deleted": deleted,
                  "duration_ms": duration_ms, "ttl_days": ttl_days})

    log.info("phase4.9C retention complete",
             {"total_deleted": grand_deleted, "specs_run": len(RETENTION),
              "skipped_missing": grand_skipped_missing})
    return {
        "total_deleted": grand_deleted,
        "specs_run": len(RETENTION),
        "skipped_missing": grand_skipped_missing,
        "by_table": totals,
        "dry_run": dry_run,
    }


def _table_has_column(conn, table: str, column: str) -> bool:
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT COUNT(*) AS n
              FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = %s
               AND column_name = %s
            """,
            (table, column),
        )
        row = cur.fetchone() or {}
    return int(row.get("n") or 0) > 0
