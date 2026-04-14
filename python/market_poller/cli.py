"""CLI entrypoint: `python -m market_poller ...`.

Env vars carry the connection + ESI details; flags only cover the
op-mode knobs an operator wants from the shell (dry-run, one-location
replay, batch size override).
"""

from __future__ import annotations

import argparse
import sys

from market_poller.config import Config
from market_poller.log import get, setup
from market_poller.runner import run


def _parse_location_ids(value: str) -> frozenset[int]:
    """Comma-separated location-id list; empty = all enabled rows."""
    if not value:
        return frozenset()
    parts = [p.strip() for p in value.split(",") if p.strip()]
    try:
        return frozenset(int(p) for p in parts)
    except ValueError as exc:
        raise argparse.ArgumentTypeError(
            f"--only-location-id takes integer IDs, got: {value}"
        ) from exc


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="market_poller",
        description=(
            "Pull order-book snapshots from ESI for every enabled row "
            "in market_watched_locations and insert into market_orders. "
            "One pass per invocation — cadence is the caller's job."
        ),
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help=(
            "Fetch and log but roll back every insert. Useful for "
            "validating ESI connectivity + schema alignment without "
            "writing rows."
        ),
    )
    parser.add_argument(
        "--only-location-id",
        type=_parse_location_ids,
        default=frozenset(),
        help=(
            "Comma-separated location IDs to poll, skipping all others. "
            "Useful for an operator one-shot replay after an incident."
        ),
    )
    parser.add_argument(
        "--batch-size",
        type=int,
        default=None,
        help="Override MARKET_POLL_BATCH_SIZE (default 5000).",
    )
    parser.add_argument(
        "--log-level",
        default="INFO",
        help="Python logging level (DEBUG, INFO, WARNING, ERROR).",
    )
    args = parser.parse_args(argv)

    setup(level=args.log_level)
    log = get("market_poller.cli")

    overrides: dict = {
        "dry_run": args.dry_run,
        "only_location_ids": args.only_location_id,
    }
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
        log.error("poll aborted", error=str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
