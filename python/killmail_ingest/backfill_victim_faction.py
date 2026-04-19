"""Backfill killmails.victim_faction_id from EVE Ref archives.

The column was added after the original ingest ran, so historical rows
are NULL. Rather than redoing the full killmail+attackers+items
ingest, this pass reads only the victim.faction_id field from the
archive JSONs and UPDATEs the single column.

Only emits rows where victim.faction_id is set — ~10% of killmails —
so the write load is bounded regardless of archive size.
"""

from __future__ import annotations

from datetime import date, timedelta
from typing import Iterable

from killmail_ingest.config import Config
from killmail_ingest.db import connect
from killmail_ingest.everef import EverefMissing, EverefTransient, fetch_day_killmails
from killmail_ingest.log import get


log = get(__name__)


def run(cfg: Config, days: int = 90, only_dates: Iterable[date] | None = None) -> dict:
    today = date.today()
    if only_dates:
        work = sorted(set(only_dates))
    else:
        work = [today - timedelta(days=i) for i in range(days)]
        work.sort()

    stats = {"days_processed": 0, "killmails_seen": 0, "victim_faction_updates": 0, "days_missing": 0}
    with connect(cfg) as conn:
        for d in work:
            try:
                archive = fetch_day_killmails(cfg, d)
            except EverefMissing:
                stats["days_missing"] += 1
                log.info("archive missing", date=d.isoformat())
                continue
            except EverefTransient as exc:
                log.warning("archive transient error — skipping", date=d.isoformat(), error=str(exc))
                continue

            updates: list[tuple[int, int]] = []
            for km in archive:
                stats["killmails_seen"] += 1
                victim = km.get("victim") or {}
                faction_id = victim.get("faction_id")
                if faction_id is None:
                    continue
                try:
                    kid = int(km["killmail_id"])
                except (KeyError, TypeError, ValueError):
                    continue
                updates.append((int(faction_id), kid))

            if updates:
                _bulk_update(conn, updates)
                stats["victim_faction_updates"] += len(updates)

            stats["days_processed"] += 1
            log.info("day done", date=d.isoformat(), archive_kms=len(archive),
                     updates=len(updates))

    log.info("backfill complete", **stats)
    return stats


def _bulk_update(conn, rows: list[tuple[int, int]]) -> None:
    """executemany UPDATE by primary key — MariaDB handles 1000s/sec."""
    batch = 2000
    with conn.cursor() as cur:
        for i in range(0, len(rows), batch):
            chunk = rows[i:i + batch]
            cur.executemany(
                "UPDATE killmails SET victim_faction_id=%s WHERE killmail_id=%s",
                chunk,
            )
    conn.commit()
