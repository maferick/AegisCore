"""Redis-backed plan → result cache.

The broker executes aggregations that are cheap-ish individually but add
up fast under a chat-loop workload: every keystroke through the Livewire
``wire:poll`` + retry path can repeat the same ``how many kills last 7d``
plan. Caching by plan-hash drops repeat questions to a single GETEX round
trip.

Design
------

* Key: ``intel_copilot:result:v1:<sha256 of canonicalised plan dict>``.
  The ``v1`` segment lets us invalidate the whole cache atomically by
  bumping the version when the executor output shape changes.
* Value: JSON of the wire dict the server emits (parser, plan, result).
  That is the exact shape the Laravel side consumes, so cache hits
  return without touching executors at all.
* TTL: per-intent map. ``count`` / ``top_n`` / ``trend`` live 60 s
  (useful while a user stares at their question); ``list`` 30 s (recent
  kills shift fast — don't want to serve a stale 4-minute-old list);
  ``lookup`` 24 h (names don't churn).
* Bypass: ``use_cache=False`` on the request body always computes fresh
  and rewrites the entry. That's the escape hatch for debugging and for
  the scheduled "freshen this answer" job we'll need once this is used
  inside a dashboard.

Degradation
-----------

If Redis is unreachable the cache layer logs once per call and returns
``None`` for lookups / silently swallows writes. The broker still works
— slower, but correct. We do not want a transient Redis hiccup to take
down the chat page.
"""

from __future__ import annotations

import hashlib
import json
from typing import Any, Protocol

from intel_copilot.log import get
from intel_copilot.plan import Intent, QueryPlan

log = get(__name__)


KEY_PREFIX = "intel_copilot:result:v1:"

# Per-intent TTL (seconds). Tuned by volatility of the underlying data.
# ``lookup`` hits esi_entity_names — names change by the hour at most,
# so a day of cache is fine. ``list`` is kills in the last N minutes,
# so 30 s keeps the staleness window narrow enough for a live-fight
# dashboard while still soaking burst reads.
_TTL_FOR_INTENT: dict[Intent, int] = {
    Intent.COUNT: 60,
    Intent.TOP_N: 60,
    Intent.TREND: 60,
    Intent.LIST: 30,
    Intent.LOOKUP: 86_400,
    Intent.COMPARE: 60,
}
DEFAULT_TTL = 60


class RedisLike(Protocol):
    """Subset of ``redis.Redis`` that the cache actually calls. Protocol
    keeps the SDK import out of the hot path and lets tests inject a
    plain dict-backed stub."""

    def get(self, key: str) -> bytes | str | None: ...

    def setex(self, key: str, ttl: int, value: bytes | str) -> Any: ...


class ResultCache:
    """Thin GETEX-style cache around a Redis client.

    Disabled when ``client`` is ``None`` — both ``get`` and ``put``
    become no-ops so the server can instantiate unconditionally even
    when Redis wasn't configured (dev, unit tests).
    """

    def __init__(self, client: RedisLike | None) -> None:
        self._client = client

    @property
    def enabled(self) -> bool:
        return self._client is not None

    def get(self, plan: QueryPlan) -> dict[str, Any] | None:
        if self._client is None:
            return None
        key = _key_for(plan)
        try:
            raw = self._client.get(key)
        except Exception as exc:  # noqa: BLE001 — degrade, don't crash
            log.warning("cache.get failed — degrading to direct exec", err=str(exc))
            return None
        if raw is None:
            return None
        try:
            decoded = json.loads(raw if isinstance(raw, (str, bytes, bytearray)) else str(raw))
        except json.JSONDecodeError:
            log.warning("cache.get stored non-json payload; evicting", key=key)
            return None
        return decoded if isinstance(decoded, dict) else None

    def put(self, plan: QueryPlan, payload: dict[str, Any], *, ttl: int | None = None) -> None:
        if self._client is None:
            return
        ttl = ttl if ttl is not None else _TTL_FOR_INTENT.get(plan.intent, DEFAULT_TTL)
        key = _key_for(plan)
        try:
            self._client.setex(key, ttl, json.dumps(payload, default=str))
        except Exception as exc:  # noqa: BLE001 — cache is best-effort
            log.warning("cache.put failed — ignoring", err=str(exc))


# ---------------------------------------------------------------------- #
# Key derivation
# ---------------------------------------------------------------------- #

def _key_for(plan: QueryPlan) -> str:
    """SHA256 of a canonically-ordered plan dict.

    Canonicalisation matters: two callers who submit the same logical
    plan with different dict key order would otherwise thrash the cache.
    ``sort_keys=True`` + ``separators`` without spaces gives a stable
    byte string that survives JSON round-trips through every client.
    """
    canonical = json.dumps(plan.to_dict(), sort_keys=True, separators=(",", ":"), default=str)
    digest = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    return f"{KEY_PREFIX}{digest}"


# ---------------------------------------------------------------------- #
# Production-path constructor — resolves env to a real Redis client or
# a null cache when the deployment hasn't opted in.
# ---------------------------------------------------------------------- #

def from_env() -> ResultCache:
    """Build a ``ResultCache`` from ``REDIS_*`` env vars.

    Returns a disabled cache (``enabled=False``) when ``REDIS_HOST`` is
    unset. The import of ``redis`` is local so non-cache code paths —
    unit tests, CLI dry-runs — never need the SDK on disk.
    """
    import os

    host = os.environ.get("REDIS_HOST") or None
    if not host:
        return ResultCache(None)

    try:
        import redis  # type: ignore[import-not-found]
    except ImportError:  # pragma: no cover — exercised in prod only
        log.warning("redis SDK not installed; result cache disabled")
        return ResultCache(None)

    client = redis.Redis(
        host=host,
        port=int(os.environ.get("REDIS_PORT", "6379")),
        password=os.environ.get("REDIS_PASSWORD") or None,
        db=int(os.environ.get("INTEL_COPILOT_REDIS_DB", "1")),
        # Strings in, strings out — avoids the ``b'{...}'`` dance on
        # every read.
        decode_responses=True,
        socket_timeout=2.0,
    )
    return ResultCache(client)
