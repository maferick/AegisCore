"""`python -m outbox_relay` entrypoint."""

from __future__ import annotations

import sys

from outbox_relay.cli import main


if __name__ == "__main__":
    sys.exit(main())
