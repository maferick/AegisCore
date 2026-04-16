"""R2Z2 (zKillboard) live killmail stream HTTP client.

API docs: https://github.com/zKillboard/zKillboard/wiki/API-(R2Z2)

Base URL: https://r2z2.zkillboard.com
- GET /ephemeral/sequence.json     → {"sequence": 96088891}
- GET /ephemeral/{seq}.json        → killmail JSON with ESI data + zkb block
- 404 = no more data, poll again after 6s minimum
- Rate limit: 20 req/s/IP
"""

from __future__ import annotations

import httpx

from killmail_ingest.config import Config
from killmail_ingest.log import get


log = get(__name__)


class R2z2Error(Exception):
    pass


class R2z2Transient(R2z2Error):
    """5xx / timeout / network — retry may succeed."""


def fetch_latest_sequence(cfg: Config) -> int:
    """Get the current head sequence ID from R2Z2."""
    url = f"{cfg.r2z2_base_url}/ephemeral/sequence.json"
    try:
        resp = httpx.get(
            url,
            headers={"User-Agent": cfg.user_agent},
            timeout=30,
        )
        resp.raise_for_status()
    except (httpx.HTTPStatusError, httpx.TimeoutException, httpx.ConnectError) as exc:
        raise R2z2Transient(f"sequence.json failed: {exc}") from exc

    data = resp.json()
    return int(data["sequence"])


def fetch_killmail(cfg: Config, sequence_id: int) -> dict | None:
    """Fetch a single killmail by sequence ID.

    Returns the parsed JSON dict on success, or None on 404 (no data
    at this sequence yet — caller should wait and retry).
    """
    url = f"{cfg.r2z2_base_url}/ephemeral/{sequence_id}.json"
    try:
        resp = httpx.get(
            url,
            headers={"User-Agent": cfg.user_agent},
            timeout=30,
        )
    except (httpx.TimeoutException, httpx.ConnectError) as exc:
        raise R2z2Transient(f"sequence {sequence_id} network error: {exc}") from exc

    if resp.status_code == 404:
        return None

    if resp.status_code == 429:
        raise R2z2Transient(f"rate limited at sequence {sequence_id}")

    if resp.status_code >= 500:
        raise R2z2Transient(f"HTTP {resp.status_code} at sequence {sequence_id}")

    resp.raise_for_status()
    return resp.json()


def extract_esi_killmail(r2z2_payload: dict) -> tuple[dict, str]:
    """Extract the ESI killmail body and hash from an R2Z2 response.

    R2Z2 wraps the ESI killmail in an envelope with a ``zkb`` block.
    Returns (esi_killmail_dict, killmail_hash).
    """
    zkb = r2z2_payload.get("zkb") or {}
    killmail_hash = zkb.get("hash", "")

    # The ESI killmail fields are at the top level alongside zkb.
    # Build a clean ESI-only dict by excluding R2Z2-specific keys.
    esi_keys = {
        "killmail_id", "killmail_time", "solar_system_id",
        "victim", "attackers", "war_id",
    }
    esi_payload = {k: v for k, v in r2z2_payload.items() if k in esi_keys}

    return esi_payload, killmail_hash
