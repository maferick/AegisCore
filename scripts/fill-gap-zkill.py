#!/usr/bin/env python3
"""One-shot: reconcile missing killmails for a specific system+window
by pulling from zkillboard's /api/systemID/.../ endpoint and ingesting
any killmail_id we don't already have.

Targeted use case: the 9-GBPD battle on 2026-04-17 where R2Z2 lag or
zkill-side queueing left ~970 kms missing from our DB even though our
stream was running. zkill's API returns the hash alongside the
killmail_id, so we can hit ESI directly for the full body.

Usage (from /opt/AegisCore):
    docker compose --env-file .env -f infra/docker-compose.yml \
        --profile tools run --rm --no-deps \
        -v "$PWD/scripts/fill-gap-zkill.py:/app/fill-gap-zkill.py:ro" \
        killmail_backfill python /app/fill-gap-zkill.py \
          --system-id 30000318 \
          --past-seconds 86400 \
          --from 2026-04-17T13:00 \
          --to 2026-04-17T18:00

Rate-limited per zkill rules: 1 req/sec to zkill, 20 req/sec to ESI.
"""

from __future__ import annotations

import argparse
import os
import sys
import time
from datetime import datetime, timezone

import httpx

# Reuse the stream's parse + persist so the rows land in the same
# shape as any other killmail (ON DUPLICATE KEY UPDATE means re-
# ingesting existing kms is a cheap no-op).
from killmail_ingest.config import Config
from killmail_ingest.db import open_connection
from killmail_ingest.parse import parse_esi_killmail
from killmail_ingest.persist import ingest_killmail


ZKILL_BASE = "https://zkillboard.com/api"
ESI_BASE = "https://esi.evetech.net/latest"
UA = "AegisCore gap-filler admin@aegiscore.local"


def fetch_zkill(system_id: int, past_seconds: int) -> list[dict]:
    """Fetch kills from zkill for a system within the last N seconds."""
    # past_seconds must be a multiple of 3600 per zkill rules
    past_seconds = (past_seconds // 3600) * 3600
    url = f"{ZKILL_BASE}/systemID/{system_id}/pastSeconds/{past_seconds}/"
    print(f"zkill: {url}", flush=True)
    resp = httpx.get(
        url,
        headers={"User-Agent": UA, "Accept-Encoding": "gzip"},
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()


def fetch_esi_killmail(km_id: int, km_hash: str) -> dict:
    url = f"{ESI_BASE}/killmails/{km_id}/{km_hash}/"
    resp = httpx.get(url, headers={"User-Agent": UA}, timeout=30)
    resp.raise_for_status()
    return resp.json()


def parse_iso(s: str) -> datetime:
    # accept naive "YYYY-MM-DDTHH:MM" as UTC
    dt = datetime.fromisoformat(s)
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--system-id", type=int, required=True)
    ap.add_argument("--past-seconds", type=int, default=86400)
    ap.add_argument("--from", dest="from_ts", required=True, help="ISO UTC, e.g. 2026-04-17T13:00")
    ap.add_argument("--to", dest="to_ts", required=True)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    start = parse_iso(args.from_ts)
    end = parse_iso(args.to_ts)

    cfg = Config.from_env()

    # Connect to MariaDB to check which kms we already have + ingest.
    conn = open_connection(cfg)

    zkill_kms = fetch_zkill(args.system_id, args.past_seconds)
    print(f"zkill returned {len(zkill_kms)} kms for system {args.system_id}", flush=True)

    # zkill entries look like:
    #   {"killmail_id": 134803179, "zkb": {"hash": "abc...", ...}}
    # No killed_at in this payload — we'll filter after ESI fetch.

    # Fast path: which ids do we already have?
    existing = set()
    with conn.cursor() as cur:
        ids = [int(k["killmail_id"]) for k in zkill_kms]
        if ids:
            # chunk to keep query size sane
            for i in range(0, len(ids), 1000):
                chunk = ids[i : i + 1000]
                cur.execute(
                    "SELECT killmail_id FROM killmails WHERE killmail_id IN ("
                    + ",".join(["%s"] * len(chunk))
                    + ")",
                    chunk,
                )
                for row in cur.fetchall():
                    existing.add(int(row["killmail_id"]))
    print(f"already have {len(existing)} / {len(zkill_kms)}; fetching {len(zkill_kms) - len(existing)} from ESI", flush=True)

    ingested = 0
    in_window = 0
    skipped_out_of_window = 0
    errors = 0

    for idx, k in enumerate(zkill_kms):
        km_id = int(k["killmail_id"])
        km_hash = k.get("zkb", {}).get("hash") or k.get("hash") or ""
        if km_id in existing:
            continue
        if not km_hash:
            errors += 1
            continue

        try:
            esi = fetch_esi_killmail(km_id, km_hash)
        except Exception as exc:
            print(f"esi fail km={km_id}: {exc}", flush=True)
            errors += 1
            time.sleep(0.2)
            continue

        killed_at = datetime.fromisoformat(esi["killmail_time"].replace("Z", "+00:00"))
        if killed_at < start or killed_at > end:
            skipped_out_of_window += 1
            # be gentle on ESI even on skips
            time.sleep(0.05)
            continue
        in_window += 1

        km = parse_esi_killmail(esi, killmail_hash=km_hash)
        if not args.dry_run:
            ingest_killmail(conn, km)
            conn.commit()
        ingested += 1

        if idx % 50 == 0:
            print(
                f"  progress idx={idx} ingested={ingested} in_window={in_window} skipped={skipped_out_of_window} errors={errors}",
                flush=True,
            )

        # Pace ESI at ~15 req/sec to stay well under 100 req/sec cap.
        time.sleep(0.07)

    print(
        f"done: ingested={ingested} in_window={in_window} skipped_out_of_window={skipped_out_of_window} errors={errors} already_had={len(existing)}",
        flush=True,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
