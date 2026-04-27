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

import json
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase49c_retention")


def _load_ttl_config() -> dict:
    p = Path(__file__).parent / "intel_ttl.json"
    with p.open() as f:
        return json.load(f)


_TTL_CONFIG = _load_ttl_config()


# ----------------------------------------------------------------------
# Per-table retention spec.
#
# Source of truth: `intel_ttl.json` (mirrored to PHP at
# app/config/intel_ttl.json — `make verify-ttl-config` enforces
# equality).
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

# Specs loaded from canonical JSON. Each entry coerced to tuple
# of (table, ts_col, ttl_days, where_clause, batch_size).
RETENTION: list[tuple[str, str, int, str, int]] = [
    (str(s[0]), str(s[1]), int(s[2]), str(s[3]), int(s[4]))
    for s in _TTL_CONFIG["retention_specs"]
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
