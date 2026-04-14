"""End-to-end orchestrator for one market-poll pass.

Steps:

  1. Open MariaDB connection (autocommit off; per-location transaction).
  2. Open httpx client (shared connection pool across all locations).
  3. SELECT enabled watched locations, optionally filtered by
     `--only-location-id`.
  4. For each location:
       a. Compute `observed_at = now(UTC)` at the start of the poll so
          every order in this pass shares one timestamp.
       b. Dispatch to the location-type-specific fetcher:
            - npc_station                     → region endpoint + filter, no auth
            - player_structure, admin-owned   → service token, structure endpoint
            - player_structure, donor-owned   → eve_market_tokens (deferred to
                                                the next rollout step; skipped
                                                here with a log line)
       c. Bulk-insert into market_orders + update watched-locations
          bookkeeping + emit outbox event, all in one transaction.
       d. On transient/permanent ESI failure: rollback the attempt,
          record the failure against the watched-locations row
          (possibly auto-disabling it), commit *only* the bookkeeping
          update, and move on.
       e. On scope-missing / decrypt / ownership failures: immediate
          disable via persist.disable_immediately — no grace counter.

A failure in one location never stops the loop — each has its own
try/except and its own transaction boundary.

Token loading is LAZY + CACHED. The service token (admin-owned
structures) and each donor's market token (donor-owned structures)
are loaded + refreshed on first need and cached for the rest of
the pass. Stacks without structure rows never touch either cache;
donors with multiple watched structures pay the refresh cost once.
"""

from __future__ import annotations

import time
from datetime import datetime, timezone

import pymysql

from market_poller.auth import (
    REQUIRED_STRUCTURE_SCOPE,
    MarketToken,
    ServiceToken,
    ServiceTokenError,
    ServiceTokenMissing,
    ServiceTokenNotConfigured,
    ServiceTokenScopeMissing,
    load_and_refresh_market_token,
    load_and_refresh_service_token,
)
from market_poller.config import Config, SUPPORTED_LOCATION_TYPES
from market_poller.db import connect, fetch_all
from market_poller.esi import (
    EsiClient,
    PermanentEsiError,
    TransientEsiError,
    client as esi_client,
)
from market_poller.log import get
from market_poller.outbox import emit_orders_snapshot_ingested
from market_poller.persist import (
    disable_immediately,
    insert_orders,
    record_failure,
    record_success,
)


log = get(__name__)


def run(cfg: Config) -> int:
    log.info(
        "market_poller starting",
        dry_run=cfg.dry_run,
        only_location_ids=",".join(str(i) for i in sorted(cfg.only_location_ids)) or "all",
        batch_size=cfg.batch_size,
    )

    polled = 0
    failed = 0
    skipped = 0

    with connect(cfg) as conn:
        with esi_client(cfg) as esi:
            locations = _load_enabled_locations(conn, cfg)
            log.info("locations to poll", count=len(locations))

            service_cache = _ServiceTokenCache(conn, cfg)
            market_cache = _MarketTokenCache(conn, cfg)

            for loc in locations:
                outcome = _poll_one(conn, esi, loc, cfg, service_cache, market_cache)
                if outcome == "polled":
                    polled += 1
                elif outcome == "failed":
                    failed += 1
                else:
                    skipped += 1

    log.info(
        "market_poller complete",
        polled=polled,
        failed=failed,
        skipped=skipped,
    )
    return 0 if failed == 0 else 1


class _ServiceTokenCache:
    """Load the admin service token lazily, cache result for the rest
    of the pass. If structure rows are disabled on this stack, we
    never touch the token and APP_KEY / SSO creds can be empty.

    Caches the ERROR too — one failed load shouldn't be retried for
    every subsequent structure row in the same pass. The next
    scheduler tick re-runs from scratch."""

    _SENTINEL_UNSET = object()

    def __init__(self, conn: pymysql.connections.Connection, cfg: Config) -> None:
        self._conn = conn
        self._cfg = cfg
        self._result: object | ServiceToken = self._SENTINEL_UNSET

    def get(self) -> ServiceToken:
        """Return the cached token or raise the cached error. First
        call triggers the load+refresh round-trip; subsequent calls
        are cheap."""
        if self._result is self._SENTINEL_UNSET:
            try:
                self._result = load_and_refresh_service_token(self._conn, self._cfg)
            except ServiceTokenError as exc:
                # Cache the exception so every structure-row poll in
                # this pass raises the same thing without a fresh
                # refresh attempt.
                self._result = exc
        if isinstance(self._result, ServiceTokenError):
            raise self._result
        assert isinstance(self._result, ServiceToken)
        return self._result


class _MarketTokenCache:
    """Donor market-token cache, keyed on user_id. Same
    lazy+memoised pattern as `_ServiceTokenCache`. A donor with N
    watched structures pays the load+refresh round-trip once per
    pass; the cache also remembers errors so repeated structure
    rows for a donor with a broken token don't re-attempt per-row.
    """

    _SENTINEL_UNSET = object()

    def __init__(self, conn: pymysql.connections.Connection, cfg: Config) -> None:
        self._conn = conn
        self._cfg = cfg
        self._by_user: dict[int, object | MarketToken] = {}

    def get(self, user_id: int) -> MarketToken:
        if user_id not in self._by_user:
            try:
                self._by_user[user_id] = load_and_refresh_market_token(
                    self._conn, self._cfg, user_id,
                )
            except ServiceTokenError as exc:
                self._by_user[user_id] = exc
        cached = self._by_user[user_id]
        if isinstance(cached, ServiceTokenError):
            raise cached
        assert isinstance(cached, MarketToken)
        return cached


# -- internals ------------------------------------------------------------


def _load_enabled_locations(
    conn: pymysql.connections.Connection,
    cfg: Config,
) -> list[dict]:
    """Pull the driver-table slice we'll iterate. Ordered by
    last_polled_at asc (nulls first) so freshly-seeded rows get
    picked up in the same pass they were added.

    Filters to the supported location_type set so the loop doesn't
    waste time on rows only the next rollout step can handle."""
    type_placeholders = ",".join(["%s"] * len(SUPPORTED_LOCATION_TYPES))
    sql = f"""
        SELECT id, location_type, region_id, location_id, name,
               owner_user_id, consecutive_failure_count
          FROM market_watched_locations
         WHERE enabled = 1
           AND location_type IN ({type_placeholders})
    """
    params: tuple = tuple(sorted(SUPPORTED_LOCATION_TYPES))
    if cfg.only_location_ids:
        id_placeholders = ",".join(["%s"] * len(cfg.only_location_ids))
        sql += f" AND location_id IN ({id_placeholders})"
        params = params + tuple(cfg.only_location_ids)
    sql += " ORDER BY last_polled_at IS NULL DESC, last_polled_at ASC"
    return fetch_all(conn, sql, params)


def _poll_one(
    conn: pymysql.connections.Connection,
    esi: EsiClient,
    loc: dict,
    cfg: Config,
    service_cache: _ServiceTokenCache,
    market_cache: _MarketTokenCache,
) -> str:
    """Poll one watched-location row. Returns 'polled' | 'failed' |
    'skipped' for the caller's summary counters."""
    location_type = loc["location_type"]
    if location_type not in SUPPORTED_LOCATION_TYPES:
        log.info(
            "location type not supported yet, skipping",
            watched_location_id=loc["id"],
            location_type=location_type,
            location_id=loc["location_id"],
        )
        return "skipped"

    # One timestamp for every order in this pass. Truncating microseconds
    # keeps the value readable in logs / payloads without losing the
    # precision we need (all rows share it exactly; sub-second resolution
    # only matters for ordering between snapshots, which are minutes
    # apart).
    observed_at = datetime.now(timezone.utc).replace(microsecond=0)
    started = time.monotonic()

    source = _source_string_for(loc)
    log.info(
        "polling location",
        watched_location_id=loc["id"],
        location_type=location_type,
        region_id=loc["region_id"],
        location_id=loc["location_id"],
        source=source,
    )

    # Resolve the order stream + filter semantics by location_type.
    # `orders_iter` is a lazy generator; we don't pay network cost
    # until insert_orders iterates it. That also means ESI errors
    # raised during iteration bubble out of the `insert_orders` call
    # below — matched on the same try/except block.
    try:
        if location_type == "npc_station":
            orders_iter = esi.region_orders(int(loc["region_id"]))
            filter_location_id: int | None = int(loc["location_id"])
        else:
            # player_structure. Two paths depending on ownership:
            #
            #   - owner_user_id IS NULL  → admin-managed platform
            #     default. Use the admin's eve_service_tokens row.
            #   - owner_user_id IS NOT NULL → donor-owned. Use the
            #     donor's eve_market_tokens row. ADR-0004 § Structure
            #     access is alliance/corp-gated: there is no
            #     technical path by which a shared admin token can
            #     poll arbitrary donor-selected structures.
            owner_user_id = loc.get("owner_user_id")
            access_token = _acquire_structure_token(
                conn, loc, cfg, service_cache, market_cache, observed_at,
            )
            if access_token is None:
                return "failed"
            orders_iter = esi.structure_orders(int(loc["location_id"]), access_token)
            filter_location_id = None  # Structure endpoint is already location-specific.
            # Silence the linter — owner_user_id is surfaced in failure
            # logs via the watched-location row itself; we read it
            # above only to pick the auth path.
            del owner_user_id

        result = insert_orders(
            conn,
            orders_iter,
            observed_at=observed_at,
            source=source,
            region_id=int(loc["region_id"]),
            filter_location_id=filter_location_id,
            batch_size=cfg.batch_size,
        )
    except TransientEsiError as exc:
        conn.rollback()
        _handle_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except PermanentEsiError as exc:
        conn.rollback()
        _handle_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except Exception as exc:
        # Defensive: never let an unexpected error abort the whole loop.
        # We treat unknown errors as transient (no auto-disable) so an
        # operator gets the log line and can investigate without a
        # permanently-disabled row to unwind.
        conn.rollback()
        log.exception("unexpected poll error", watched_location_id=loc["id"])
        _handle_failure(conn, loc, cfg, None, f"unexpected: {exc}", observed_at)
        return "failed"

    duration_ms = int((time.monotonic() - started) * 1000)

    if cfg.dry_run:
        conn.rollback()
        log.info(
            "dry-run — rolled back inserts",
            watched_location_id=loc["id"],
            rows_received=result.rows_received,
            rows_inserted=result.rows_inserted,
            duration_ms=duration_ms,
        )
        return "polled"

    # Commit path: bookkeeping + outbox + inserts, all together.
    record_success(conn, int(loc["id"]), observed_at)
    emit_orders_snapshot_ingested(
        conn,
        watched_location_id=int(loc["id"]),
        source=source,
        region_id=int(loc["region_id"]),
        location_id=int(loc["location_id"]),
        location_type=location_type,
        observed_at=observed_at,
        rows_received=result.rows_received,
        rows_inserted=result.rows_inserted,
        duration_ms=duration_ms,
    )
    conn.commit()
    return "polled"


def _acquire_structure_token(
    conn: pymysql.connections.Connection,
    loc: dict,
    cfg: Config,
    service_cache: _ServiceTokenCache,
    market_cache: _MarketTokenCache,
    now: datetime,
) -> str | None:
    """Return the access_token string to use for this structure poll,
    or None after recording failure on the row.

    Branches on `owner_user_id`:
      - NULL  → admin-managed. Use service-character token
                (`eve_service_tokens`).
      - int   → donor-owned. Use the donor's market token
                (`eve_market_tokens` rows with matching user_id).
                The caller's source-string already bakes in the
                structure_id; this function just returns the bearer.

    Failure classification is identical for both paths:
      - ServiceTokenNotConfigured / ServiceTokenMissing → routine
        skip (no counter tick, logged at info).
      - ServiceTokenScopeMissing → security-boundary disable
        immediately (no grace counter).
      - Any other ServiceTokenError → routine failure (decrypt /
        refresh flap), bucketed with 5xx.

    Returns just the access_token so the caller doesn't have to
    branch on dataclass type (ServiceToken vs MarketToken).
    """
    owner_user_id = loc.get("owner_user_id")
    is_donor_path = owner_user_id is not None

    try:
        if is_donor_path:
            token = market_cache.get(int(owner_user_id))
            # Trust-boundary invariant: the token's user_id MUST
            # match the row's owner_user_id. SELECT-side filter in
            # load_and_refresh_market_token already enforces this,
            # but a defensive assertion at the use-site is cheap and
            # catches any future refactor that loosens the SELECT.
            if int(token.user_id) != int(owner_user_id):
                disable_immediately(
                    conn, int(loc["id"]),
                    reason="ownership_mismatch",
                    message=(
                        f"market token user_id={token.user_id} does not match "
                        f"watched location owner_user_id={owner_user_id}"
                    ),
                    now=now,
                )
                conn.commit()
                log.warning(
                    "structure disabled — token ↔ owner mismatch (security violation)",
                    watched_location_id=loc["id"],
                    token_user_id=token.user_id,
                    owner_user_id=owner_user_id,
                )
                return None
            return token.access_token
        else:
            return service_cache.get().access_token

    except ServiceTokenScopeMissing as exc:
        disable_immediately(
            conn,
            int(loc["id"]),
            reason="scope_missing",
            message=f"token lacks {REQUIRED_STRUCTURE_SCOPE}: {exc}",
            now=now,
        )
        conn.commit()
        log.warning(
            "structure disabled — token scope missing",
            watched_location_id=loc["id"],
            required_scope=REQUIRED_STRUCTURE_SCOPE,
            owner_user_id=owner_user_id,
            error=str(exc),
        )
        return None
    except (ServiceTokenNotConfigured, ServiceTokenMissing) as exc:
        # Neither a security event nor an upstream flap — just "this
        # stack isn't set up yet for this structure's auth path".
        # Admin path: APP_KEY empty or admin hasn't authorised.
        # Donor path: donor hasn't authorised their character yet
        # (rows created via the picker land before the auth flow
        # technically could, though the picker UX prevents that).
        log.info(
            "structure skipped — token not available",
            watched_location_id=loc["id"],
            owner_user_id=owner_user_id,
            reason=type(exc).__name__,
            error=str(exc),
        )
        return None
    except ServiceTokenError as exc:
        _handle_failure(
            conn, loc, cfg,
            status_code=None,
            message=f"token_error: {exc}",
            now=now,
        )
        return None


def _handle_failure(
    conn: pymysql.connections.Connection,
    loc: dict,
    cfg: Config,
    status_code: int | None,
    message: str,
    now: datetime,
) -> None:
    """Record a failure on the watched-locations row in its own tiny
    transaction. Separated from the poll rollback above so the failure
    telemetry lands even if the poll transaction is doomed."""
    disabled = record_failure(
        conn,
        int(loc["id"]),
        cfg=cfg,
        status_code=status_code,
        message=message,
        now=now,
    )
    conn.commit()
    log.warning(
        "poll failed",
        watched_location_id=loc["id"],
        location_id=loc["location_id"],
        status_code=status_code,
        auto_disabled=disabled,
        error=message[:200],
    )


def _source_string_for(loc: dict) -> str:
    """Provenance string stamped onto every market_orders row.

    Convention:
      - NPC (region endpoint + location filter):
            esi_region_<region_id>_<location_id>
      - Structure (authed structure endpoint):
            esi_structure_<structure_id>

    The string is human-readable on purpose — it shows up in audit
    queries and grep-a-log-file contexts a lot more often than a
    bare ID."""
    if loc["location_type"] == "npc_station":
        return f"esi_region_{int(loc['region_id'])}_{int(loc['location_id'])}"
    return f"esi_structure_{int(loc['location_id'])}"
