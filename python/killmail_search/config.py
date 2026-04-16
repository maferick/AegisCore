"""Runtime configuration for killmail_search."""

from __future__ import annotations

import os
from dataclasses import dataclass, replace


@dataclass(frozen=True)
class Config:
    # MariaDB (source)
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # OpenSearch (sink)
    opensearch_url: str
    opensearch_username: str
    opensearch_password: str
    opensearch_index: str
    opensearch_verify_certs: bool

    # Tuning
    batch_size: int
    dry_run: bool

    @classmethod
    def from_env(cls, **overrides) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            v = os.environ.get(key)
            if not v:
                v = default
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        cfg = cls(
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306")),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            opensearch_url=env("OPENSEARCH_URL", "https://opensearch:9200"),
            opensearch_username=env("OPENSEARCH_USERNAME", "admin"),
            opensearch_password=env("OPENSEARCH_PASSWORD", required=True),
            opensearch_index=env("OPENSEARCH_KILLMAIL_INDEX", "killmails"),
            opensearch_verify_certs=env("OPENSEARCH_VERIFY_CERTS", "false").lower() in ("true", "1", "yes"),
            batch_size=int(env("KILLMAIL_SEARCH_BATCH_SIZE", "1000")),
            dry_run=False,
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg
