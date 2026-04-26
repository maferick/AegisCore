"""counter_intel entry point.

Usage:
  python -m counter_intel features              # run feature extraction
  python -m counter_intel features --window-end 2026-04-18
"""

from __future__ import annotations

import argparse
from datetime import date

from counter_intel.config import Config
from counter_intel.db import connection, neo_driver
from counter_intel.features import extract_and_persist
from counter_intel.projection import project
from counter_intel.similarity import run as run_similarity
from counter_intel.anomalies import compute as compute_anomalies
from counter_intel.graph_features import compute as compute_graph_features
from counter_intel.phase1 import run_bloc_agnostic as phase1_agnostic, run_bloc_relative as phase1_relative
from counter_intel.phase2_triangulation import run as phase2_triangulation
from counter_intel.phase2_baseline import run as phase2_baseline
from counter_intel.phase2_cohort_features import run as phase2_cohort_features
from counter_intel.phase4 import (
    run_timelines as phase4_timelines,
    run_fleet_participation as phase4_fleet_participation,
    run_intel_reliability as phase4_intel_reliability,
    run_session_correlation as phase4_session_correlation,
)
from counter_intel.phase4_aggregation import (
    run_hostile_clusters as phase4_hostile_clusters,
    run_incidents as phase4_incidents,
    run_system_activity as phase4_system_activity,
    run_corridors as phase4_corridors,
    run_response_times as phase4_response_times,
    run_threat_surface as phase4_threat_surface,
)
from counter_intel.phase4_force_composition import (
    run_force_compositions as phase45_force_compositions,
    run_force_transitions as phase45_force_transitions,
)
from counter_intel.log import get

log = get("counter_intel.cli")


def main() -> int:
    parser = argparse.ArgumentParser(prog="counter_intel")
    sub = parser.add_subparsers(dest="cmd", required=True)

    f = sub.add_parser("features", help="Extract character features into MariaDB.")
    f.add_argument("--window-end", type=str, default=None,
                   help="YYYY-MM-DD window end date (default: today UTC)")

    p = sub.add_parser("projection", help="Project characters + CO_OCCURS edges into Neo4j.")
    p.add_argument("--window-end", type=str, default=None,
                   help="YYYY-MM-DD (default: today UTC)")

    s = sub.add_parser("similarity", help="Run GDS knn + pageRank + betweenness on the projected graph.")
    s.add_argument("--top-k", type=int, default=100)
    s.add_argument("--similarity-cutoff", type=float, default=0.60)

    a = sub.add_parser("anomalies", help="Compute anomaly scores per viewer bloc into MariaDB.")
    a.add_argument("--viewer-bloc-id", type=int, required=True,
                   help="Bloc id whose perspective hostility is resolved from")
    a.add_argument("--window-end", type=str, default=None)

    g = sub.add_parser("graph-features", help="Compute Step 2 graph features (community + seed-anchored similarity) per viewer bloc.")
    g.add_argument("--viewer-bloc-id", type=int, required=True)
    g.add_argument("--window-end", type=str, default=None)

    p1a = sub.add_parser("phase1-agnostic", help="Phase 1 bloc-agnostic CI signals (dormancy, corp tenure, loss profile, battle-only).")
    p1a.add_argument("--window-end", type=str, default=None)

    p1r = sub.add_parser("phase1-relative", help="Phase 1 bloc-relative CI signals (asymmetric pair, community hostile %).")
    p1r.add_argument("--viewer-bloc-id", type=int, required=True)
    p1r.add_argument("--window-end", type=str, default=None)

    p2t = sub.add_parser("phase2-triangulation", help="Phase 2 hostile micro-network triangulation (recurring 3+ cluster opposite target).")
    p2t.add_argument("--viewer-bloc-id", type=int, required=True)
    p2t.add_argument("--window-end", type=str, default=None)

    p2b = sub.add_parser("phase2-baseline", help="Phase 2 alliance community baseline (median/p90 community_hostile_pct per declared alliance).")
    p2b.add_argument("--viewer-bloc-id", type=int, required=True)
    p2b.add_argument("--window-end", type=str, default=None)

    p2c = sub.add_parser("phase2-cohort-features", help="Phase 2.5 k-NN cohort extension: tz_centroid_sin/cos from hour_histogram.")
    p2c.add_argument("--window-end", type=str, default=None)
    p2c.add_argument("--force", action="store_true", help="recompute rows even when already filled")

    p4t = sub.add_parser("phase4-timelines", help="Phase 4.1 — operational timeline events from log streams.")
    p4t.add_argument("--viewer-bloc-id", type=int, required=True)
    p4t.add_argument("--since-hours", type=int, default=168, help="how many hours back to scan (default 7d)")
    p4t.add_argument("--dry-run", action="store_true", help="compute + log rows but do not persist")

    p4f = sub.add_parser("phase4-fleet-participation", help="Phase 4.2 — per-character fleet presence windows.")
    p4f.add_argument("--viewer-bloc-id", type=int, required=True)
    p4f.add_argument("--since-hours", type=int, default=168)

    p4i = sub.add_parser("phase4-intel-reliability", help="Phase 4.3 — per-reporter intel reliability profiles.")
    p4i.add_argument("--viewer-bloc-id", type=int, required=True)
    p4i.add_argument("--window-end", type=str, default=None)
    p4i.add_argument("--window-days", type=int, default=30)

    p4s = sub.add_parser("phase4-session-correlation", help="Phase 4.4 — pairwise session correlation edges.")
    p4s.add_argument("--viewer-bloc-id", type=int, required=True)
    p4s.add_argument("--window-end", type=str, default=None)
    p4s.add_argument("--window-days", type=int, default=30)

    p4hc = sub.add_parser("phase4-hostile-clusters", help="Phase 4.3A — operational hostile-contact clusters.")
    p4hc.add_argument("--viewer-bloc-id", type=int, required=True)
    p4hc.add_argument("--since-hours", type=int, default=8760)

    p4i = sub.add_parser("phase4-incidents", help="Phase 4.3B/C/E — fuse clusters + timelines into incidents, link battles.")
    p4i.add_argument("--viewer-bloc-id", type=int, required=True)
    p4i.add_argument("--since-hours", type=int, default=8760)

    p4sa = sub.add_parser("phase4-system-activity", help="Phase 4.3D — per-system per-day operational activity heatmap.")
    p4sa.add_argument("--viewer-bloc-id", type=int, required=True)
    p4sa.add_argument("--since-hours", type=int, default=8760)

    p4cor = sub.add_parser("phase4-corridors", help="Phase 4.4C — recurring hostile travel-lane inference.")
    p4cor.add_argument("--viewer-bloc-id", type=int, required=True)
    p4cor.add_argument("--since-hours", type=int, default=8760)

    p4rt = sub.add_parser("phase4-response-times", help="Phase 4.4E — operational tempo medians per system.")
    p4rt.add_argument("--viewer-bloc-id", type=int, required=True)
    p4rt.add_argument("--window-end", type=str, default=None)
    p4rt.add_argument("--window-days", type=int, default=30)

    p4ts = sub.add_parser("phase4-threat-surface", help="Phase 4.4F — composite per-system threat score.")
    p4ts.add_argument("--viewer-bloc-id", type=int, required=True)
    p4ts.add_argument("--window-end", type=str, default=None)
    p4ts.add_argument("--window-days", type=int, default=30)

    p45fc = sub.add_parser("phase45-force-compositions", help="Phase 4.5A — per-cluster force composition + doctrine match.")
    p45fc.add_argument("--viewer-bloc-id", type=int, required=True)
    p45fc.add_argument("--since-hours", type=int, default=8760)

    p45ft = sub.add_parser("phase45-force-transitions", help="Phase 4.5C — sequential dscan deltas inside an incident.")
    p45ft.add_argument("--viewer-bloc-id", type=int, required=True)
    p45ft.add_argument("--since-hours", type=int, default=8760)

    args = parser.parse_args()
    if args.cmd == "features":
        return _run_features(args)
    if args.cmd == "projection":
        return _run_projection(args)
    if args.cmd == "similarity":
        return _run_similarity(args)
    if args.cmd == "anomalies":
        return _run_anomalies(args)
    if args.cmd == "graph-features":
        return _run_graph_features(args)
    if args.cmd == "phase1-agnostic":
        return _run_phase1_agnostic(args)
    if args.cmd == "phase1-relative":
        return _run_phase1_relative(args)
    if args.cmd == "phase2-triangulation":
        return _run_phase2_triangulation(args)
    if args.cmd == "phase2-baseline":
        return _run_phase2_baseline(args)
    if args.cmd == "phase2-cohort-features":
        return _run_phase2_cohort_features(args)
    if args.cmd == "phase4-timelines":
        return _run_phase4_timelines(args)
    if args.cmd == "phase4-fleet-participation":
        return _run_phase4_fleet_participation(args)
    if args.cmd == "phase4-intel-reliability":
        return _run_phase4_intel_reliability(args)
    if args.cmd == "phase4-session-correlation":
        return _run_phase4_session_correlation(args)
    if args.cmd == "phase4-hostile-clusters":
        return _run_phase4_hostile_clusters(args)
    if args.cmd == "phase4-incidents":
        return _run_phase4_incidents(args)
    if args.cmd == "phase4-system-activity":
        return _run_phase4_system_activity(args)
    if args.cmd == "phase4-corridors":
        return _run_phase4_corridors(args)
    if args.cmd == "phase4-response-times":
        return _run_phase4_response_times(args)
    if args.cmd == "phase4-threat-surface":
        return _run_phase4_threat_surface(args)
    if args.cmd == "phase45-force-compositions":
        return _run_phase45_force_compositions(args)
    if args.cmd == "phase45-force-transitions":
        return _run_phase45_force_transitions(args)
    parser.print_help()
    return 2


