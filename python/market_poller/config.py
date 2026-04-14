"""Runtime configuration for market_poller.

Mirrors the MariaDB env-var convention used by sde_importer +
graph_universe_sync (`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
`DB_PASSWORD`) and adds ESI-side knobs with sensible defaults.

No dotenv reads here — the compose file passes everything through.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field, replace


# Kinds of watched location this package knows how to poll. The runner
# filters the driver-table query by this set; anything else is logged
# and skipped. Phase 1 ships `npc_station` only — structure polling
# needs auth'd ESI + the eve_market_tokens table and lands in a later
# rollout step per ADR-0004.
SUPPORTED_LOCATION_TYPES: frozenset[str] = frozenset({"npc_station"})


@dataclass(frozen=True)
class Config:
    # MariaDB — canonical store for market_watched_locations (read) and
    # market_orders + outbox (write).
    db_host: str
    db_port: int
    db_database: str
    db_username: str
    db_password: str

    # ESI surface. `base_url` ends without a trailing slash; paths are
    # composed as `f"{base_url}{path}"` in esi.py.
    esi_base_url: str
    esi_user_agent: str
    esi_timeout_seconds: int

    # Rate-limit discipline. `safety_margin` is how many tokens we leave
    # on the table before we refuse to dispatch the next page — keeps
    # burst headroom for retries and other callers sharing the bucket.
    # `error_limit_safety_margin` is the analogous floor on CCP's global
    # error budget (X-ESI-Error-Limit-Remain). Tighter than the bucket
    # margin because tripping it hits every route with a 420, not just
    # the offending one.
    rate_limit_safety_margin: int
    error_limit_safety_margin: int

    # Failure thresholds (ADR-0004 § Failure handling). Routine failures
    # tick `consecutive_failure_count` on the watched-locations row;
    # crossing these thresholds auto-disables with `disabled_reason`
    # populated.
    max_consecutive_403s: int
    max_consecutive_5xx: int

    # Bulk-insert batch size for market_orders. Jita's order book is
    # ~150k rows across all types in The Forge; a 5 000-row batch keeps
    # each `executemany` under ~500KB of wire traffic and well under
    # the `max_allowed_packet` default of 16MB. Env-tunable for
    # operators running on smaller / bigger DBs.
    batch_size: int

    # Op modes.
    dry_run: bool                      # Log + fetch, skip the DB write.
    only_location_ids: frozenset[int]  # Empty = every enabled row; populated
                                       # from --only-location-id for ops
                                       # replays of one specific hub.

    @classmethod
    def from_env(cls, **overrides) -> "Config":
        def env(key: str, default: str | None = None, required: bool = False) -> str:
            v = os.environ.get(key, default)
            if required and not v:
                raise RuntimeError(f"Missing required env var: {key}")
            return v or ""

        cfg = cls(
            db_host=env("DB_HOST", required=True),
            db_port=int(env("DB_PORT", "3306")),
            db_database=env("DB_DATABASE", required=True),
            db_username=env("DB_USERNAME", required=True),
            db_password=env("DB_PASSWORD", required=True),
            esi_base_url=env("ESI_BASE_URL", "https://esi.evetech.net/latest"),
            esi_user_agent=env("ESI_USER_AGENT", "AegisCore/0.1 (+ops@example.com)"),
            esi_timeout_seconds=int(env("ESI_TIMEOUT_SECONDS", "15")),
            rate_limit_safety_margin=int(env("MARKET_POLL_RATE_LIMIT_SAFETY_MARGIN", "5")),
            error_limit_safety_margin=int(env("MARKET_POLL_ERROR_LIMIT_SAFETY_MARGIN", "10")),
            max_consecutive_403s=int(env("MARKET_POLL_MAX_CONSECUTIVE_403S", "3")),
            max_consecutive_5xx=int(env("MARKET_POLL_MAX_CONSECUTIVE_5XX", "5")),
            batch_size=int(env("MARKET_POLL_BATCH_SIZE", "5000")),
            dry_run=False,
            only_location_ids=frozenset(),
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg
