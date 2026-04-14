"""Minimal ESI HTTP client for the market poller.

Intentionally thin. The "proper" Python-plane ESI client with full
per-group bucket tracking, distributed refresh locking, JWT signature
verification, etc. is tracked under ADR-0002 § phase-2 #12 and lands
when we have enough callers to justify it (this is the first). Until
then we inline exactly the ESI surface the poller needs:

  - GET /markets/{region_id}/orders/ with pagination.

Rate-limit posture is reactive and conservative:

  - We read `X-Ratelimit-Remaining` / `X-ESI-Error-Limit-Remain` on
    every response. If either drops at or below its configured safety
    margin, we sleep until the matching `Reset` header expires before
    dispatching the next request.
  - On 429 / 420 we honour `Retry-After` and raise a transient failure
    so the runner can record it against the watched-locations row. We
    do NOT retry inside one call — the 5-minute cadence is the retry.
  - On 5xx we raise transient. On 4xx other than 429 we raise
    permanent (403 = no access, 404 = location gone, etc.).
  - `User-Agent` is always set per CCP's policy.

ETag / If-None-Match caching is deliberately omitted from this first
cut. The region-orders endpoint is cached 300s server-side, matching
our poll cadence; a 304 would save ~60% of the token cost but
complicates correctness (we'd have to remember "what was the response
body last time" somewhere durable, which adds a small cache store).
Layer it on when the first operator complaint about rate limits
lands, not before.
"""

from __future__ import annotations

import time
from contextlib import contextmanager
from dataclasses import dataclass
from typing import Iterator

import httpx

from market_poller.config import Config
from market_poller.log import get


log = get(__name__)


class EsiError(Exception):
    """Base class for ESI-surface errors."""


class PermanentEsiError(EsiError):
    """4xx other than 429. Auto-disable paths key off status_code."""

    def __init__(self, status_code: int, message: str) -> None:
        super().__init__(message)
        self.status_code = status_code


class TransientEsiError(EsiError):
    """5xx, 429, 420, network / timeout. The caller retries via
    the scheduler's next tick, never in-process."""

    def __init__(self, status_code: int | None, message: str, retry_after: float = 0.0) -> None:
        super().__init__(message)
        self.status_code = status_code
        self.retry_after = retry_after


@dataclass(frozen=True)
class RawOrder:
    """The subset of ESI order fields we persist. Covers both
    `/markets/{region_id}/orders/` and `/markets/structures/{id}/`.
    The structure endpoint omits `system_id` from its response — we
    tolerate that by defaulting to 0 at parse time; the column isn't
    persisted in `market_orders` so it's a harmless placeholder."""

    order_id: int
    type_id: int
    location_id: int
    system_id: int  # 0 for structure-endpoint orders (field not returned).
    is_buy_order: bool
    price: float
    volume_remain: int
    volume_total: int
    min_volume: int
    range: str
    duration: int
    issued: str  # ISO-8601 UTC string; parsed on persist


@contextmanager
def client(cfg: Config) -> Iterator["EsiClient"]:
    """Yield a configured EsiClient bound to one httpx.Client's
    connection pool. Scope is one poll pass."""
    with httpx.Client(
        base_url=cfg.esi_base_url,
        headers={
            "User-Agent": cfg.esi_user_agent,
            "Accept": "application/json",
        },
        timeout=cfg.esi_timeout_seconds,
        follow_redirects=True,
    ) as http:
        yield EsiClient(http, cfg)


