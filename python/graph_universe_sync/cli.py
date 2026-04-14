"""CLI entrypoint: `python -m graph_universe_sync ...`.

Env vars carry the connection details (DB + Neo4j); flags only cover
the iteration knobs an operator wants from the shell.
"""

from __future__ import annotations

import argparse
import sys

from graph_universe_sync.config import KNOWN_STAGES, Config
from graph_universe_sync.log import get, setup
from graph_universe_sync.runner import run


def _parse_only(value: str) -> frozenset[str]:
    """Comma-separated stage list, validated against KNOWN_STAGES."""
    if not value:
        return frozenset()
    parts = [p.strip() for p in value.split(",") if p.strip()]
    unknown = sorted(set(parts) - KNOWN_STAGES)
    if unknown:
        raise argparse.ArgumentTypeError(
            f"unknown stage(s): {','.join(unknown)} "
            f"(known: {','.join(sorted(KNOWN_STAGES))})"
        )
    return frozenset(parts)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="graph_universe_sync",
        description="Project SDE universe topology from MariaDB into Neo4j.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Log row/edge counts only; do not write to Neo4j.",
    )
    parser.add_argument(
        "--rebuild",
        action="store_true",
        help=(
            "DETACH DELETE existing :Region/:Constellation/:System/:Station "
            "nodes before MERGE. Use after schema-shape changes; otherwise "
            "MERGE is idempotent and a plain re-run is enough."
        ),
    )
    parser.add_argument(
        "--skip-indices",
        action="store_true",
        help="Skip the CREATE CONSTRAINT bootstrap (already idempotent).",
    )
    parser.add_argument(
        "--only",
        type=_parse_only,
        default=frozenset(),
        help=(
            "Run only the listed stages (comma-separated). "
            f"Known: {','.join(sorted(KNOWN_STAGES))}. Default: all."
        ),
    )
    parser.add_argument(
        "--chunk-size",
        type=int,
        default=None,
        help="Override GRAPH_BATCH_SIZE (default 2000).",
    )
    parser.add_argument(
        "--log-level",
        default="INFO",
        help="Python logging level (DEBUG, INFO, WARNING, ERROR).",
    )
    args = parser.parse_args(argv)

    setup(level=args.log_level)
    log = get("graph_universe_sync.cli")

    overrides: dict = {
        "dry_run": args.dry_run,
        "rebuild": args.rebuild,
        "skip_indices": args.skip_indices,
        "only_stages": args.only,
    }
    if args.chunk_size is not None:
        overrides["batch_size"] = args.chunk_size

    try:
        cfg = Config.from_env(**overrides)
    except RuntimeError as exc:
        log.error("config error", error=str(exc))
        return 2

    try:
        return run(cfg)
    except Exception as exc:  # pragma: no cover — top-level safety net
        log.error("projection aborted", error=str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
