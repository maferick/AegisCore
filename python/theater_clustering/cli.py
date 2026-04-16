"""CLI for theater_clustering.

Modes:
  run         — single clustering pass, then exit.
  loop        — scheduler: one pass every `THEATER_SCHEDULER_INTERVAL_SECONDS`.

Env knobs (via Config.from_env):
  THEATER_PROXIMITY_SECONDS         default 2700
  THEATER_MIN_PARTICIPANTS          default 10
  THEATER_LOCK_AFTER_HOURS          default 48
  THEATER_WINDOW_HOURS              default 48
  THEATER_SCHEDULER_INTERVAL_SECONDS default 300
"""

from __future__ import annotations

import argparse
import sys
import time

from theater_clustering.clusterer import cluster_killmails
from theater_clustering.config import Config
from theater_clustering.db import connection
from theater_clustering.log import get
from theater_clustering.persist import (
    lock_aged_theaters,
    load_candidates,
    persist_clusters,
)


log = get(__name__)


def run_once(cfg: Config) -> None:
    started = time.time()
    with connection(cfg) as conn:
        killmails, attackers_by_km = load_candidates(conn, cfg.window_hours)

        clusters = cluster_killmails(
            killmails,
            attackers_by_km,
            proximity_seconds=cfg.proximity_seconds,
            min_participants=cfg.min_participants,
        )

        kms_by_id = {km.killmail_id: km for km in killmails}
        theaters_written, participants_written = persist_clusters(
            conn, clusters, kms_by_id, attackers_by_km,
        )
        locked = lock_aged_theaters(conn, cfg.lock_after_hours)

    log.info(
        "theater-clustering: pass complete",
        candidates=len(killmails),
        clusters=len(clusters),
        theaters_written=theaters_written,
        participants_written=participants_written,
        newly_locked=locked,
        duration_s=round(time.time() - started, 2),
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="theater_clustering",
        description="Group killmails into battle theaters.",
    )
    sub = parser.add_subparsers(dest="mode", required=True)
    sub.add_parser("run", help="single clustering pass, then exit")
    sub.add_parser("loop", help="scheduler: one pass every interval")
    args = parser.parse_args(argv)

    cfg = Config.from_env()

    if args.mode == "run":
        run_once(cfg)
        return 0

    if args.mode == "loop":
        interval = cfg.scheduler_interval_seconds
        log.info("theater-clustering: scheduler online", interval_s=interval)
        while True:
            try:
                run_once(cfg)
            except Exception as exc:
                log.error("theater-clustering: pass failed", error=str(exc))
            time.sleep(interval)

    return 2


if __name__ == "__main__":
    sys.exit(main())
