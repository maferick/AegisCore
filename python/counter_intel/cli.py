"""counter_intel entry point.

Usage:
  python -m counter_intel features              # run feature extraction
  python -m counter_intel features --window-end 2026-04-18
"""

from __future__ import annotations

import argparse
from datetime import date

from counter_intel.config import Config
from counter_intel.db import connection
from counter_intel.features import extract_and_persist
from counter_intel.log import get

log = get("counter_intel.cli")


def main() -> int:
    parser = argparse.ArgumentParser(prog="counter_intel")
    sub = parser.add_subparsers(dest="cmd", required=True)

    f = sub.add_parser("features", help="Extract character features into MariaDB.")
    f.add_argument("--window-end", type=str, default=None,
                   help="YYYY-MM-DD window end date (default: today UTC)")

    args = parser.parse_args()
    if args.cmd == "features":
        return _run_features(args)
    parser.print_help()
    return 2


def _run_features(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn:
        stats = extract_and_persist(conn, cfg, window_end=window_end)
    log.info("features pass complete", stats)
    return 0
