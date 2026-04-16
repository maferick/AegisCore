"""`python -m killmail_search` entrypoint."""

from __future__ import annotations

import sys

from killmail_search.cli import main

if __name__ == "__main__":
    sys.exit(main())
