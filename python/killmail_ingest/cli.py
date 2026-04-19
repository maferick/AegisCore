"""CLI entrypoint with two subcommands: backfill + stream."""

from __future__ import annotations

import argparse
import sys
import time
from datetime import date

from killmail_ingest import log as logmod
from killmail_ingest.backfill import run_backfill
from killmail_ingest.backfill_victim_faction import run as run_victim_faction_backfill
from killmail_ingest.config import Config
from killmail_ingest.stream import run_stream


log = logmod.get(__name__)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="killmail_ingest",
        description="Killmail data acquisition — EVE Ref backfill + R2Z2 live stream.",
    )
    sub = parser.add_subparsers(dest="command", required=True)

    # -- backfill subcommand -----------------------------------------------

    bf = sub.add_parser("backfill", help="Historical backfill from EVE Ref daily archives.")
    bf.add_argument("--dry-run", action="store_true", help="Parse + count, rollback all writes.")
    bf.add_argument("--from", dest="from_date", type=str, default=None, help="Override min_date (YYYY-MM-DD).")
    bf.add_argument("--to", dest="to_date", type=str, default=None, help="Override max_date (YYYY-MM-DD).")
    bf.add_argument("--only-date", type=str, action="append", default=[], help="Process only these dates.")
    bf.add_argument("--interval", type=int, default=0, help="Loop mode: seconds between passes (0 = one-shot).")
    bf.add_argument("--log-level", default="INFO")

    # -- stream subcommand -------------------------------------------------

    st = sub.add_parser("stream", help="Real-time ingestion from R2Z2 (zKillboard sequence stream).")
    st.add_argument("--dry-run", action="store_true", help="Fetch + parse, rollback all writes.")
    st.add_argument("--log-level", default="INFO")

    # -- backfill-victim-faction subcommand --------------------------------

    bvf = sub.add_parser("backfill-victim-faction",
                         help="Backfill killmails.victim_faction_id from EVE Ref archives only.")
    bvf.add_argument("--days", type=int, default=90, help="Last N days to cover (default 90).")
    bvf.add_argument("--only-date", type=str, action="append", default=[], help="Only process these dates.")
    bvf.add_argument("--log-level", default="INFO")

    args = parser.parse_args(argv)
    logmod.setup(args.log_level)

    if args.command == "backfill":
        return _run_backfill(args)
    elif args.command == "stream":
        return _run_stream(args)
    elif args.command == "backfill-victim-faction":
        return _run_backfill_victim_faction(args)
    else:
        parser.print_help()
        return 2


def _run_backfill_victim_faction(args: argparse.Namespace) -> int:
    try:
        cfg = Config.from_env()
    except RuntimeError as exc:
        log.error("config error", error=str(exc))
        return 2
    only_dates = [date.fromisoformat(d) for d in args.only_date] if args.only_date else None
    run_victim_faction_backfill(cfg, days=args.days, only_dates=only_dates)
    return 0


def _run_backfill(args: argparse.Namespace) -> int:
    overrides: dict = {}

    if args.dry_run:
        overrides["dry_run"] = True
    if args.from_date:
        overrides["min_date"] = date.fromisoformat(args.from_date)
    if args.to_date:
        overrides["max_date"] = date.fromisoformat(args.to_date)
    if args.only_date:
        overrides["only_dates"] = frozenset(date.fromisoformat(d) for d in args.only_date)

    try:
        cfg = Config.from_env(**overrides)
    except RuntimeError as exc:
        log.error("config error", error=str(exc))
        return 2

    if args.interval <= 0:
        # One-shot mode.
        return run_backfill(cfg)

    # Loop mode.
    log.info("backfill loop mode", interval_seconds=args.interval)
    while True:
        try:
            run_backfill(cfg)
        except Exception:
            log.exception("backfill pass failed")
        time.sleep(args.interval)


def _run_stream(args: argparse.Namespace) -> int:
    overrides: dict = {}

    if args.dry_run:
        overrides["dry_run"] = True

    try:
        cfg = Config.from_env(**overrides)
    except RuntimeError as exc:
        log.error("config error", error=str(exc))
        return 2

    return run_stream(cfg)
