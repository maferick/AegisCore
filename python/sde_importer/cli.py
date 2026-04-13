"""CLI entrypoint: `python -m sde_importer ...`.

Env-driven by default; flags only cover the iteration knobs an operator
actually wants from the shell (skip-download, only-download, log level).
Database + URL are deliberately not flags — they belong in the compose
env so prod and CI can't disagree.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from sde_importer.config import Config
from sde_importer.log import get, setup
from sde_importer.runner import run


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="sde_importer",
        description="Download CCP's EVE SDE JSONL zip and load it into MariaDB.",
    )
    parser.add_argument(
        "--only-download",
        action="store_true",
        help="Download + extract only; don't touch the database.",
    )
    parser.add_argument(
        "--skip-download",
        action="store_true",
        help="Reuse an existing extract dir instead of fetching the zip.",
    )
    parser.add_argument(
        "--extract-dir",
        type=Path,
        default=None,
        help="Override the extract directory (only meaningful with --skip-download).",
    )
    parser.add_argument(
        "--log-level",
        default="INFO",
        help="Python logging level (DEBUG, INFO, WARNING, ERROR).",
    )
    args = parser.parse_args(argv)

    setup(level=args.log_level)
    log = get("sde_importer.cli")

    if args.only_download and args.skip_download:
        parser.error("--only-download and --skip-download are mutually exclusive")

    try:
        cfg = Config.from_env(
            only_download=args.only_download,
            skip_download=args.skip_download,
            extract_dir_override=args.extract_dir,
        )
    except RuntimeError as exc:
        log.error("config error", error=str(exc))
        return 2

    try:
        return run(cfg)
    except Exception as exc:
        log.error("import aborted", error=str(exc))
        return 1


if __name__ == "__main__":
    sys.exit(main())
