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
