"""Phase 2 — community-mismatch alliance baseline.

For each (declared_alliance, viewer_bloc) pair active in the latest
ci_character_anomalies_rolling window, compute the distribution of
community_hostile_pct across the alliance's members. Stored in
ci_alliance_community_baseline so the dossier render can compare a
pilot's value against their own alliance's median + p90 instead of
the absolute 60% threshold.

This addresses the calibration finding from the first run: every
hostile-alliance member has a high community_hostile_pct, so the
absolute threshold fires baseline-true. Normalizing isolates the
*outliers within their own alliance*, which is the real signal.

Run: counter_intel phase2-baseline --viewer-bloc-id N [--window-end YYYY-MM-DD]
"""

from __future__ import annotations

import statistics
from datetime import date, datetime, timezone

import pymysql

from counter_intel.config import Config
from counter_intel.log import get

log = get("counter_intel.phase2_baseline")

MIN_ALLIANCE_SAMPLE = 10  # only baseline alliances with >= N scored members


def run(
    conn: pymysql.connections.Connection,
    cfg: Config,
    viewer_bloc_id: int,
    window_end: date | None = None,
) -> dict:
    if window_end is None:
        window_end = datetime.now(timezone.utc).date()

    # Gather per-alliance community_hostile_pct samples. Resolve the
    # pilot's declared alliance via the most recent open corp history
    # row + corp_alliance history at that time. Cheap join — we already
    # have indices on character_corporation_history(character_id,
    # is_deleted, end_date).
    log.info(
        "phase2 baseline starting",
        {"viewer_bloc_id": viewer_bloc_id, "window_end": window_end.isoformat()},
    )
    with conn.cursor() as cur:
        cur.execute(
            """
            SELECT cah.alliance_id AS alliance_id,
                   a.community_hostile_pct AS pct
              FROM ci_character_anomalies_rolling a
              JOIN character_corporation_history cch
                ON cch.character_id = a.character_id
               AND cch.is_deleted = 0
               AND cch.end_date IS NULL
              JOIN corporation_alliance_history cah
                ON cah.corporation_id = cch.corporation_id
               AND cah.start_date <= cch.start_date
               AND (cah.end_date IS NULL OR cah.end_date >= cch.start_date)
             WHERE a.viewer_bloc_id = %s
               AND a.window_end_date = %s
               AND a.window_days = %s
               AND a.community_hostile_pct IS NOT NULL
               AND cah.alliance_id IS NOT NULL
            """,
            (viewer_bloc_id, window_end, cfg.window_days),
        )
        rows = cur.fetchall()

    by_alliance: dict[int, list[float]] = {}
    for r in rows:
        aid = int(r["alliance_id"])
        by_alliance.setdefault(aid, []).append(float(r["pct"]))

    log.info("samples loaded", {"alliances": len(by_alliance), "samples": len(rows)})

    written = 0
    for aid, samples in by_alliance.items():
        n = len(samples)
        if n < MIN_ALLIANCE_SAMPLE:
            continue
        samples_sorted = sorted(samples)
        median = float(statistics.median(samples_sorted))
        # p90: simple percentile
        idx = max(0, int(round(0.9 * (n - 1))))
        p90 = samples_sorted[idx]
        mean = float(statistics.fmean(samples_sorted))
        stdev = float(statistics.pstdev(samples_sorted)) if n >= 2 else 0.0

        with conn.cursor() as cur:
            cur.execute(
                """
                INSERT INTO ci_alliance_community_baseline
                    (alliance_id, viewer_bloc_id, window_end_date, window_days,
                     sample_size, median_pct, p90_pct, mean_pct, stdev_pct, computed_at)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
                ON DUPLICATE KEY UPDATE
                    window_days = VALUES(window_days),
                    sample_size = VALUES(sample_size),
                    median_pct = VALUES(median_pct),
                    p90_pct = VALUES(p90_pct),
                    mean_pct = VALUES(mean_pct),
                    stdev_pct = VALUES(stdev_pct),
                    computed_at = NOW()
                """,
                (
                    aid, viewer_bloc_id, window_end, cfg.window_days,
                    n, round(median, 4), round(p90, 4), round(mean, 4), round(stdev, 4),
                ),
            )
        written += 1

    conn.commit()
    log.info("phase2 baseline done", {"alliances_written": written})
    return {"alliances": len(by_alliance), "alliances_written": written, "samples": len(rows)}
