"""`python -m market_importer` entrypoint."""

from __future__ import annotations

import sys

from market_importer.cli import main


if __name__ == "__main__":
    sys.exit(main())
