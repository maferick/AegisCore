"""CLI + orchestrator for a single (battle, alliance) run.

Usage:
    python -m battle_graph run --battle-id 101976 --alliance-id 1354830081
    python -m battle_graph run --battle-id 101976 --alliance-id 1354830081 \
        --edge-profile v1_seed --algo-profile v1_seed
    python -m battle_graph run ... --dry-run
"""

from __future__ import annotations

import argparse
import sys

from battle_graph.compute import cleanup_neo4j, project_gds, run_algorithms, write_graph
from battle_graph.config import Config
from battle_graph.db import maria, neo, neo_session
from battle_graph.edges import build_edges
from battle_graph.inputs import load_battle, load_pilots_for_side
from battle_graph.log import get
from battle_graph.persist import write_metrics, write_skip_rows
from battle_graph.profiles import load_algo_profile, load_edge_profile, tier_for
from battle_graph.runs import finalize_run, start_run


log = get(__name__)


def _parse() -> argparse.Namespace:
    p = argparse.ArgumentParser(prog="battle_graph")
    sub = p.add_subparsers(dest="cmd", required=True)

    r = sub.add_parser("run", help="Run a single (battle, alliance) projection + compute pass")
    r.add_argument("--battle-id", type=int, required=True)
    r.add_argument("--alliance-id", type=int, required=True)
    r.add_argument("--edge-profile-version", type=int, default=None)
    r.add_argument("--edge-profile", type=str, default=None, help="Label, resolved if --edge-profile-version is omitted")
    r.add_argument("--algo-profile-version", type=int, default=None)
    r.add_argument("--algo-profile", type=str, default=None)
    r.add_argument("--dry-run", action="store_true")

    return p.parse_args()


def run(args: argparse.Namespace) -> int:
    cfg = Config.from_env()

    with maria(cfg) as conn:
        edge = load_edge_profile(conn, args.edge_profile_version, args.edge_profile)
        algo = load_algo_profile(conn, args.algo_profile_version, args.algo_profile)

        log.info(
            "profiles resolved",
            edge_profile=edge.label, edge_profile_version=edge.edge_profile_version,
            algo_profile=algo.label, algo_profile_version=algo.algo_profile_version,
        )

        if args.dry_run:
            log.info("dry-run — no audit row, no writes", battle_id=args.battle_id, alliance_id=args.alliance_id)
            run_id = 0
            lock_key: str | None = None
        else:
            run_id, lock_key = start_run(
                conn,
                battle_id=args.battle_id,
                alliance_id=args.alliance_id,
                edge_profile_version=edge.edge_profile_version,
                algo_profile_version=algo.algo_profile_version,
            )

        try:
            battle = load_battle(conn, args.battle_id)
            if battle is None:
                raise RuntimeError(f"battle_id {args.battle_id} not found in battle_theaters")

            pilots = load_pilots_for_side(conn, battle, args.alliance_id)
            pilot_count = len(pilots)
            tier = tier_for(pilot_count, algo)

            log.info(
                "battle loaded",
                battle_id=battle.battle_id,
                alliance_id=args.alliance_id,
                pilots=pilot_count,
                killmails=len(battle.killmail_ids),
                tier=tier,
            )

            if tier == "small":
                if not args.dry_run:
                    write_skip_rows(
                        conn,
                        battle_id=args.battle_id,
                        alliance_id=args.alliance_id,
                        character_ids=sorted(pilots.keys()),
                        edge_profile_version=edge.edge_profile_version,
                        algo_profile_version=algo.algo_profile_version,
                        graph_tier=tier,
                        skip_reason="below_min_pilots",
                    )
                    finalize_run(
                        conn, run_id, "skipped",
                        lock_key=lock_key,
                        pilot_count=pilot_count, edge_count=0,
                        graph_tier=tier, algorithms_run=[],
                    )
                log.info("tier=small — skipped", pilot_count=pilot_count)
                return 0

            edges = build_edges(battle, pilots, edge)
            log.info("edges built", edge_count=len(edges))

            if args.dry_run:
                log.info("dry-run complete — no neo4j, no writes")
                return 0

            with neo(cfg) as driver, neo_session(driver, cfg) as session:
                try:
                    write_graph(
                        session,
                        run_id=run_id,
                        battle_id=args.battle_id,
                        alliance_id=args.alliance_id,
                        pilots=sorted(pilots.keys()),
                        edges=edges,
                    )
                    project_gds(session, run_id)
                    metrics = run_algorithms(session, run_id, algo, tier)

                    algs_run = ["weighted_degree"]
                    if algo.run_pagerank and (tier != "huge" or algo.run_pagerank):
                        algs_run.append("pagerank")
                    if algo.run_betweenness:
                        algs_run.append("betweenness")
                    if algo.run_clustering_coefficient:
                        algs_run.append("clustering_coefficient")
                    if algo.run_louvain and (tier != "huge" or algo.run_louvain):
                        algs_run.append("louvain")

                    write_metrics(
                        conn,
                        battle_id=args.battle_id,
                        alliance_id=args.alliance_id,
                        metrics=metrics,
                        edge_profile_version=edge.edge_profile_version,
                        algo_profile_version=algo.algo_profile_version,
                        graph_tier=tier,
                    )
                    finalize_run(
                        conn, run_id, "success",
                        lock_key=lock_key,
                        pilot_count=pilot_count, edge_count=len(edges),
                        graph_tier=tier, algorithms_run=algs_run,
                    )
                    log.info("run complete", run_id=run_id, algorithms=algs_run)
                finally:
                    cleanup_neo4j(session, run_id)

            return 0

        except Exception as exc:
            log.error("run failed", run_id=run_id, error=str(exc))
            if not args.dry_run:
                try:
                    # Best-effort Neo4j cleanup for partial state.
                    with neo(cfg) as driver, neo_session(driver, cfg) as session:
                        cleanup_neo4j(session, run_id)
                except Exception:
                    pass
                finalize_run(conn, run_id, "failed", lock_key=lock_key, error_message=str(exc))
            return 1


def main() -> int:
    args = _parse()
    if args.cmd == "run":
        return run(args)
    raise SystemExit(f"unknown command: {args.cmd}")


if __name__ == "__main__":
    sys.exit(main())
