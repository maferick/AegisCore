"""Phase 2.5 — k-NN cohort feature extension.

Fills the new tz_centroid_sin/cos columns on
ci_character_features_rolling from the existing hour_histogram JSON.

Circular encoding: each hour h ∈ [0, 23] becomes the angle
(2π * h / 24). Weighted mean of (sin, cos) across the histogram bins
gives a centroid that respects circularity — 23:00 and 01:00 map
near each other instead of being maximally apart.

Idempotent. Skip rows that already have non-NULL tz_centroid_sin
unless --force.

Run: counter_intel phase2-cohort-features [--window-end YYYY-MM-DD] [--force]

ADR-0008 covers the broader cohort extension; this module is the
TZ-only piece of Phase 2.5. Doctrine match rate + pagerank/betweenness
z-normalisation are separate passes (see ADR for sequencing).
"""

from __future__ import annotations

import json
import math
from datetime import date, datetime, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase2_cohort_features")


def run(
    conn: pymysql.connections.Connection,
    cfg: Config,
    window_end: date | None = None,
    force: bool = False,
) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()

    log.info(
        "phase2 cohort-features starting",
        {"window_end": window_end.isoformat(), "force": force},
    )

    with conn.cursor() as cur:
        if force:
            cur.execute(
                "SELECT character_id, hour_histogram FROM ci_character_features_rolling "
                "WHERE window_end_date = %s AND window_days = %s",
                (window_end, cfg.window_days),
            )
        else:
            cur.execute(
                "SELECT character_id, hour_histogram FROM ci_character_features_rolling "
                "WHERE window_end_date = %s AND window_days = %s "
                "AND tz_centroid_sin IS NULL",
                (window_end, cfg.window_days),
            )
        rows = cur.fetchall()

    log.info("rows to process", {"n": len(rows)})

    written = 0
    for r in rows:
        cid = int(r["character_id"])
        try:
            hist = json.loads(r["hour_histogram"]) if r["hour_histogram"] else None
        except (TypeError, ValueError):
            hist = None
        if not hist or not isinstance(hist, list) or len(hist) != 24:
            continue
        total = sum(hist)
        if total <= 0:
            continue
        # Circular mean.
        sin_sum = 0.0
        cos_sum = 0.0
        for h in range(24):
            w = float(hist[h]) / total
            angle = 2.0 * math.pi * h / 24.0
            sin_sum += w * math.sin(angle)
            cos_sum += w * math.cos(angle)

        with conn.cursor() as cur:
            cur.execute(
                "UPDATE ci_character_features_rolling "
                "SET tz_centroid_sin = %s, tz_centroid_cos = %s "
                "WHERE character_id = %s AND window_end_date = %s AND window_days = %s",
                (round(sin_sum, 5), round(cos_sum, 5), cid, window_end, cfg.window_days),
            )
        written += 1
        if written % 5000 == 0:
            conn.commit()
            log.info("phase2 cohort-features progress", {"written": written, "total": len(rows)})
    conn.commit()
    log.info("phase2 cohort-features done", {"written": written})
    return {"candidates": len(rows), "written": written}
