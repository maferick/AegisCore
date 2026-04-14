"""CLI entrypoint: `python -m market_importer ...`.

Env carries the DB + source details; flags cover op-mode knobs +
one-day replays.
"""

from __future__ import annotations

import argparse
import sys
from datetime import date

from market_importer.config import Config
from market_importer.log import get, setup
from market_importer.runner import run


def _parse_date_list(value: str) -> frozenset[date]:
    """Comma-separated ISO-8601 dates; empty = no filter."""
    if not value:
        return frozenset()
    parts = [p.strip() for p in value.split(",") if p.strip()]
    try:
        return frozenset(date.fromisoformat(p) for p in parts)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(
            f"--only-date takes ISO-8601 dates (YYYY-MM-DD): {exc}"
        ) from exc


def _parse_date_single(value: str) -> date:
    try:
        return date.fromisoformat(value)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(
            f"expected ISO-8601 date (YYYY-MM-DD), got: {value}"
        ) from exc


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="market_importer",
        description=(
            "Import EVE Ref's daily market-history CSV dumps into the "
            "MariaDB `market_history` table. Reconciles against "
            "data.everef.net/market-history/totals.json on every run — "
            "only downloads missing or partial days. Idempotent."
        ),
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help=(
            "Fetch + parse + count, but rollback every day's transaction "
            "before commit. Use to validate upstream availability, parser "
            "compatibility, and schema alignment without writing rows."
        ),
    )
    parser.add_argument(
        "--force-redownload",
        action="store_true",
        help=(
            "Re-download every day in [min,max], even if the local count "
            "already matches totals.json. Use after a suspected bad import."
        ),
    )
    parser.add_argument(
        "--only-date",
        type=_parse_date_list,
        default=frozenset(),
        help=(
            "Comma-separated ISO-8601 dates to import, skipping "
            "reconcile and all other days. Dates outside [min,max] are "
            "silently filtered out."
        ),
    )
    parser.add_argument(
        "--from",
        dest="from_date",
        type=_parse_date_single,
        default=None,
        help="Override MARKET_IMPORT_MIN_DATE (default 2025-01-01).",
    )
    parser.add_argument(
        "--to",
        dest="to_date",
        type=_parse_date_single,
        default=None,
        help="Override MARKET_IMPORT_MAX_DATE (default: yesterday UTC).",
    )
    parser.add_argument(
        "--batch-size",
        type=int,
        default=None,
        help="Override MARKET_IMPORT_BATCH_SIZE (default 5000).",
    )
    parser.add_argument(
        "--log-level",
        default="INFO",
        help="Python logging level (DEBUG, INFO, WARNING, ERROR).",
    )
    args = parser.parse_args(argv)

    setup(level=args.log_level)
    log = get("market_importer.cli")

    overrides: dict = {
        "dry_run": args.dry_run,
        "force_redownload": args.force_redownload,
        "only_dates": args.only_date,
    }
    if args.from_date is not None:
        overrides["min_date"] = args.from_date
    if args.to_date is not None:
        overrides["max_date"] = args.to_date
    if args.batch_size is not None:
        overrides["batch_size"] = args.batch_size

    try:
        cfg = Config.from_env(**overrides)
    except RuntimeError as exc:
        log.error("config error", error=str(exc))
        return 2

    try:
        return run(cfg)
    except Exception as exc:  # pragma: no cover — top-level safety net
        log.error("import aborted", error=str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