def _run_features(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn:
        stats = extract_and_persist(conn, cfg, window_end=window_end)
    log.info("features pass complete", stats)
    return 0


def _run_projection(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn, neo_driver(cfg) as driver:
        stats = project(conn, driver, cfg, window_end=window_end)
    log.info("projection pass complete", stats)
    return 0


def _run_similarity(args) -> int:
    cfg = Config.from_env()
    with neo_driver(cfg) as driver:
        stats = run_similarity(driver, cfg, top_k=args.top_k, sim_cutoff=args.similarity_cutoff)
    log.info("similarity pass complete", stats)
    return 0


def _run_anomalies(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn, neo_driver(cfg) as driver:
        stats = compute_anomalies(conn, driver, cfg, viewer_bloc_id=args.viewer_bloc_id, window_end=window_end)
    log.info("anomalies pass complete", stats)
    return 0


def _run_graph_features(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn, neo_driver(cfg) as driver:
        stats = compute_graph_features(conn, driver, cfg, viewer_bloc_id=args.viewer_bloc_id, window_end=window_end)
    log.info("graph features pass complete", stats)
    return 0


def _run_phase1_agnostic(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn:
        stats = phase1_agnostic(conn, cfg, window_end=window_end)
    log.info("phase1 agnostic complete", stats)
    return 0


def _run_phase1_relative(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn:
        stats = phase1_relative(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, window_end=window_end)
    log.info("phase1 relative complete", stats)
    return 0


def _run_phase2_triangulation(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn:
        stats = phase2_triangulation(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, window_end=window_end)
    log.info("phase2 triangulation complete", stats)
    return 0


def _run_phase2_baseline(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn:
        stats = phase2_baseline(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, window_end=window_end)
    log.info("phase2 baseline complete", stats)
    return 0


def _run_phase2_cohort_features(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    with connection(cfg) as conn:
        stats = phase2_cohort_features(conn, cfg, window_end=window_end, force=bool(getattr(args, "force", False)))
    log.info("phase2 cohort-features complete", stats)
    return 0


def _run_phase4_timelines(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase4_timelines(
            conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since,
            dry_run=bool(getattr(args, "dry_run", False)),
        )
    log.info("phase4 timelines complete", stats)
    return 0


def _run_phase4_fleet_participation(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase4_fleet_participation(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since)
    log.info("phase4 fleet-participation complete", stats)
    return 0


def _run_phase4_intel_reliability(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    if window_end is None:
        from datetime import timezone, datetime as _dt
        window_end = _dt.now(timezone.utc).date()
    with connection(cfg) as conn:
        stats = phase4_intel_reliability(conn, cfg, viewer_bloc_id=args.viewer_bloc_id,
                                         window_end=window_end, window_days=int(args.window_days))
    log.info("phase4 intel-reliability complete", stats)
    return 0


def _run_phase4_hostile_clusters(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase4_hostile_clusters(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since)
    log.info("phase4.3A hostile-clusters complete", stats)
    return 0


def _run_phase4_incidents(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase4_incidents(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since)
    log.info("phase4.3B incidents complete", stats)
    return 0


def _run_phase4_system_activity(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase4_system_activity(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since)
    log.info("phase4.3D system-activity complete", stats)
    return 0


def _run_phase4_corridors(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase4_corridors(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since)
    log.info("phase4.4C corridors complete", stats)
    return 0


def _run_phase4_response_times(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    if window_end is None:
        from datetime import timezone, datetime as _dt
        window_end = _dt.now(timezone.utc).date()
    with connection(cfg) as conn:
        stats = phase4_response_times(conn, cfg, viewer_bloc_id=args.viewer_bloc_id,
                                       window_end=window_end, window_days=int(args.window_days))
    log.info("phase4.4E response-times complete", stats)
    return 0


def _run_phase45_force_compositions(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase45_force_compositions(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since)
    log.info("phase4.5A force compositions complete", stats)
    return 0


def _run_phase45_force_transitions(args) -> int:
    from datetime import timezone, timedelta, datetime as _dt
    cfg = Config.from_env()
    since = _dt.now(timezone.utc) - timedelta(hours=int(args.since_hours))
    with connection(cfg) as conn:
        stats = phase45_force_transitions(conn, cfg, viewer_bloc_id=args.viewer_bloc_id, since_dt=since)
    log.info("phase4.5C force transitions complete", stats)
    return 0


def _run_phase4_threat_surface(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    if window_end is None:
        from datetime import timezone, datetime as _dt
        window_end = _dt.now(timezone.utc).date()
    with connection(cfg) as conn:
        stats = phase4_threat_surface(conn, cfg, viewer_bloc_id=args.viewer_bloc_id,
                                       window_end=window_end, window_days=int(args.window_days))
    log.info("phase4.4F threat-surface complete", stats)
    return 0


def _run_phase4_session_correlation(args) -> int:
    cfg = Config.from_env()
    window_end = date.fromisoformat(args.window_end) if args.window_end else None
    if window_end is None:
        from datetime import timezone, datetime as _dt
        window_end = _dt.now(timezone.utc).date()
    with connection(cfg) as conn:
        stats = phase4_session_correlation(conn, cfg, viewer_bloc_id=args.viewer_bloc_id,
                                           window_end=window_end, window_days=int(args.window_days))
    log.info("phase4 session-correlation complete", stats)
    return 0
