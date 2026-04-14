"""`python -m market_poller` entrypoint."""

from __future__ import annotations

import sys

from market_poller.cli import main


if __name__ == "__main__":
    sys.exit(main())
