"""Load, refresh, and scope-gate the admin service-character token.

Consumes the `eve_service_tokens` singleton row that the Laravel
`/admin/eve-service-character` flow wrote (ADR-0002 § Token kinds).
Per ADR-0004 this is the token the market poller uses for
admin-selected player structures.

Refresh discipline:

  - The row is read under `SELECT ... FOR UPDATE` so a hypothetical
    second poller instance serialises on the lock rather than both
    refreshing in parallel (double-rotation would invalidate the
    stored refresh_token and lock us out until a manual re-auth).
    Phase 1 is single-scheduler so the lock is precautionary, but it
    costs nothing and satisfies ADR-0002 § phase-2 #12's
    "distributed refresh lock" follow-up without adding Redis or
    zookeeper.
  - Refresh triggers when `expires_at <= now + 60s` — same 60-second
    future-bias the Laravel donations poller uses
    (`EveDonationsToken::isAccessTokenFresh()`).
  - CCP rotates the refresh_token on every call. We persist the new
    one (re-encrypted with Laravel's envelope format) BEFORE using
    the new access_token for anything, so a crash between refresh
    and first use doesn't orphan the credential.

Scope discipline:

  - The caller tells us which scopes it needs (e.g.
    `esi-markets.structure_markets.v1`). If the stored token lacks
    any of them, we raise `ServiceTokenScopeMissing` — a
    security-boundary failure the runner maps to `enabled = false`
    with `disabled_reason = 'scope_missing'`, no grace counter. The
    operator has to re-authorise the service character with the
    right scope list before polling resumes.

Not-configured path:

  - If APP_KEY or EVE_SSO_CLIENT_ID / CLIENT_SECRET are empty, the
    stack isn't set up for Python-side refresh yet. We raise
    `ServiceTokenNotConfigured` which the runner logs + skips
    player_structure rows cleanly, without disabling them (the
    configuration is an operator knob, not a security event).
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import pymysql

from market_poller.config import Config
from market_poller.laravel_encrypter import LaravelEncrypter, LaravelEncrypterError
from market_poller.log import get
from market_poller.sso import (
    RefreshedToken,
    SsoError,
    SsoPermanentError,
    SsoTransientError,
    refresh_access_token,
)


log = get(__name__)


REQUIRED_STRUCTURE_SCOPE = "esi-markets.structure_markets.v1"


class ServiceTokenError(Exception):
    """Base for service-token load / refresh failures. Routine-ish —
    the runner logs + record_failure()s without disabling rows."""


class ServiceTokenNotConfigured(ServiceTokenError):
    """APP_KEY / SSO credentials not configured. Structure polling is
    deliberately not available on this stack. Log + skip, don't
    fail loudly — this is a legitimate deployment shape (e.g. a
    stack that only polls NPC hubs)."""


class ServiceTokenMissing(ServiceTokenError):
    """No eve_service_tokens row exists. The admin hasn't run
    /admin/eve-service-character yet. Routine; the log tells the
    operator what to do."""


class ServiceTokenScopeMissing(ServiceTokenError):
    """Token exists but lacks a required scope. Security-boundary
    failure — the runner disables affected rows immediately with
    `disabled_reason = 'scope_missing'`."""


@dataclass(frozen=True)
class ServiceToken:
    """The resolved, decrypted, refreshed-if-stale service token.

    `access_token` is safe to pass to ESI as a Bearer; `scopes` is the
    scope list from the JWT's `scp` claim (or from the stored row if
    no refresh was needed this pass).
    """
    id: int
    character_id: int
    character_name: str
    access_token: str
    refresh_token: str
    expires_at: datetime  # UTC-aware
    scopes: list[str]

    def has_scope(self, scope: str) -> bool:
        return scope in self.scopes


@dataclass(frozen=True)
class MarketToken:
    """The resolved, decrypted, refreshed-if-stale donor market token.

    Shape-identical to `ServiceToken` but sourced from
    `eve_market_tokens` (one row per donor character) and always
    carries a `user_id` — the binding the poller enforces on every
    fetch (ADR-0004 § Token ownership enforced at read/use).
    """
    id: int
    user_id: int
    character_id: int
    character_name: str
    access_token: str
    refresh_token: str
    expires_at: datetime
    scopes: list[str]

    def has_scope(self, scope: str) -> bool:
        return scope in self.scopes


def load_and_refresh_service_token(
    conn: pymysql.connections.Connection,
    cfg: Config,
    *,
    required_scopes: tuple[str, ...] = (REQUIRED_STRUCTURE_SCOPE,),
) -> ServiceToken:
    """Load the singleton service token, refresh if stale, return a
    usable `ServiceToken`. Commits its own transaction for the
    refresh path; leaves the connection in autocommit-off state the
    caller started it with."""
    if not cfg.app_key:
        raise ServiceTokenNotConfigured("APP_KEY is empty — Python plane can't decrypt service tokens")
    if not cfg.eve_sso_client_id or not cfg.eve_sso_client_secret:
        raise ServiceTokenNotConfigured("EVE_SSO_CLIENT_ID / CLIENT_SECRET not configured")

    try:
        encrypter = LaravelEncrypter.from_app_key(cfg.app_key)
    except LaravelEncrypterError as exc:
        raise ServiceTokenError(f"APP_KEY malformed: {exc}") from exc

    # SELECT FOR UPDATE — any parallel poller serialises here. Lock is
    # released on commit()/rollback() at function exit.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, character_id, character_name, scopes,
                   access_token, refresh_token, expires_at
              FROM eve_service_tokens
             ORDER BY updated_at DESC
             LIMIT 1
             FOR UPDATE
            """
        )
        row = cur.fetchone()
    if row is None:
        conn.rollback()
        raise ServiceTokenMissing("no eve_service_tokens row — run /admin/eve-service-character")

    try:
        access_token = encrypter.decrypt(row["access_token"])
        refresh_token = encrypter.decrypt(row["refresh_token"])
    except LaravelEncrypterError as exc:
        conn.rollback()
        raise ServiceTokenError(f"decrypt failed: {exc}") from exc

    expires_at = _as_utc(row["expires_at"])
    scopes = _load_scopes(row["scopes"])

    refresh_due = expires_at <= datetime.now(timezone.utc) + timedelta(seconds=60)
    if not refresh_due:
        # No refresh needed — release the row lock. Everything below the
        # commit is read-only, so rollback() is equivalent to commit()
        # for our purposes and avoids advertising any modification.
        conn.rollback()
        _require_scopes(scopes, required_scopes, character_id=int(row["character_id"]))
        return ServiceToken(
            id=int(row["id"]),
            character_id=int(row["character_id"]),
            character_name=row["character_name"],
            access_token=access_token,
            refresh_token=refresh_token,
            expires_at=expires_at,
            scopes=scopes,
        )

    # Refresh path — commit the lock-holding transaction only after the
    # UPDATE persists the new refresh_token. If CCP's refresh returns
    # but we crash before persisting, the next tick will try the
    # already-rotated (now-invalid) old refresh_token and 401, which
    # ServiceTokenPermanent surfaces to the operator.
    log.info(
        "service token stale, refreshing",
        character_id=int(row["character_id"]),
        expires_at=expires_at.isoformat(),
    )
    try:
        fresh = refresh_access_token(
            refresh_token,
            client_id=cfg.eve_sso_client_id,
            client_secret=cfg.eve_sso_client_secret,
            token_url=cfg.eve_sso_token_url,
        )
    except SsoPermanentError as exc:
        conn.rollback()
        raise ServiceTokenError(f"refresh permanently rejected: {exc}") from exc
    except SsoError as exc:
        conn.rollback()
        raise ServiceTokenError(f"refresh failed: {exc}") from exc

    _persist_refreshed(conn, encrypter, row_id=int(row["id"]), fresh=fresh)
    conn.commit()

    _require_scopes(fresh.scopes, required_scopes, character_id=fresh.character_id)
    return ServiceToken(
        id=int(row["id"]),
        character_id=fresh.character_id,
        character_name=fresh.character_name,
        access_token=fresh.access_token,
        refresh_token=fresh.refresh_token,
        expires_at=fresh.expires_at,
        scopes=fresh.scopes,
    )


