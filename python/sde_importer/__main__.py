"""Entrypoint: `python -m sde_importer`."""

import sys

from sde_importer.cli import main

if __name__ == "__main__":
    sys.exit(main())
