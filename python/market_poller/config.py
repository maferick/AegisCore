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
# and skipped.
#
#   - npc_station:     region endpoint + client-side location filter,
#                      no auth.
#   - player_structure: admin-owned (owner_user_id IS NULL) paths use
#                      the eve_service_tokens service character; donor-
#                      owned (owner_user_id = <user>) paths use
#                      eve_market_tokens and land in the next rollout
#                      step (ADR-0004 § donor self-service).
SUPPORTED_LOCATION_TYPES: frozenset[str] = frozenset({"npc_station", "player_structure"})


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

    # Authentication surface for admin-owned player_structure polling.
    # All three must be populated for structure polling to work:
    #
    #   - `app_key` is Laravel's APP_KEY — the same `base64:...` value
    #     that encrypted the stored service token. Required so the
    #     Python plane can decrypt the token and re-encrypt the
    #     rotated refresh_token back into the shared row.
    #   - `eve_sso_client_id` / `eve_sso_client_secret` are the same
    #     EVE_SSO_* app credentials the Laravel SSO flow uses;
    #     required for `POST /v2/oauth/token` (refresh_token grant).
    #
    # Empty values are tolerated: a stack that hasn't configured
    # structure polling yet simply skips player_structure rows with a
    # log line. See market_poller/auth.py § ServiceTokenNotConfigured.
    app_key: str
    eve_sso_client_id: str
    eve_sso_client_secret: str
    eve_sso_token_url: str

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
            app_key=env("APP_KEY", ""),
            eve_sso_client_id=env("EVE_SSO_CLIENT_ID", ""),
            eve_sso_client_secret=env("EVE_SSO_CLIENT_SECRET", ""),
            eve_sso_token_url=env(
                "EVE_SSO_TOKEN_URL",
                "https://login.eveonline.com/v2/oauth/token",
            ),
            dry_run=False,
            only_location_ids=frozenset(),
        )
        if overrides:
            cfg = replace(cfg, **overrides)
        return cfg