def load_and_refresh_market_token(
    conn: pymysql.connections.Connection,
    cfg: Config,
    user_id: int,
    *,
    required_scopes: tuple[str, ...] = (REQUIRED_STRUCTURE_SCOPE,),
) -> MarketToken:
    """Load + refresh-if-stale the market token for `user_id`.

    Phase 5 assumption: one market token per donor (at most one
    authorised character per donor). A donor with multiple linked
    EVE characters who has authorised more than one is an edge case
    we don't fully support — we pick the most-recently-updated row
    and log the fact that others were ignored. Proper multi-alt
    support is a future migration that adds `owner_character_id` to
    `market_watched_locations`.

    Error surface mirrors the service-token path:
      - ServiceTokenNotConfigured — APP_KEY / SSO creds empty.
      - ServiceTokenMissing        — no row for this user_id.
      - ServiceTokenScopeMissing   — row exists but lacks the scope
                                     (security-boundary disable).
      - ServiceTokenError          — decrypt / refresh failed.
    """
    if not cfg.app_key:
        raise ServiceTokenNotConfigured(
            "APP_KEY is empty — Python plane can't decrypt market tokens"
        )
    if not cfg.eve_sso_client_id or not cfg.eve_sso_client_secret:
        raise ServiceTokenNotConfigured(
            "EVE_SSO_CLIENT_ID / CLIENT_SECRET not configured"
        )

    try:
        encrypter = LaravelEncrypter.from_app_key(cfg.app_key)
    except LaravelEncrypterError as exc:
        raise ServiceTokenError(f"APP_KEY malformed: {exc}") from exc

    # SELECT FOR UPDATE — parallel pollers serialise here. Two rows
    # for the same user_id would be the "donor authorised multiple
    # alts" edge case flagged above; we pick the freshest.
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT id, user_id, character_id, character_name, scopes,
                   access_token, refresh_token, expires_at
              FROM eve_market_tokens
             WHERE user_id = %s
             ORDER BY updated_at DESC
             LIMIT 1
             FOR UPDATE
            """,
            (int(user_id),),
        )
        row = cur.fetchone()
    if row is None:
        conn.rollback()
        raise ServiceTokenMissing(
            f"no eve_market_tokens row for user_id={user_id} — donor hasn't "
            "authorised market access yet"
        )

    try:
        access_token = encrypter.decrypt(row["access_token"])
        refresh_token = encrypter.decrypt(row["refresh_token"])
    except LaravelEncrypterError as exc:
        conn.rollback()
        raise ServiceTokenError(f"decrypt failed: {exc}") from exc

    expires_at = _as_utc(row["expires_at"])
    scopes = _load_scopes(row["scopes"])

    refresh_due = expires_at <= datetime.now(timezone.utc) + timedelta(seconds=60)
    if not refresh_due:
        conn.rollback()
        _require_scopes(scopes, required_scopes, character_id=int(row["character_id"]))
        return MarketToken(
            id=int(row["id"]),
            user_id=int(row["user_id"]),
            character_id=int(row["character_id"]),
            character_name=row["character_name"],
            access_token=access_token,
            refresh_token=refresh_token,
            expires_at=expires_at,
            scopes=scopes,
        )

    log.info(
        "market token stale, refreshing",
        user_id=int(row["user_id"]),
        character_id=int(row["character_id"]),
        expires_at=expires_at.isoformat(),
    )
    try:
        fresh = refresh_access_token(
            refresh_token,
            client_id=cfg.eve_sso_client_id,
            client_secret=cfg.eve_sso_client_secret,
            token_url=cfg.eve_sso_token_url,
        )
    except SsoPermanentError as exc:
        conn.rollback()
        raise ServiceTokenError(f"refresh permanently rejected: {exc}") from exc
    except SsoError as exc:
        conn.rollback()
        raise ServiceTokenError(f"refresh failed: {exc}") from exc

    _persist_market_refreshed(conn, encrypter, row_id=int(row["id"]), fresh=fresh)
    conn.commit()

    _require_scopes(fresh.scopes, required_scopes, character_id=fresh.character_id)
    return MarketToken(
        id=int(row["id"]),
        user_id=int(row["user_id"]),
        character_id=fresh.character_id,
        character_name=fresh.character_name,
        access_token=fresh.access_token,
        refresh_token=fresh.refresh_token,
        expires_at=fresh.expires_at,
        scopes=fresh.scopes,
    )


# -- internals ------------------------------------------------------------


def _persist_refreshed(
    conn: pymysql.connections.Connection,
    encrypter: LaravelEncrypter,
    *,
    row_id: int,
    fresh: RefreshedToken,
) -> None:
    """UPDATE eve_service_tokens with the freshly-rotated tokens. Run
    inside the SELECT FOR UPDATE transaction — caller commits."""
    enc_access = encrypter.encrypt(fresh.access_token)
    enc_refresh = encrypter.encrypt(fresh.refresh_token)
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE eve_service_tokens
               SET access_token   = %s,
                   refresh_token  = %s,
                   expires_at     = %s,
                   scopes         = %s,
                   character_name = %s,
                   updated_at     = %s
             WHERE id = %s
            """,
            (
                enc_access,
                enc_refresh,
                fresh.expires_at.astimezone(timezone.utc).replace(tzinfo=None),
                json.dumps(fresh.scopes),
                fresh.character_name,
                datetime.now(timezone.utc).replace(tzinfo=None),
                row_id,
            ),
        )


