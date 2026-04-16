"""EVE Ref historical backfill runner.

Reconciles local state against EVE Ref's totals.json manifest,
downloads missing or updated days, and ingests all killmails via
the shared persist.ingest_killmail() entry point.

Transaction granularity: per-day. A day either fully loads or rolls
back. State is updated after successful commit.
"""

from __future__ import annotations

from datetime import date, timedelta

import pymysql

from killmail_ingest.config import Config
from killmail_ingest.db import connect
from killmail_ingest.everef import (
    EverefMissing,
    EverefTransient,
    fetch_day_killmails,
    fetch_totals,
)
from killmail_ingest.log import get
from killmail_ingest.outbox import emit_killmail_ingested
from killmail_ingest.parse import parse_esi_killmail
from killmail_ingest.persist import ingest_killmail
from killmail_ingest.state import get_all_states, set_state


log = get(__name__)


def run_backfill(cfg: Config) -> int:
    """Run one backfill pass. Returns 0 on success, 1 if any day failed."""
    log.info(
        "backfill starting",
        min_date=cfg.min_date.isoformat(),
        max_date=cfg.max_date.isoformat(),
        recheck_days=cfg.recheck_window_days,
    )

    # Fetch the manifest.
    try:
        totals = fetch_totals(cfg)
    except (EverefMissing, EverefTransient) as exc:
        log.error("failed to fetch totals.json", error=str(exc))
        return 1

    log.info("totals.json loaded", days=len(totals))

    with connect(cfg) as conn:
        local_state = get_all_states(conn, "everef")

        # Build target list: days that need (re)processing.
        targets = _compute_targets(cfg, totals, local_state)

        if not targets:
            log.info("backfill: nothing to do")
            return 0

        log.info("backfill: targets computed", days=len(targets))

        loaded = 0
        skipped = 0
        failed = 0

        for day in sorted(targets):
            try:
                count = _process_day(conn, cfg, day)
                loaded += 1
                log.info("day loaded", day=day.isoformat(), killmails=count)
            except EverefMissing:
                skipped += 1
                log.info("day skipped (no archive)", day=day.isoformat())
            except (EverefTransient, Exception) as exc:
                failed += 1
                log.error("day failed", day=day.isoformat(), error=str(exc))
                try:
                    conn.rollback()
                except Exception:
                    pass

    log.info("backfill complete", loaded=loaded, skipped=skipped, failed=failed)
    return 1 if failed > 0 else 0


def _compute_targets(
    cfg: Config,
    totals: dict[date, int],
    local_state: dict[str, str],
) -> list[date]:
    """Determine which days need processing."""
    targets = []
    recheck_start = cfg.max_date - timedelta(days=cfg.recheck_window_days)

    # Filter to configured date range.
    candidate_days = sorted(
        d for d in totals
        if cfg.min_date <= d <= cfg.max_date
    )

    # Override with only_dates if specified.
    if cfg.only_dates:
        return sorted(cfg.only_dates)

    for day in candidate_days:
        local_count_str = local_state.get(day.isoformat())
        remote_count = totals[day]

        if local_count_str is None:
            # Never processed — must download.
            targets.append(day)
        elif day >= recheck_start:
            # Within rolling recheck window — re-check if count changed.
            if int(local_count_str) < remote_count:
                targets.append(day)
        # Otherwise: already processed and outside recheck window, skip.

    return targets


def _process_day(
    conn: pymysql.connections.Connection,
    cfg: Config,
    day: date,
) -> int:
    """Download, parse, and ingest one day's killmails. Returns count."""
    raw_killmails = fetch_day_killmails(cfg, day)

    if not raw_killmails:
        # Empty archive — mark as processed with 0 count.
        set_state(conn, "everef", day.isoformat(), "0")
        conn.commit()
        return 0

    new_count = 0
    update_count = 0

    for raw in raw_killmails:
        try:
            km = parse_esi_killmail(raw)
            was_new = ingest_killmail(conn, km)
            emit_killmail_ingested(conn, km=km)

            if was_new:
                new_count += 1
            else:
                update_count += 1
        except Exception as exc:
            log.warning(
                "skipping killmail",
                killmail_id=raw.get("killmail_id", "?"),
                day=day.isoformat(),
                error=str(exc),
            )

    total = new_count + update_count
    set_state(conn, "everef", day.isoformat(), str(total))

    if cfg.dry_run:
        conn.rollback()
        log.info("dry-run rollback", day=day.isoformat(), parsed=total)
    else:
        conn.commit()

    return total
