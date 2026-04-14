"""`python -m graph_universe_sync` entrypoint."""

from __future__ import annotations

import sys

from graph_universe_sync.cli import main


if __name__ == "__main__":
    sys.exit(main())
