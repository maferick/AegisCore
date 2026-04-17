"""Runtime configuration for intel_copilot."""

from __future__ import annotations

import os
from dataclasses import dataclass, replace


@dataclass(frozen=True)
class Config:
    # OpenSearch (killmail analytics)
    opensearch_url: str
    opensearch_username: str
    opensearch_password: str
    opensearch_index: str
    opensearch_verify_certs: bool

    # MariaDB (canonical lookups)
    db_host: str | None
    db_port: int
    db_database: str | None
    db_username: str | None
    db_password: str | None

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
            opensearch_url=env("OPENSEARCH_URL", "http://opensearch:9200"),
            opensearch_username=env("OPENSEARCH_USERNAME", "admin"),
            opensearch_password=env("OPENSEARCH_PASSWORD", ""),
            opensearch_index=env("OPENSEARCH_KILLMAIL_INDEX", "killmails"),
            opensearch_verify_certs=env("OPENSEARCH_VERIFY_CERTS", "false").lower() in ("true", "1", "yes"),
            db_host=os.environ.get("DB_HOST"),
            db_port=int(env("DB_PORT", "3306")),
            db_database=os.environ.get("DB_DATABASE"),
            db_username=os.environ.get("DB_USERNAME"),
            db_password=os.environ.get("DB_PASSWORD"),
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg
