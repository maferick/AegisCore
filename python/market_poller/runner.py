"""End-to-end orchestrator for one market-poll pass.

Steps:

  1. Open MariaDB connection (autocommit off; per-location transaction).
  2. Open httpx client (shared connection pool across all locations).
  3. SELECT enabled watched locations joined to market_hubs, optionally
     filtered by `--only-location-id`. Hubs that are inactive or frozen
     (`disabled_reason IS NOT NULL`) are filtered out at SELECT time so
     a stuck hub doesn't burn a round-trip per tick.
  4. For each location:
       a. Compute `observed_at = now(UTC)` at the start of the poll so
          every order in this pass shares one timestamp.
       b. Dispatch by (location_type, is_public_reference):
            - npc_station                         → region endpoint +
                                                    location filter, no
                                                    auth.
            - player_structure, is_public = true  → admin-managed
                                                    platform default;
                                                    service token.
            - player_structure, is_public = false → donor-registered
                                                    private hub; walks
                                                    active collectors
                                                    in `market_hub_
                                                    collectors` with
                                                    failover.
       c. Bulk-insert into market_orders + bookkeeping updates + outbox
          event, all in one transaction per successful collector.
       d. On per-collector transient/permanent ESI failure: rollback,
          record the failure against that collector, try the next. Only
          when every active collector has been exhausted does the
          location count as failed for this pass.
       e. On scope-missing / decrypt / ownership failures: immediate
          disable of that collector (or, for the service-token path,
          the watched-locations row) — no grace counter.

A failure in one location never stops the loop — each has its own
try/except and its own transaction boundary.

Token loading is LAZY + CACHED. The service token (admin-managed
public-reference structures) and each donor collector's market token
(private hubs) are loaded + refreshed on first need and cached for
the rest of the pass. Market tokens are keyed by `eve_market_tokens.id`
(collector.token_id) rather than by user_id — a donor with multiple
collectors across different hubs still pays the refresh round-trip
once per token, and the cache is immune to the "multiple rows per
user_id" edge case the old user-keyed cache had to handle.
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
    load_and_refresh_market_token_by_id,
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
    disable_collector_immediately,
    disable_immediately,
    freeze_hub_no_collectors,
    insert_orders,
    record_collector_failure,
    record_collector_success,
    record_failure,
    record_hub_sync_success,
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
            _warn_on_unattached_watched_rows(conn)
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
    of the pass. If no admin-managed structure rows exist on this
    stack, we never touch the token and APP_KEY / SSO creds can be
    empty.

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
    """Donor market-token cache, keyed on `eve_market_tokens.id` (=
    `market_hub_collectors.token_id`). One refresh round-trip per
    unique token per pass, regardless of how many hubs the donor
    collects for. Errors are memoised so a broken token doesn't
    re-attempt on every collector row that points at it.
    """

    _SENTINEL_UNSET = object()

    def __init__(self, conn: pymysql.connections.Connection, cfg: Config) -> None:
        self._conn = conn
        self._cfg = cfg
        self._by_token: dict[int, object | MarketToken] = {}

    def get(self, token_id: int) -> MarketToken:
        if token_id not in self._by_token:
            try:
                self._by_token[token_id] = load_and_refresh_market_token_by_id(
                    self._conn, self._cfg, token_id,
                )
            except ServiceTokenError as exc:
                self._by_token[token_id] = exc
        cached = self._by_token[token_id]
        if isinstance(cached, ServiceTokenError):
            raise cached
        assert isinstance(cached, MarketToken)
        return cached


# -- internals ------------------------------------------------------------


def _warn_on_unattached_watched_rows(
    conn: pymysql.connections.Connection,
) -> None:
    """Loud signal for watched rows with hub_id IS NULL. After ADR-0005
    backfill, every row should point at a hub; any still-null row is
    invisible to this poller's JOIN'd SELECT and will silently stop
    being polled. That's the regression class we want to surface
    immediately rather than discover via "market prices aren't
    updating for donor X".

    Diagnostic only — no auto-repair. The fix is either (a) re-run
    the backfill migration once, or (b) recreate the row via
    `/account/settings` → picker (which now creates the hub trio
    alongside the watched row)."""
    with conn.cursor() as cur:
        cur.execute(
            "SELECT COUNT(*) AS n FROM market_watched_locations "
            "WHERE enabled = 1 AND hub_id IS NULL"
        )
        row = cur.fetchone()
    n = int(row["n"]) if row else 0
    if n > 0:
        log.warning(
            "watched rows have NULL hub_id — these will not be polled",
            count=n,
            hint=(
                "Re-run the ADR-0005 backfill or recreate rows via "
                "/account/settings to attach them to a canonical hub."
            ),
        )


def _load_enabled_locations(
    conn: pymysql.connections.Connection,
    cfg: Config,
) -> list[dict]:
    """Pull the driver-table slice we'll iterate. JOIN market_hubs so the
    downstream dispatch can branch on `is_public_reference` without a
    second round-trip per row; also filters out hubs that are inactive
    or frozen so a broken hub doesn't burn attempts per tick.

    Ordered by watched_locations.last_polled_at asc (nulls first) so
    freshly-seeded rows get picked up in the same pass they were added.
    """
    type_placeholders = ",".join(["%s"] * len(SUPPORTED_LOCATION_TYPES))
    sql = f"""
        SELECT mwl.id,
               mwl.location_type,
               mwl.region_id,
               mwl.location_id,
               mwl.name,
               mwl.hub_id,
               mwl.consecutive_failure_count,
               mh.is_public_reference,
               mh.is_active        AS hub_is_active,
               mh.disabled_reason  AS hub_disabled_reason
          FROM market_watched_locations mwl
          JOIN market_hubs mh ON mh.id = mwl.hub_id
         WHERE mwl.enabled = 1
           AND mwl.location_type IN ({type_placeholders})
           AND mh.is_active = 1
           AND mh.disabled_reason IS NULL
    """
    params: tuple = tuple(sorted(SUPPORTED_LOCATION_TYPES))
    if cfg.only_location_ids:
        id_placeholders = ",".join(["%s"] * len(cfg.only_location_ids))
        sql += f" AND mwl.location_id IN ({id_placeholders})"
        params = params + tuple(cfg.only_location_ids)
    sql += " ORDER BY mwl.last_polled_at IS NULL DESC, mwl.last_polled_at ASC"
    return fetch_all(conn, sql, params)


def _load_active_collectors(
    conn: pymysql.connections.Connection,
    hub_id: int,
) -> list[dict]:
    """Active collectors for a hub, primary first, then stalest-failure
    first so a backup that's been rested longest gets tried ahead of
    one that just failed on the previous tick. A null `last_failure_at`
    is treated as "never failed" and sorts ahead of any timestamped
    failure."""
    return fetch_all(
        conn,
        """
        SELECT id, hub_id, user_id, character_id, token_id,
               is_primary, is_active, consecutive_failure_count,
               last_failure_at, last_success_at
          FROM market_hub_collectors
         WHERE hub_id = %s
           AND is_active = 1
         ORDER BY is_primary DESC,
                  last_failure_at IS NULL DESC,
                  last_failure_at ASC,
                  id ASC
        """,
        (int(hub_id),),
    )


def _poll_one(
    conn: pymysql.connections.Connection,
    esi: EsiClient,
    loc: dict,
    cfg: Config,
    service_cache: _ServiceTokenCache,
    market_cache: _MarketTokenCache,
) -> str:
    """Poll one watched-location row. Returns 'polled' | 'failed' |
    'skipped' for the caller's summary counters. Dispatches by
    (location_type, is_public_reference) to the right path."""
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
    source = _source_string_for(loc)
    is_public = bool(loc.get("is_public_reference"))

    log.info(
        "polling location",
        watched_location_id=loc["id"],
        location_type=location_type,
        region_id=loc["region_id"],
        location_id=loc["location_id"],
        hub_id=loc.get("hub_id"),
        is_public_reference=is_public,
        source=source,
    )

    if location_type == "npc_station":
        return _poll_unauthed(conn, esi, loc, cfg, observed_at, source)

    # player_structure: two auth paths depending on the canonical hub's
    # classification. Public-reference (Jita-style NPC or admin-
    # registered platform default) uses the service token; private hub
    # walks its collectors with failover.
    if is_public:
        return _poll_service_structure(
            conn, esi, loc, cfg, service_cache, observed_at, source,
        )
    return _poll_private_structure(
        conn, esi, loc, cfg, market_cache, observed_at, source,
    )


def _poll_unauthed(
    conn: pymysql.connections.Connection,
    esi: EsiClient,
    loc: dict,
    cfg: Config,
    observed_at: datetime,
    source: str,
) -> str:
    """NPC station path: region endpoint + client-side location filter,
    no auth."""
    started = time.monotonic()
    try:
        orders_iter = esi.region_orders(int(loc["region_id"]))
        result = insert_orders(
            conn,
            orders_iter,
            observed_at=observed_at,
            source=source,
            region_id=int(loc["region_id"]),
            filter_location_id=int(loc["location_id"]),
            batch_size=cfg.batch_size,
        )
    except TransientEsiError as exc:
        conn.rollback()
        _handle_watched_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except PermanentEsiError as exc:
        conn.rollback()
        _handle_watched_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except Exception as exc:
        conn.rollback()
        log.exception("unexpected poll error", watched_location_id=loc["id"])
        _handle_watched_failure(conn, loc, cfg, None, f"unexpected: {exc}", observed_at)
        return "failed"

    duration_ms = int((time.monotonic() - started) * 1000)
    return _commit_success(
        conn, loc, cfg, observed_at, source, result, duration_ms,
        hub_character_id=None,  # NPC: no collector to denormalise.
    )


def _poll_service_structure(
    conn: pymysql.connections.Connection,
    esi: EsiClient,
    loc: dict,
    cfg: Config,
    service_cache: _ServiceTokenCache,
    observed_at: datetime,
    source: str,
) -> str:
    """Admin-managed public-reference structure: poll via the service
    character's token. Failure bookkeeping stays on
    `market_watched_locations` (no collectors for public hubs)."""
    started = time.monotonic()
    try:
        service_token = service_cache.get()
    except ServiceTokenScopeMissing as exc:
        disable_immediately(
            conn, int(loc["id"]),
            reason="scope_missing",
            message=f"service token lacks {REQUIRED_STRUCTURE_SCOPE}: {exc}",
            now=observed_at,
        )
        conn.commit()
        log.warning(
            "structure disabled — service token scope missing",
            watched_location_id=loc["id"],
            required_scope=REQUIRED_STRUCTURE_SCOPE,
            error=str(exc),
        )
        return "failed"
    except (ServiceTokenNotConfigured, ServiceTokenMissing) as exc:
        log.info(
            "structure skipped — service token not available",
            watched_location_id=loc["id"],
            reason=type(exc).__name__,
            error=str(exc),
        )
        return "skipped"
    except ServiceTokenError as exc:
        _handle_watched_failure(
            conn, loc, cfg, None, f"service_token_error: {exc}", observed_at,
        )
        return "failed"

    try:
        orders_iter = esi.structure_orders(
            int(loc["location_id"]), service_token.access_token,
        )
        result = insert_orders(
            conn,
            orders_iter,
            observed_at=observed_at,
            source=source,
            region_id=int(loc["region_id"]),
            filter_location_id=None,  # Structure endpoint is location-specific.
            batch_size=cfg.batch_size,
        )
    except TransientEsiError as exc:
        conn.rollback()
        _handle_watched_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except PermanentEsiError as exc:
        conn.rollback()
        _handle_watched_failure(conn, loc, cfg, exc.status_code, str(exc), observed_at)
        return "failed"
    except Exception as exc:
        conn.rollback()
        log.exception("unexpected poll error", watched_location_id=loc["id"])
        _handle_watched_failure(conn, loc, cfg, None, f"unexpected: {exc}", observed_at)
        return "failed"

    duration_ms = int((time.monotonic() - started) * 1000)
    return _commit_success(
        conn, loc, cfg, observed_at, source, result, duration_ms,
        hub_character_id=None,  # Service-token path: not a collector poll.
    )


def _poll_private_structure(
    conn: pymysql.connections.Connection,
    esi: EsiClient,
    loc: dict,
    cfg: Config,
    market_cache: _MarketTokenCache,
    observed_at: datetime,
    source: str,
) -> str:
    """Private hub path: walk active collectors, try each in turn, stop
    at the first success. Per-collector failure bookkeeping lives on
    `market_hub_collectors`; the watched-location row only tracks
    `last_polled_at` on success (for scheduler ordering). When all
    collectors have been exhausted and none remain active, the hub is
    frozen with `disabled_reason = 'no_active_collector'`."""
    hub_id = int(loc["hub_id"])
    started = time.monotonic()

    collectors = _load_active_collectors(conn, hub_id)
    if not collectors:
        # Private hub with zero active collectors — freeze it so the
        # next tick doesn't re-SELECT the row. Donor re-auth will
        # reactivate a collector and the success path clears the freeze.
        freeze_hub_no_collectors(conn, hub_id, observed_at)
        conn.commit()
        log.warning(
            "private hub has no active collectors; frozen",
            watched_location_id=loc["id"],
            hub_id=hub_id,
        )
        return "failed"

    last_error_msg: str | None = None
    last_status_code: int | None = None

    for collector in collectors:
        collector_id = int(collector["id"])
        token_id = int(collector["token_id"])
        collector_user_id = int(collector["user_id"])
        collector_char_id = int(collector["character_id"])

        # 1. Resolve the bearer for this collector.
        try:
            token = market_cache.get(token_id)
        except ServiceTokenScopeMissing as exc:
            disable_collector_immediately(
                conn, collector_id,
                reason="scope_missing",
                message=f"token lacks {REQUIRED_STRUCTURE_SCOPE}: {exc}",
                now=observed_at,
            )
            conn.commit()
            log.warning(
                "collector disabled — scope missing",
                watched_location_id=loc["id"],
                hub_id=hub_id,
                collector_id=collector_id,
                error=str(exc),
            )
            last_error_msg = str(exc)
            continue
        except (ServiceTokenNotConfigured, ServiceTokenMissing) as exc:
            # Token vanished between the collector SELECT and the
            # FOR UPDATE here, or APP_KEY is empty. Not a security
            # event — skip this collector, try the next.
            log.info(
                "collector skipped — token not available",
                watched_location_id=loc["id"],
                hub_id=hub_id,
                collector_id=collector_id,
                reason=type(exc).__name__,
                error=str(exc),
            )
            last_error_msg = str(exc)
            continue
        except ServiceTokenError as exc:
            record_collector_failure(
                conn, collector_id,
                cfg=cfg,
                status_code=None,
                message=f"token_error: {exc}",
                now=observed_at,
            )
            conn.commit()
            log.warning(
                "collector token load failed; trying next",
                watched_location_id=loc["id"],
                hub_id=hub_id,
                collector_id=collector_id,
                error=str(exc),
            )
            last_error_msg = str(exc)
            continue

        # 2. Trust-boundary invariant: collector's user_id MUST match
        # the token's user_id. `load_and_refresh_market_token_by_id`
        # doesn't enforce this (it keys by PK), so we enforce at use.
        if int(token.user_id) != collector_user_id:
            disable_collector_immediately(
                conn, collector_id,
                reason="ownership_mismatch",
                message=(
                    f"market token user_id={token.user_id} does not "
                    f"match collector user_id={collector_user_id}"
                ),
                now=observed_at,
            )
            conn.commit()
            log.warning(
                "collector disabled — token ↔ user mismatch (security violation)",
                watched_location_id=loc["id"],
                hub_id=hub_id,
                collector_id=collector_id,
                token_user_id=token.user_id,
                collector_user_id=collector_user_id,
            )
            continue

        # 3. Fetch + insert. Per-collector transaction boundary: this
        # collector's ESI errors only roll this collector's work back.
        try:
            orders_iter = esi.structure_orders(
                int(loc["location_id"]), token.access_token,
            )
            result = insert_orders(
                conn,
                orders_iter,
                observed_at=observed_at,
                source=source,
                region_id=int(loc["region_id"]),
                filter_location_id=None,
                batch_size=cfg.batch_size,
            )
        except TransientEsiError as exc:
            conn.rollback()
            record_collector_failure(
                conn, collector_id,
                cfg=cfg,
                status_code=exc.status_code,
                message=str(exc),
                now=observed_at,
            )
            conn.commit()
            last_error_msg = str(exc)
            last_status_code = exc.status_code
            log.info(
                "collector poll failed (transient); trying next",
                watched_location_id=loc["id"],
                hub_id=hub_id,
                collector_id=collector_id,
                status_code=exc.status_code,
            )
            continue
        except PermanentEsiError as exc:
            conn.rollback()
            record_collector_failure(
                conn, collector_id,
                cfg=cfg,
                status_code=exc.status_code,
                message=str(exc),
                now=observed_at,
            )
            conn.commit()
            last_error_msg = str(exc)
            last_status_code = exc.status_code
            log.info(
                "collector poll failed (permanent); trying next",
                watched_location_id=loc["id"],
                hub_id=hub_id,
                collector_id=collector_id,
                status_code=exc.status_code,
            )
            continue
        except Exception as exc:
            conn.rollback()
            log.exception(
                "unexpected collector poll error",
                watched_location_id=loc["id"],
                hub_id=hub_id,
                collector_id=collector_id,
            )
            record_collector_failure(
                conn, collector_id,
                cfg=cfg,
                status_code=None,
                message=f"unexpected: {exc}",
                now=observed_at,
            )
            conn.commit()
            last_error_msg = str(exc)
            continue

        # 4. Success — commit + update collector + hub bookkeeping.
        duration_ms = int((time.monotonic() - started) * 1000)
        record_collector_success(conn, collector_id, observed_at)
        return _commit_success(
            conn, loc, cfg, observed_at, source, result, duration_ms,
            hub_character_id=collector_char_id,
        )

    # Every active collector failed this pass. If the loop tripped any
    # auto-deactivate thresholds, the hub may now have zero active
    # collectors; check + freeze if so.
    if _count_active_collectors(conn, hub_id) == 0:
        freeze_hub_no_collectors(conn, hub_id, observed_at)
        conn.commit()
        log.warning(
            "private hub frozen — no active collectors remain after pass",
            watched_location_id=loc["id"],
            hub_id=hub_id,
            last_status_code=last_status_code,
            last_error=(last_error_msg or "")[:200],
        )
    else:
        log.warning(
            "private hub poll failed — all collectors failed this pass",
            watched_location_id=loc["id"],
            hub_id=hub_id,
            last_status_code=last_status_code,
            last_error=(last_error_msg or "")[:200],
        )
    return "failed"


def _count_active_collectors(
    conn: pymysql.connections.Connection,
    hub_id: int,
) -> int:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT COUNT(*) AS n FROM market_hub_collectors "
            "WHERE hub_id = %s AND is_active = 1",
            (int(hub_id),),
        )
        row = cur.fetchone()
    return int(row["n"]) if row else 0


def _commit_success(
    conn: pymysql.connections.Connection,
    loc: dict,
    cfg: Config,
    observed_at: datetime,
    source: str,
    result,
    duration_ms: int,
    *,
    hub_character_id: int | None,
) -> str:
    """Finalise a successful poll: either rollback (dry-run) or commit
    the inserts + watched-locations success + (for private hubs) hub
    sync-success + outbox event, atomically.

    `hub_character_id` is the collector's character id for private
    hubs; None for NPC + service-token paths (neither of which has
    a collector to denormalise onto the hub row).
    """
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

    record_success(conn, int(loc["id"]), observed_at)
    # Always bump hub-level sync state. For private hubs we also
    # denormalise the serving collector's character_id; for public
    # reference the character_id stays None and that column is
    # left alone. See `record_hub_sync_success` for the split.
    record_hub_sync_success(
        conn, int(loc["hub_id"]), hub_character_id, observed_at,
    )
    emit_orders_snapshot_ingested(
        conn,
        watched_location_id=int(loc["id"]),
        source=source,
        region_id=int(loc["region_id"]),
        location_id=int(loc["location_id"]),
        location_type=loc["location_type"],
        observed_at=observed_at,
        rows_received=result.rows_received,
        rows_inserted=result.rows_inserted,
        duration_ms=duration_ms,
    )
    conn.commit()
    return "polled"


def _handle_watched_failure(
    conn: pymysql.connections.Connection,
    loc: dict,
    cfg: Config,
    status_code: int | None,
    message: str,
    now: datetime,
) -> None:
    """Record a failure on the watched-locations row in its own tiny
    transaction. Separated from the poll rollback above so the failure
    telemetry lands even if the poll transaction is doomed.

    Only called on the NPC and service-token paths; private-hub
    failures land on the collector row via `record_collector_failure`
    and never touch the watched counter."""
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
