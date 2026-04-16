"""`python -m killmail_ingest` entrypoint."""

from __future__ import annotations

import sys

from killmail_ingest.cli import main


if __name__ == "__main__":
    sys.exit(main())
