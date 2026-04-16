"""Runtime configuration for killmail_ingest.

Env-var convention matches market_importer / sde_importer (DB_*) with
killmail source knobs layered on top.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, replace
from datetime import date, timedelta


@dataclass(frozen=True)
class Config:
    # MariaDB
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # EVE Ref killmail source (backfill mode).
    everef_base_url: str        # https://data.everef.net/killmails
    everef_totals_url: str      # https://data.everef.net/killmails/totals.json
    user_agent: str
    download_timeout_seconds: int

    # R2Z2 source (stream mode).
    r2z2_base_url: str          # https://r2z2.zkillboard.com
    r2z2_poll_interval_seconds: int  # minimum 6

    # Backfill window [min_date, max_date] (inclusive).
    min_date: date
    max_date: date

    # Rolling recheck window — re-check recent days even if previously
    # imported, because EVE Ref files are mutable.
    recheck_window_days: int

    # Tuning.
    batch_size: int

    # Op modes.
    dry_run: bool
    only_dates: frozenset[date]

    @classmethod
    def from_env(cls, **overrides) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            v = os.environ.get(key)
            if not v:
                v = default
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        today = date.today()
        cfg = cls(
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306")),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            everef_base_url=env("EVEREF_KILLMAIL_BASE_URL", "https://data.everef.net/killmails"),
            everef_totals_url=env(
                "EVEREF_KILLMAIL_TOTALS_URL",
                "https://data.everef.net/killmails/totals.json",
            ),
            user_agent=env("ESI_USER_AGENT", "AegisCore/0.1 (+ops@example.com)"),
            download_timeout_seconds=int(env("KILLMAIL_IMPORT_DOWNLOAD_TIMEOUT", "600")),
            r2z2_base_url=env("R2Z2_BASE_URL", "https://r2z2.zkillboard.com"),
            r2z2_poll_interval_seconds=max(6, int(env("R2Z2_POLL_INTERVAL_SECONDS", "6"))),
            min_date=_parse_date(env("KILLMAIL_IMPORT_MIN_DATE", "2025-01-01")),
            max_date=_parse_date(env("KILLMAIL_IMPORT_MAX_DATE", (today - timedelta(days=1)).isoformat())),
            recheck_window_days=int(env("KILLMAIL_RECHECK_WINDOW_DAYS", "7")),
            batch_size=int(env("KILLMAIL_IMPORT_BATCH_SIZE", "5000")),
            dry_run=False,
            only_dates=frozenset(),
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg


def _parse_date(raw: str) -> date:
    try:
        return date.fromisoformat(raw)
    except ValueError as exc:
        raise RuntimeError(f"invalid date value: {raw!r}") from exc
