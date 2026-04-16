"""CLI entrypoint for killmail search indexer."""

from __future__ import annotations

import argparse
import time

from killmail_search import log as logmod
from killmail_search.config import Config
from killmail_search.indexer import run_backfill

log = logmod.get(__name__)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="killmail_search",
        description="Index killmails into OpenSearch for fast search.",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    bf = sub.add_parser("backfill", help="Bulk index all enriched killmails.")
    bf.add_argument("--dry-run", action="store_true")
    bf.add_argument("--interval", type=int, default=0, help="Loop mode: seconds between passes.")
    bf.add_argument("--log-level", default="INFO")

    args = parser.parse_args(argv)
    logmod.setup(args.log_level)

    if args.command == "backfill":
        overrides: dict = {}
        if args.dry_run:
            overrides["dry_run"] = True

        try:
            cfg = Config.from_env(**overrides)
        except RuntimeError as exc:
            log.error("config error", error=str(exc))
            return 2

        if args.interval <= 0:
            return run_backfill(cfg)

        log.info("backfill loop mode", interval_seconds=args.interval)
        while True:
            try:
                run_backfill(cfg)
            except Exception:
                log.exception("backfill pass failed")
            time.sleep(args.interval)

    return 0
