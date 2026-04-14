"""End-to-end orchestrator for a market_history import pass.

Steps:

  1. Open MariaDB connection (autocommit off, per-day transactions).
  2. Fetch EVE Ref's totals.json manifest.
  3. SELECT local `COUNT(*) GROUP BY trade_date` for the target
     window to know which days we already have and how many rows.
  4. Reconcile: a day is a target if
       (a) local count is 0, or
       (b) local count < published total, or
       (c) `--force-redownload` is set
     and the day is inside `[cfg.min_date, cfg.max_date]`.
     `--only-date=...` short-circuits everything: if set, it's the
     exact target set.
  5. For each target day (ascending):
       a. Download the bz2 CSV.
       b. Parse + stream-upsert into `market_history` inside a tx.
       c. Emit `market.history_snapshot_loaded` into outbox.
       d. Commit (or rollback on dry-run / error).

A failure on one day doesn't stop the loop — each day has its own
try/except + its own transaction. Network blips, 404s on
not-yet-uploaded days, and CSV corruption all fall through to "log
and move on". The operator sees the count in the final summary.

Idempotent by construction:
  - `INSERT ... ON DUPLICATE KEY UPDATE` on the PK means re-running
    a day after a partial load or upstream count change converges
    on the latest-EVEref-known values.
  - Reconciliation skips already-complete days, so a re-run after a
    successful import is essentially a totals.json fetch + a local
    COUNT + exit.
"""

from __future__ import annotations

from datetime import date, timedelta

import pymysql

from market_importer.config import Config
from market_importer.db import connect, fetch_all
from market_importer.everef import (
    EverefError,
    EverefMissing,
    EverefTransient,
    fetch_day_csv_bytes,
    fetch_totals,
)
from market_importer.log import get
from market_importer.outbox import emit_history_snapshot_loaded
from market_importer.parse import CsvFormatError, parse_day_csv
from market_importer.persist import upsert_rows


log = get(__name__)


SOURCE = "everef_market_history"
OBSERVATION_KIND = "historical_dump"


def run(cfg: Config) -> int:
    log.info(
        "market_importer starting",
        min_date=cfg.min_date.isoformat(),
        max_date=cfg.max_date.isoformat(),
        dry_run=cfg.dry_run,
        force_redownload=cfg.force_redownload,
        only_dates=",".join(sorted(d.isoformat() for d in cfg.only_dates)) or "reconcile",
        batch_size=cfg.batch_size,
    )

    if cfg.min_date > cfg.max_date:
        log.error(
            "min_date is after max_date",
            min_date=cfg.min_date.isoformat(),
            max_date=cfg.max_date.isoformat(),
        )
        return 2

    loaded = 0
    skipped = 0
    failed = 0

    with connect(cfg) as conn:
        try:
            totals = fetch_totals(cfg)
        except EverefError as exc:
            log.error("totals fetch failed — cannot reconcile", error=str(exc))
            return 1

        local_counts = _local_counts(conn, cfg.min_date, cfg.max_date)
        targets = _pick_targets(cfg, totals, local_counts)
        log.info(
            "reconciliation complete",
            target_days=len(targets),
            already_complete=_count_complete(cfg, totals, local_counts),
        )

        for day in targets:
            outcome = _import_day(conn, cfg, day, totals.get(day))
            if outcome == "loaded":
                loaded += 1
            elif outcome == "skipped":
                skipped += 1
            else:
                failed += 1

    log.info("market_importer complete", loaded=loaded, skipped=skipped, failed=failed)
    return 0 if failed == 0 else 1


# -- internals ------------------------------------------------------------


def _local_counts(
    conn: pymysql.connections.Connection,
    min_date: date,
    max_date: date,
) -> dict[date, int]:
    """Run `SELECT trade_date, COUNT(*) ... GROUP BY trade_date` across
    the import window. Missing dates are implicit zeros — the caller
    uses `.get(day, 0)`."""
    rows = fetch_all(
        conn,
        """
        SELECT trade_date, COUNT(*) AS n
          FROM market_history
         WHERE trade_date BETWEEN %s AND %s
         GROUP BY trade_date
        """,
        (min_date, max_date),
    )
    out: dict[date, int] = {}
    for r in rows:
        # MariaDB returns trade_date as a datetime.date; type_id as int.
        td = r["trade_date"]
        out[td] = int(r["n"])
    return out


def _pick_targets(
    cfg: Config,
    totals: dict[date, int],
    local_counts: dict[date, int],
) -> list[date]:
    """Compute the list of days we actually want to (re)download.
    Always sorted ascending so partition writes land in monotonic
    order (fewer page-cache misses on the RANGE partitions)."""
    if cfg.only_dates:
        # Explicit override: do exactly these dates, even if fully
        # loaded. --force-redownload is implied by --only-date since
        # the operator has already asked for them by name.
        return sorted(d for d in cfg.only_dates if cfg.min_date <= d <= cfg.max_date)

    candidates: list[date] = []
    day = cfg.min_date
    while day <= cfg.max_date:
        published = totals.get(day)
        if published is None:
            # EVE Ref hasn't published anything for this day yet (future,
            # or upstream gap). Skip — can't reconcile against nothing.
            pass
        else:
            local = local_counts.get(day, 0)
            if cfg.force_redownload or local < published:
                candidates.append(day)
        day += timedelta(days=1)
    return candidates


def _count_complete(
    cfg: Config,
    totals: dict[date, int],
    local_counts: dict[date, int],
) -> int:
    """Count days in the window that are already locally complete
    (`local >= published`). Informational — helps the operator see
    the reconcile fraction at a glance."""
    n = 0
    day = cfg.min_date
    while day <= cfg.max_date:
        published = totals.get(day)
        if published is not None and local_counts.get(day, 0) >= published:
            n += 1
        day += timedelta(days=1)
    return n


def _import_day(
    conn: pymysql.connections.Connection,
    cfg: Config,
    day: date,
    published: int | None,
) -> str:
    """Fetch + parse + upsert one day. Returns
    'loaded' | 'skipped' | 'failed' for the summary counters."""
    log.info("importing day", day=day.isoformat(), published=published)
    try:
        blob = fetch_day_csv_bytes(cfg, day)
    except EverefMissing as exc:
        log.info("day not published yet, skipping", day=day.isoformat(), reason=str(exc))
        return "skipped"
    except EverefTransient as exc:
        log.warning("transient everef error — will retry next run", day=day.isoformat(), error=str(exc))
        return "failed"

    try:
        rows_iter = parse_day_csv(blob)
        received, affected = upsert_rows(
            conn,
            rows_iter,
            source=SOURCE,
            observation_kind=OBSERVATION_KIND,
            batch_size=cfg.batch_size,
        )
    except CsvFormatError as exc:
        conn.rollback()
        log.error("csv format error — day rolled back", day=day.isoformat(), error=str(exc))
        return "failed"
    except Exception:
        conn.rollback()
        log.exception("unexpected error during import — day rolled back", day=day.isoformat())
        return "failed"

    if cfg.dry_run:
        conn.rollback()
        log.info(
            "dry-run — rolled back",
            day=day.isoformat(),
            rows_received=received,
            would_affect=affected,
        )
        return "loaded"

    emit_history_snapshot_loaded(
        conn,
        trade_date=day,
        rows_received=received,
        rows_affected=affected,
        source=SOURCE,
        observation_kind=OBSERVATION_KIND,
    )
    conn.commit()
    log.info(
        "day imported",
        day=day.isoformat(),
        rows_received=received,
        rows_affected=affected,
        published=published,
    )
    return "loaded"
