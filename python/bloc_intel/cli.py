"""bloc_intel entry point.

Usage:
  python -m bloc_intel extract                 # rolling window ending today
  python -m bloc_intel extract --window-end 2026-04-18
"""

from __future__ import annotations

import argparse
from datetime import date

from bloc_intel.config import Config
from bloc_intel.db import connection
from bloc_intel.extractor import compute
from bloc_intel.log import get

log = get("bloc_intel.cli")


def main() -> int:
    parser = argparse.ArgumentParser(prog="bloc_intel")
    sub = parser.add_subparsers(dest="cmd", required=True)

    e = sub.add_parser("extract", help="Compute alliance-pair behavior over the rolling window.")
    e.add_argument("--window-end", type=str, default=None,
                   help="YYYY-MM-DD (default: today UTC)")

    args = parser.parse_args()
    if args.cmd == "extract":
        cfg = Config.from_env()
        window_end = date.fromisoformat(args.window_end) if args.window_end else None
        with connection(cfg) as conn:
            stats = compute(conn, cfg, window_end=window_end)
        log.info("extract pass complete", stats)
        return 0
    parser.print_help()
    return 2
