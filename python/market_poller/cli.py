"""CLI entrypoint: `python -m market_poller ...`.

Env vars carry the connection + ESI details; flags only cover the
op-mode knobs an operator wants from the shell (dry-run, one-location
replay, batch size override, loop-mode interval).

Two operating modes:

  - **One-shot** (default, `--interval 0`): runs one pass and exits.
    What `make market-poll` invokes for ad-hoc operator runs.
  - **Loop** (`--interval N` where N > 0): runs a pass, sleeps N
    seconds, runs another pass, repeats forever. What the
    `market_poll_scheduler` compose service uses to provide the
    sustained 5-minute cadence ESI region-orders publish on.

A pass that crashes in loop mode is logged + the loop continues
into its next sleep; the per-location transaction boundary in the
runner makes a partial-pass crash safe to recover from on the next
tick.
"""

from __future__ import annotations

import argparse
import sys
import time

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
            "Default: one pass per invocation. With --interval, runs in "
            "a loop forever."
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
        "--interval",
        type=int,
        default=0,
        help=(
            "Loop mode — after each pass sleep this many seconds, then "
            "run again. 0 (default) = single-pass + exit. Recommended "
            "in-stack value is 300, matching CCP's region-orders cache "
            "window."
        ),
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

    if args.interval <= 0:
        # One-shot mode — original behaviour.
        try:
            return run(cfg)
        except Exception as exc:  # pragma: no cover — top-level safety net
            log.error("poll aborted", error=str(exc))
            return 1

    # Loop mode. Crashes inside `run()` log + continue; SIGTERM from
    # `docker compose down` interrupts `time.sleep()` and exits with a
    # non-zero rc, which docker treats as a normal stop because we're
    # under `restart: unless-stopped`.
    log.info("entering loop mode", interval_seconds=args.interval)
    while True:
        try:
            run(cfg)
        except Exception:  # pragma: no cover — keep the loop running
            log.exception("pass crashed; will retry on next tick")
        log.info("pass complete; sleeping", interval_seconds=args.interval)
        try:
            time.sleep(args.interval)
        except KeyboardInterrupt:
            log.info("interrupted; exiting loop")
            return 0


if __name__ == "__main__":
    sys.exit(main())
