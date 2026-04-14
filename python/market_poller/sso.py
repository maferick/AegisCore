"""EVE SSO v2 refresh for stored service-character tokens.

Mirrors the Laravel-side `EveSsoClient::refreshAccessToken()` logic
(see `app/app/Services/Eve/Sso/EveSsoClient.php`). Two forms, same
spec:

    POST https://login.eveonline.com/v2/oauth/token
    Authorization: Basic base64(client_id:client_secret)
    Host: login.eveonline.com              (CCP best-practice header)
    Content-Type: application/x-www-form-urlencoded
    Accept: application/json

    grant_type=refresh_token&refresh_token=<the_stored_one>

On 200, the body has:

    {"access_token": "...", "token_type": "Bearer",
     "expires_in": 1199,
     "refresh_token": "..."}

The refresh_token is ROTATED on every call. Callers MUST persist
`refreshed.refresh_token` before making another call — the old one is
invalidated the moment this response lands.

JWT signature verification is intentionally skipped. TLS to CCP is
the trust boundary (same justification as ADR-0002 § JWT verification);
the JWKS + signature work is flagged in ADR-0002 § phase-2 #12 and
not on the critical path for this step.

Only the refresh path is here. Authorization code exchange lives on
the Laravel plane (it's user-session-bound; see ADR-0002).
"""

from __future__ import annotations

import base64
import json
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import httpx


TOKEN_URL_DEFAULT = "https://login.eveonline.com/v2/oauth/token"


class SsoError(Exception):
    """Base class for EVE SSO refresh failures."""


class SsoTransientError(SsoError):
    """5xx, network, timeout. Caller retries on the next scheduler
    tick — never in-process (stacking refreshes on a stale refresh
    token risks invalidating the stored credential)."""


class SsoPermanentError(SsoError):
    """400/401 — refresh token rejected by CCP. Either we have a stale
    refresh_token (previous poll tick rotated it and didn't persist
    the new one — a bug) or the user revoked the app on CCP's
    third-party-apps page. Either way, human intervention needed;
    do NOT auto-retry."""


class SsoMalformedResponseError(SsoError):
    """200 but the body is missing required fields or the JWT shape
    is wrong. Treated as transient — CCP sometimes returns partial
    bodies under load."""


@dataclass(frozen=True)
class RefreshedToken:
    """Result of a successful refresh. `scopes` is the JWT `scp` claim,
    normalized to a string list so callers can run scope predicates
    cheaply (`REQUIRED_SCOPE in refreshed.scopes`).

    Note: CCP's JWT `scp` claim is either a single string (one scope)
    or a list of strings (multiple scopes). We normalise both cases to
    list[str]."""
    access_token: str
    refresh_token: str
    expires_at: datetime  # UTC-aware
    character_id: int
    character_name: str
    scopes: list[str]


def refresh_access_token(
    refresh_token: str,
    *,
    client_id: str,
    client_secret: str,
    token_url: str = TOKEN_URL_DEFAULT,
    timeout_seconds: int = 10,
) -> RefreshedToken:
    """Trade a stored refresh_token for a fresh access_token +
    (rotated) refresh_token. Raises a subclass of SsoError on any
    failure; the caller surfaces the exception to the runner which
    decides retry / disable semantics per ADR-0004 § Failure handling.
    """
    if not client_id or not client_secret:
        raise SsoPermanentError("EVE_SSO_CLIENT_ID / CLIENT_SECRET not configured")

    basic = base64.b64encode(f"{client_id}:{client_secret}".encode("utf-8")).decode("ascii")

    try:
        resp = httpx.post(
            token_url,
            headers={
                "Authorization": f"Basic {basic}",
                # CCP-documented belt-and-braces against any upstream
                # Host-header-based caching weirdness; Laravel client
                # sends it too.
                "Host": "login.eveonline.com",
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json",
                "User-Agent": "AegisCore market_poller (SSO refresh)",
            },
            data={"grant_type": "refresh_token", "refresh_token": refresh_token},
            timeout=timeout_seconds,
        )
    except (httpx.TimeoutException, httpx.RequestError) as exc:
        raise SsoTransientError(f"refresh request: {exc}") from exc

    status = resp.status_code
    if status in (400, 401):
        raise SsoPermanentError(f"refresh rejected: HTTP {status}: {resp.text[:200]}")
    if 500 <= status < 600:
        raise SsoTransientError(f"sso 5xx: HTTP {status}: {resp.text[:200]}")
    if status != 200:
        raise SsoTransientError(f"sso unexpected status {status}: {resp.text[:200]}")

    try:
        body = resp.json()
    except ValueError as exc:
        raise SsoMalformedResponseError(f"response not JSON: {exc}") from exc

    access_token = body.get("access_token")
    new_refresh_token = body.get("refresh_token")
    expires_in = body.get("expires_in")
    if not access_token or not new_refresh_token or expires_in is None:
        raise SsoMalformedResponseError(
            f"response missing access_token / refresh_token / expires_in: keys={sorted(body)}"
        )

    try:
        expires_in = int(expires_in)
    except (TypeError, ValueError) as exc:
        raise SsoMalformedResponseError(f"expires_in not an int: {expires_in!r}") from exc

    character_id, character_name, scopes = _decode_jwt_identity(access_token)

    return RefreshedToken(
        access_token=access_token,
        refresh_token=new_refresh_token,
        expires_at=datetime.now(timezone.utc) + timedelta(seconds=expires_in),
        character_id=character_id,
        character_name=character_name,
        scopes=scopes,
    )


# -- JWT payload decode ---------------------------------------------------


def _decode_jwt_identity(jwt: str) -> tuple[int, str, list[str]]:
    """Unverified JWT payload decode — extracts (character_id, name, scopes).

    Same trust model as ADR-0002: we fetched this JWT via TLS from
    login.eveonline.com, so the TLS chain is the trust boundary.
    Verifying the signature would close essentially no additional gap
    and would require the full JWKS dance (cached, refreshed, etc.).
    Phase-2 work per ADR-0002 § phase-2 #12.
    """
    parts = jwt.split(".")
    if len(parts) != 3:
        raise SsoMalformedResponseError(
            f"JWT shape wrong: expected 3 dot-separated parts, got {len(parts)}"
        )
    try:
        # JWT uses URL-safe base64 without padding. Repad so Python's
        # b64decode is happy.
        payload_b64 = parts[1]
        padded = payload_b64 + "=" * (-len(payload_b64) % 4)
        payload = json.loads(base64.urlsafe_b64decode(padded).decode("utf-8"))
    except (ValueError, UnicodeDecodeError, base64.binascii.Error) as exc:
        raise SsoMalformedResponseError(f"JWT payload decode: {exc}") from exc

    # CCP's `sub` claim: "CHARACTER:EVE:<id>" or similar. Extract the
    # trailing int.
    sub = payload.get("sub", "")
    try:
        character_id = int(str(sub).rsplit(":", 1)[-1])
    except ValueError as exc:
        raise SsoMalformedResponseError(f"JWT sub not an int character ID: {sub!r}") from exc

    name = str(payload.get("name", ""))

    raw_scopes = payload.get("scp", [])
    if isinstance(raw_scopes, str):
        scopes = [s for s in raw_scopes.split(" ") if s]
    elif isinstance(raw_scopes, list):
        scopes = [str(s) for s in raw_scopes]
    else:
        scopes = []

    return character_id, name, scopes
