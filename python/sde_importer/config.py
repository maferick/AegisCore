"""Runtime configuration, sourced from environment variables.

Mirrors the `AEGISCORE_*` / `SDE_*` / `DB_*` conventions that the PHP side uses.
Nothing here reads from a YAML or dotenv — compose passes the env in directly.
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Config:
    # SDE source + pinning
    source_url: str
    version_file: Path          # bind-mounted infra/sde/version.txt (rw)
    work_dir: Path              # ephemeral: download + extract scratch
    download_timeout: int       # seconds for the full download

    # MariaDB connection
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # Batch sizing for bulk inserts. Tuned for ~1MB per INSERT statement
    # at the 50th-percentile row size. Too small = transaction overhead
    # dominates; too big = max_allowed_packet breach.
    batch_size: int

    # Ops toggles
    only_download: bool         # download + unzip, skip DB load (dry-run-ish)
    skip_download: bool         # reuse an existing extract dir (iterate on loaders)
    extract_dir_override: Path | None

    @classmethod
    def from_env(cls, **overrides) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            v = os.environ.get(key, default)
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        cfg = cls(
            source_url=env(
                "SDE_SOURCE_URL",
                "https://developers.eveonline.com/static-data/eve-online-static-data-latest-jsonl.zip",
            ),
            version_file=Path(env("SDE_VERSION_FILE", "/var/www/sde/version.txt")),
            work_dir=Path(env("SDE_WORK_DIR", "/tmp/sde")),
            download_timeout=int(env("SDE_DOWNLOAD_TIMEOUT", "600")),
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306")),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            batch_size=int(env("SDE_BATCH_SIZE", "2000")),
            only_download=False,
            skip_download=False,
            extract_dir_override=None,
        )
        # Apply CLI overrides (fields are frozen; use dataclasses.replace).
        if overrides:
            from dataclasses import replace
            cfg = replace(cfg, **overrides)
        return cfg
