"""EVE Ref killmail archive HTTP client.

Archives are daily tar.bz2 files at:
    https://data.everef.net/killmails/{YYYY}/killmails-{YYYY-MM-DD}.tar.bz2

Each archive contains one JSON file per killmail — a verbatim ESI
/killmails/{id}/{hash}/ response.

totals.json at https://data.everef.net/killmails/totals.json maps
YYYYMMDD → killmail count per day.
"""

from __future__ import annotations

import io
import json
import tarfile
from datetime import date

import httpx

from killmail_ingest.config import Config
from killmail_ingest.log import get


log = get(__name__)


class EverefError(Exception):
    pass


class EverefMissing(EverefError):
    """404 — archive does not exist for this date."""


class EverefTransient(EverefError):
    """5xx / timeout / network — retry may succeed."""


def fetch_totals(cfg: Config) -> dict[date, int]:
    """Fetch the totals.json manifest.

    Returns a dict mapping dates to killmail counts. Keys in the JSON
    are YYYYMMDD strings; we parse them to date objects.
    """
    try:
        resp = httpx.get(
            cfg.everef_totals_url,
            headers={"User-Agent": cfg.user_agent},
            timeout=cfg.download_timeout_seconds,
        )
        resp.raise_for_status()
    except httpx.HTTPStatusError as exc:
        if exc.response.status_code == 404:
            raise EverefMissing("totals.json not found") from exc
        raise EverefTransient(f"totals.json HTTP {exc.response.status_code}") from exc
    except (httpx.TimeoutException, httpx.ConnectError) as exc:
        raise EverefTransient(f"totals.json network error: {exc}") from exc

    raw = resp.json()
    result = {}
    for key, count in raw.items():
        try:
            d = date(int(key[:4]), int(key[4:6]), int(key[6:8]))
            result[d] = int(count)
        except (ValueError, IndexError):
            log.warning("totals.json: skipping unparseable key", key=key)
    return result


def fetch_day_killmails(cfg: Config, day: date) -> list[dict]:
    """Download and extract one day's killmail archive.

    Returns a list of ESI-shaped killmail dicts. The killmail_hash is
    derived from the filename inside the archive (the JSON body from
    ESI doesn't always include it).
    """
    url = f"{cfg.everef_base_url}/{day.year}/killmails-{day.isoformat()}.tar.bz2"
    log.info("downloading", url=url)

    try:
        resp = httpx.get(
            url,
            headers={"User-Agent": cfg.user_agent},
            timeout=cfg.download_timeout_seconds,
            follow_redirects=True,
        )
        resp.raise_for_status()
    except httpx.HTTPStatusError as exc:
        if exc.response.status_code == 404:
            raise EverefMissing(f"no archive for {day}") from exc
        raise EverefTransient(f"HTTP {exc.response.status_code} for {day}") from exc
    except (httpx.TimeoutException, httpx.ConnectError) as exc:
        raise EverefTransient(f"network error for {day}: {exc}") from exc

    killmails = []
    buf = io.BytesIO(resp.content)

    with tarfile.open(fileobj=buf, mode="r:bz2") as tar:
        for member in tar:
            if not member.isfile():
                continue
            f = tar.extractfile(member)
            if f is None:
                continue

            try:
                data = json.loads(f.read())
            except (json.JSONDecodeError, UnicodeDecodeError) as exc:
                log.warning("skipping malformed entry", member=member.name, error=str(exc))
                continue

            # Extract killmail_hash from filename if not in payload.
            # Filenames are typically "{killmail_id}/{killmail_hash}.json"
            # or just "{killmail_id}.json".
            if "killmail_hash" not in data:
                parts = member.name.replace(".json", "").split("/")
                if len(parts) >= 2:
                    data["killmail_hash"] = parts[-1]

            killmails.append(data)

    log.info("extracted", day=day.isoformat(), killmails=len(killmails))
    return killmails
