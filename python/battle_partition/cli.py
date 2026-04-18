"""CLI + orchestrator for Spec 3 partition runs.

Usage:
    python -m battle_partition run --battle-id 142837 --alliance-id 1354830081
    python -m battle_partition run ... --partition-algo-version 2
    python -m battle_partition run ... --edge-profile-version 1 --algo-profile-version 1
    python -m battle_partition run ... --dry-run
"""

from __future__ import annotations

import argparse
import sys

from battle_partition.config import Config
from battle_partition.db import (
    acquire_lock, graph_metrics_lock_key, maria,
    partition_lock_key, release_lock,
)
from battle_partition.inputs import load_graph_metrics, spec2_run_exists
from battle_partition.log import get
from battle_partition.partition import partition
from battle_partition.persist import write_partition
from battle_partition.profiles import load_partition_rule, resolve_graph_profile_combo


log = get(__name__)


def _parse() -> argparse.Namespace:
    p = argparse.ArgumentParser(prog="battle_partition")
    sub = p.add_subparsers(dest="cmd", required=True)

    r = sub.add_parser("run", help="Run partition + membership materialization for one (battle, alliance)")
    r.add_argument("--battle-id", type=int, required=True)
    r.add_argument("--alliance-id", type=int, required=True)
    r.add_argument("--partition-algo-version", type=int, default=None)
    r.add_argument("--partition-algo", type=str, default=None)
    r.add_argument("--edge-profile-version", type=int, default=None)
    r.add_argument("--algo-profile-version", type=int, default=None)
    r.add_argument("--dry-run", action="store_true")

    return p.parse_args()


def run(args: argparse.Namespace) -> int:
    cfg = Config.from_env()

    with maria(cfg) as conn:
        rule = load_partition_rule(
            conn, args.partition_algo_version, args.partition_algo,
        )
        edge_v, algo_v = resolve_graph_profile_combo(
            conn, args.battle_id, args.alliance_id,
            args.edge_profile_version, args.algo_profile_version,
        )

        log.info(
            "profiles resolved",
            partition_algo=rule.label, partition_algo_version=rule.partition_algo_version,
            min_community_size=rule.min_community_size,
            edge_profile_version=edge_v, algo_profile_version=algo_v,
        )

        if not spec2_run_exists(conn, args.battle_id, args.alliance_id, edge_v, algo_v):
            log.error("no Spec 2 run found; run battle_graph first",
                      battle_id=args.battle_id, alliance_id=args.alliance_id,
                      edge_profile_version=edge_v, algo_profile_version=algo_v)
            return 1

        # Two-lock protocol per Spec 3 § 11:
        #   1. graph-metrics lock (shared with Spec 2) — prevents
        #      Spec 2 from mutating our inputs mid-partition
        #   2. partition lock — prevents two Spec 3 runs on the same
        #      (battle, alliance, rule) tuple
        # Both are session-bound so MariaDB cleans up on connection
        # close if the worker crashes.
        gm_key = graph_metrics_lock_key(args.battle_id, args.alliance_id, edge_v, algo_v)
        p_key = partition_lock_key(args.battle_id, args.alliance_id, rule.partition_algo_version)
        acquire_lock(conn, gm_key, timeout_seconds=30)
        try:
            acquire_lock(conn, p_key, timeout_seconds=5)
            log.info("locks acquired", gm_key=gm_key, partition_key=p_key)

            try:
                metrics = load_graph_metrics(
                    conn, args.battle_id, args.alliance_id, edge_v, algo_v,
                )
                if not metrics:
                    log.error(
                        "no metrics rows found for profile combo",
                        battle_id=args.battle_id, alliance_id=args.alliance_id,
                        edge_profile_version=edge_v, algo_profile_version=algo_v,
                    )
                    return 1
                log.info("metrics loaded", pilots=len(metrics))

                result = partition(metrics, rule)
                log.info(
                    "partition computed",
                    sub_fleets=len(result.sub_fleets),
                    memberships=len(result.memberships),
                    promoted_communities=result.promoted_community_count,
                    orphan_communities=result.orphan_community_count,
                    orphan_pilots=result.orphan_pilot_count,
                )

                if args.dry_run:
                    log.info("dry-run complete — no writes")
                    return 0

                write_partition(
                    conn,
                    battle_id=args.battle_id,
                    alliance_id=args.alliance_id,
                    partition_algo_version=rule.partition_algo_version,
                    edge_profile_version=edge_v,
                    algo_profile_version=algo_v,
                    sub_fleets=result.sub_fleets,
                    memberships=result.memberships,
                )
                log.info("partition written", run_status="success")
                return 0
            finally:
                release_lock(conn, p_key)
        finally:
            release_lock(conn, gm_key)


def main() -> int:
    args = _parse()
    if args.cmd == "run":
        return run(args)
    raise SystemExit(f"unknown command: {args.cmd}")


if __name__ == "__main__":
    sys.exit(main())
