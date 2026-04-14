"""HTTP fetch helpers for data.everef.net.

Two surfaces:

  - `fetch_totals()` — pull the flat `{YYYY-MM-DD: row_count}` manifest
    used for the completeness check. Cheap; we grab it once at the
    start of a run.
  - `fetch_day_csv_bytes()` — download one day's compressed CSV to
    memory. Each file is ~600 KB compressed, ~2-4 MB uncompressed;
    well under any sane memory ceiling for a one-shot importer.
    Streaming decompression would add complexity without payoff.

No auth. No rate limits published by EVE Ref, but we set a custom
`User-Agent` so their operator can identify AegisCore traffic if it
ever matters.
"""

from __future__ import annotations

import json
from datetime import date

import httpx

from market_importer.config import Config
from market_importer.log import get


log = get(__name__)


class EverefError(Exception):
    """Base class for EVE Ref fetch failures. The runner logs + skips
    the affected day; it doesn't abort the whole run."""


class EverefMissing(EverefError):
    """404 — file doesn't exist. Totally valid: today's file might not
    be uploaded yet, or totals.json includes a date whose CSV failed
    to publish."""


class EverefTransient(EverefError):
    """5xx, timeout, network error. Retry on the next run."""


def fetch_totals(cfg: Config) -> dict[date, int]:
    """GET totals.json and return it as `{date: row_count}`.

    Caller uses this to decide which days need a (re)download:
    missing locally, or local_count < published_count, or
    --force-redownload.
    """
    url = cfg.everef_totals_url
    log.info("fetching everef totals", url=url)
    try:
        resp = httpx.get(
            url,
            headers={"User-Agent": cfg.everef_user_agent},
            timeout=cfg.download_timeout_seconds,
            follow_redirects=True,
        )
    except (httpx.TimeoutException, httpx.RequestError) as exc:
        raise EverefTransient(f"fetch totals: {exc}") from exc

    if resp.status_code != 200:
        raise EverefTransient(f"fetch totals: HTTP {resp.status_code}")

    try:
        raw = json.loads(resp.text)
    except json.JSONDecodeError as exc:
        raise EverefTransient(f"fetch totals: invalid JSON ({exc})") from exc

    totals: dict[date, int] = {}
    for k, v in raw.items():
        try:
            totals[date.fromisoformat(k)] = int(v)
        except (ValueError, TypeError):
            log.warning("totals.json entry skipped", key=k, value=v)
    log.info("everef totals loaded", days=len(totals))
    return totals


def fetch_day_csv_bytes(cfg: Config, day: date) -> bytes:
    """Download one day's `.csv.bz2` file to memory. Returns the
    compressed bytes; decompression + CSV parsing happen in parse.py.

    Raises EverefMissing on 404, EverefTransient on 5xx / network /
    timeout. 4xx other than 404 is also EverefTransient — nothing we
    can meaningfully disable here.
    """
    url = f"{cfg.everef_base_url}/{day.year:04d}/market-history-{day.isoformat()}.csv.bz2"
    log.debug("fetching everef day", url=url)
    try:
        resp = httpx.get(
            url,
            headers={"User-Agent": cfg.everef_user_agent},
            timeout=cfg.download_timeout_seconds,
            follow_redirects=True,
        )
    except (httpx.TimeoutException, httpx.RequestError) as exc:
        raise EverefTransient(f"fetch {day}: {exc}") from exc

    status = resp.status_code
    if status == 200:
        return resp.content
    if status == 404:
        raise EverefMissing(f"no dump published for {day}")
    raise EverefTransient(f"fetch {day}: HTTP {status}")
