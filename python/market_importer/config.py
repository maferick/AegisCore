"""Runtime configuration for market_importer.

Env-var convention matches sde_importer / graph_universe_sync /
market_poller (`DB_*`) with EVE Ref source knobs layered on top.

The historical default `MARKET_IMPORT_MIN_DATE=2025-01-01` comes
directly from the user-facing ask in ADR-0004 ("from 2025 forward").
Operators can rewind further if they want the full EVE Ref history
(it goes back to 2003-05-10), at the cost of hundreds of MB of CSVs
and a much longer first run.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, replace
from datetime import date


@dataclass(frozen=True)
class Config:
    # MariaDB — destination for upserts into market_history + outbox
    # emission.
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # EVE Ref source. `base_url` is the daily-file prefix; paths under
    # it follow the `{YYYY}/market-history-{YYYY-MM-DD}.csv.bz2`
    # convention. `totals_url` is the flat `{date: rows}` manifest used
    # for the completeness check.
    everef_base_url: str
    everef_totals_url: str
    everef_user_agent: str
    download_timeout_seconds: int

    # Import window [min_date, max_date]. Inclusive on both ends.
    # max_date defaulted to yesterday UTC (today's file may still be
    # mid-scrape and won't have a published total yet).
    min_date: date
    max_date: date

    # Bulk-insert knob. EVE Ref's own importer recommends a high
    # INSERT_SIZE (they cite 100 000) and their schema's row width is
    # small. 5 000 matches sde_importer / market_poller defaults and
    # keeps each `executemany` roughly wire-budget-friendly; operators
    # on beefier DBs can crank this up via env.
    batch_size: int

    # Op modes.
    dry_run: bool                 # Parse + count, roll back per-day tx.
    only_dates: frozenset[date]   # Subset filter — empty = reconcile-driven.
    force_redownload: bool        # Ignore totals.json completeness check and
                                  # re-download every day in [min,max].

    @classmethod
    def from_env(cls, **overrides) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            # Treat unset AND empty-string as "not provided" — compose
            # passes empty strings into the container when an upstream
            # env is unset (e.g. `${FOO:-}` expansion), and we want
            # those to fall through to the documented default rather
            # than land as "" on _parse_date()/int()/etc. callers.
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
            everef_base_url=env("EVEREF_BASE_URL", "https://data.everef.net/market-history"),
            everef_totals_url=env(
                "EVEREF_TOTALS_URL",
                "https://data.everef.net/market-history/totals.json",
            ),
            everef_user_agent=env("ESI_USER_AGENT", "AegisCore/0.1 (+ops@example.com)"),
            download_timeout_seconds=int(env("MARKET_IMPORT_DOWNLOAD_TIMEOUT", "600")),
            min_date=_parse_date(env("MARKET_IMPORT_MIN_DATE", "2025-01-01")),
            max_date=_parse_date(env("MARKET_IMPORT_MAX_DATE", _yesterday(today).isoformat())),
            batch_size=int(env("MARKET_IMPORT_BATCH_SIZE", "5000")),
            dry_run=False,
            only_dates=frozenset(),
            force_redownload=False,
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg


def _parse_date(raw: str) -> date:
    """Accepts ISO-8601 date strings; raises RuntimeError on garbage so
    the operator sees the env var name in the traceback."""
    try:
        return date.fromisoformat(raw)
    except ValueError as exc:
        raise RuntimeError(f"invalid date value: {raw!r}") from exc


def _yesterday(today: date) -> date:
    from datetime import timedelta
    return today - timedelta(days=1)
