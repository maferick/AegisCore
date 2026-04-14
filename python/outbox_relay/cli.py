"""CLI entrypoint: `python -m outbox_relay ...`.

Two operating modes (same shape as market_poller / market_importer):

  - **One-shot drain** (default): claim + project until the queue
    is empty, then exit. What `make outbox-relay` invokes.
  - **Loop** (`--interval N` where N > 0): claim + project; sleep
    N seconds when idle; immediately re-poll when there's work.
    What the `outbox_relay` long-lived compose service uses.
"""

from __future__ import annotations

import argparse
import sys

from outbox_relay.config import Config
from outbox_relay.log import get, setup
from outbox_relay.relay import run_loop, run_one_pass


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="outbox_relay",
        description=(
            "Claim + project + ack rows from the MariaDB `outbox` "
            "table. Default: drain the queue once and exit. With "
            "--interval, runs forever as a long-lived consumer."
        ),
    )
    parser.add_argument(
        "--interval",
        type=int,
        default=0,
        help=(
            "Loop mode — when the queue is empty, sleep this many "
            "seconds before polling again. 0 (default) = drain "
            "queue once + exit. 5 is the in-stack default for the "
            "long-lived service."
        ),
    )
    parser.add_argument(
        "--batch-size",
        type=int,
        default=None,
        help="Override OUTBOX_RELAY_BATCH_SIZE (default 50).",
    )
    parser.add_argument(
        "--max-attempts",
        type=int,
        default=None,
        help=(
            "Override OUTBOX_RELAY_MAX_ATTEMPTS (default 5). "
            "Events with attempts >= this value stop being claimed "
            "and are parked as dead letters until an operator "
            "resets attempts to 0."
        ),
    )
    parser.add_argument(
        "--log-level",
        default="INFO",
        help="Python logging level (DEBUG, INFO, WARNING, ERROR).",
    )
    args = parser.parse_args(argv)

    setup(level=args.log_level)
    log = get("outbox_relay.cli")

    overrides: dict = {}
    if args.batch_size is not None:
        overrides["batch_size"] = args.batch_size
    if args.max_attempts is not None:
        overrides["max_attempts"] = args.max_attempts

    try:
        cfg = Config.from_env(**overrides)
    except RuntimeError as exc:
        log.error("config error", error=str(exc))
        return 2

    if args.interval <= 0:
        # One-shot drain mode. Loop locally until queue empty so
        # `make outbox-relay` actually drains, not just one batch.
        log.info("outbox_relay starting in one-shot drain mode", batch_size=cfg.batch_size)
        try:
            total_succeeded = 0
            total_failed = 0
            total_points = 0
            while True:
                result = run_one_pass(cfg)
                total_succeeded += result.succeeded
                total_failed += result.failed
                total_points += result.points_written
                if result.queue_empty:
                    break
            log.info(
                "drain complete",
                succeeded=total_succeeded,
                failed=total_failed,
                points_written=total_points,
            )
            return 0 if total_failed == 0 else 1
        except Exception as exc:  # pragma: no cover — top-level safety net
            log.error("drain aborted", error=str(exc))
            return 1

    # Loop mode — long-lived consumer.
    try:
        run_loop(cfg, interval_seconds=args.interval)
        return 0
    except Exception as exc:  # pragma: no cover — top-level safety net
        log.error("loop aborted", error=str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