class EsiClient:
    def __init__(self, http: httpx.Client, cfg: Config) -> None:
        self._http = http
        self._cfg = cfg

    def region_orders(self, region_id: int) -> Iterator[RawOrder]:
        """Stream every order across every page of the region. The
        caller does the location-ID filter — we stream everything so
        a future multi-hub-per-region poll doesn't re-fetch the same
        pages N times."""
        page = 1
        total_pages = 1  # Learned from X-Pages on page 1's response.
        while page <= total_pages:
            log.debug("fetching region orders page", region_id=region_id, page=page, total_pages=total_pages)
            resp = self._get(
                f"/markets/{region_id}/orders/",
                params={"order_type": "all", "page": page},
            )
            if page == 1:
                total_pages = int(resp.headers.get("x-pages", "1"))

            for entry in resp.json():
                yield RawOrder(
                    order_id=int(entry["order_id"]),
                    type_id=int(entry["type_id"]),
                    location_id=int(entry["location_id"]),
                    system_id=int(entry["system_id"]),
                    is_buy_order=bool(entry["is_buy_order"]),
                    price=float(entry["price"]),
                    volume_remain=int(entry["volume_remain"]),
                    volume_total=int(entry["volume_total"]),
                    min_volume=int(entry["min_volume"]),
                    range=str(entry["range"]),
                    duration=int(entry["duration"]),
                    issued=str(entry["issued"]),
                )

            self._respect_rate_limits(resp)
            page += 1

    def structure_orders(self, structure_id: int, access_token: str) -> Iterator[RawOrder]:
        """Stream orders from `/markets/structures/{id}/`. Requires a
        Bearer token scoped `esi-markets.structure_markets.v1` whose
        underlying character has docking access at the structure.

        The structure endpoint is location-specific (unlike the region
        endpoint + filter pattern), so every returned order already
        belongs to this structure. No client-side filter is needed;
        callers pass `filter_location_id=None` into `persist.insert_orders`.

        `system_id` is not in the structure endpoint's response — we
        default to 0 in RawOrder. The column isn't persisted into
        `market_orders` so it's a typed placeholder, not lost data.
        """
        page = 1
        total_pages = 1
        while page <= total_pages:
            log.debug(
                "fetching structure orders page",
                structure_id=structure_id,
                page=page,
                total_pages=total_pages,
            )
            resp = self._get(
                f"/markets/structures/{structure_id}/",
                params={"page": page},
                access_token=access_token,
            )
            if page == 1:
                total_pages = int(resp.headers.get("x-pages", "1"))

            for entry in resp.json():
                yield RawOrder(
                    order_id=int(entry["order_id"]),
                    type_id=int(entry["type_id"]),
                    location_id=int(entry["location_id"]),
                    system_id=0,
                    is_buy_order=bool(entry["is_buy_order"]),
                    price=float(entry["price"]),
                    volume_remain=int(entry["volume_remain"]),
                    volume_total=int(entry["volume_total"]),
                    min_volume=int(entry["min_volume"]),
                    range=str(entry["range"]),
                    duration=int(entry["duration"]),
                    issued=str(entry["issued"]),
                )

            self._respect_rate_limits(resp)
            page += 1

    # -- internals --------------------------------------------------------

    def _get(
        self,
        path: str,
        params: dict | None = None,
        access_token: str | None = None,
    ) -> httpx.Response:
        headers: dict[str, str] | None = None
        if access_token is not None:
            headers = {"Authorization": f"Bearer {access_token}"}
        try:
            resp = self._http.get(path, params=params, headers=headers)
        except httpx.TimeoutException as exc:
            raise TransientEsiError(None, f"timeout on GET {path}: {exc}") from exc
        except httpx.RequestError as exc:
            raise TransientEsiError(None, f"network error on GET {path}: {exc}") from exc

        status = resp.status_code
        if status == 200 or status == 304:
            return resp
        if status in (420, 429):
            retry_after = _parse_retry_after(resp)
            raise TransientEsiError(status, f"rate limited ({status}) on {path}", retry_after=retry_after)
        if 500 <= status < 600:
            raise TransientEsiError(status, f"upstream {status} on {path}: {resp.text[:200]}")
        if 400 <= status < 500:
            raise PermanentEsiError(status, f"{status} on {path}: {resp.text[:200]}")
        # 3xx other than 304 shouldn't land here — httpx follows redirects
        # by default. Treat as transient to avoid accidentally disabling
        # a location over an upstream routing quirk.
        raise TransientEsiError(status, f"unexpected status {status} on {path}")

    def _respect_rate_limits(self, resp: httpx.Response) -> None:
        """Reactive throttle: if the bucket or the global error budget
        has dropped to or below the configured margin, sleep until the
        matching window rolls. Not a distributed lock — parallel
        workers (we don't ship any yet, but forward-compat matters)
        can race past this. The safety margin absorbs small overshoots
        until a proper limiter lands."""
        remaining = _parse_int(resp.headers.get("x-ratelimit-remaining"))
        reset = _parse_int(resp.headers.get("x-ratelimit-reset"))
        if remaining is not None and remaining <= self._cfg.rate_limit_safety_margin and reset:
            log.warning(
                "bucket near limit, sleeping until reset",
                remaining=remaining,
                margin=self._cfg.rate_limit_safety_margin,
                reset_seconds=reset,
            )
            time.sleep(min(reset, 30))  # Hard cap on any single sleep.

        err_remain = _parse_int(resp.headers.get("x-esi-error-limit-remain"))
        err_reset = _parse_int(resp.headers.get("x-esi-error-limit-reset"))
        if err_remain is not None and err_remain <= self._cfg.error_limit_safety_margin and err_reset:
            log.warning(
                "error budget near limit, sleeping until reset",
                remaining=err_remain,
                margin=self._cfg.error_limit_safety_margin,
                reset_seconds=err_reset,
            )
            time.sleep(min(err_reset, 60))


def _parse_int(value: str | None) -> int | None:
    if value is None:
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _parse_retry_after(resp: httpx.Response) -> float:
    raw = resp.headers.get("retry-after", "0")
    try:
        return float(raw)
    except (TypeError, ValueError):
        # Retry-After can be an HTTP-date rather than seconds. We don't
        # parse that form — the scheduler's 5-minute cadence is the
        # guaranteed next-attempt window regardless.
        return 0.0