def _persist_market_refreshed(
    conn: pymysql.connections.Connection,
    encrypter: LaravelEncrypter,
    *,
    row_id: int,
    fresh: RefreshedToken,
) -> None:
    """UPDATE eve_market_tokens with freshly-rotated tokens. Twin of
    `_persist_refreshed` — kept separate because we want a SQL typo
    on one table name to be a compile-time rather than a cross-table
    corruption."""
    enc_access = encrypter.encrypt(fresh.access_token)
    enc_refresh = encrypter.encrypt(fresh.refresh_token)
    with conn.cursor() as cur:
        cur.execute(
            """
            UPDATE eve_market_tokens
               SET access_token   = %s,
                   refresh_token  = %s,
                   expires_at     = %s,
                   scopes         = %s,
                   character_name = %s,
                   updated_at     = %s
             WHERE id = %s
            """,
            (
                enc_access,
                enc_refresh,
                fresh.expires_at.astimezone(timezone.utc).replace(tzinfo=None),
                json.dumps(fresh.scopes),
                fresh.character_name,
                datetime.now(timezone.utc).replace(tzinfo=None),
                row_id,
            ),
        )


def _require_scopes(
    granted: list[str],
    required: tuple[str, ...],
    *,
    character_id: int,
) -> None:
    missing = [s for s in required if s not in granted]
    if missing:
        raise ServiceTokenScopeMissing(
            f"service token for character {character_id} missing scope(s): {missing} "
            f"(granted: {granted})"
        )


def _as_utc(value: datetime) -> datetime:
    """MariaDB `TIMESTAMP` columns come back naive under pymysql's
    default settings. Treat them as UTC (the Laravel stack writes UTC
    timestamps per config/app.php timezone)."""
    if value.tzinfo is None:
        return value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc)


def _load_scopes(raw: str | list | None) -> list[str]:
    """The `scopes` column is a JSON column. pymysql decodes JSON
    columns to dict/list automatically in most versions, but older
    MariaDB / driver combinations return the raw JSON string. Handle
    both."""
    if raw is None:
        return []
    if isinstance(raw, str):
        try:
            parsed = json.loads(raw)
        except json.JSONDecodeError:
            return []
    else:
        parsed = raw
    if not isinstance(parsed, list):
        return []
    return [str(s) for s in parsed]
