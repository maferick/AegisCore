"""CLI + orchestrator for Spec 4 feature extraction runs.

Usage:
    python -m battle_features run --battle-id 40365 --alliance-id 99011978
    python -m battle_features run ... --partition-algo-version 1
    python -m battle_features run ... --edge-profile-version 1 --algo-profile-version 1
    python -m battle_features run ... --dry-run

Three-lock protocol:
  - graph-metrics lock   (shared with Spec 2 + Spec 3)
  - partition lock       (shared with Spec 3)
  - feature lock         (Spec-4 specific, same hash input as partition
                          lock but 'bf_' prefix)

All three are session-bound so MariaDB auto-releases on connection
close. Acquired in order; released in reverse order on every exit path.
"""

from __future__ import annotations

import argparse
import statistics
import sys

from battle_features.config import Config
from battle_features.db import (
    acquire_lock, feature_lock_key, graph_metrics_lock_key, maria,
    partition_lock_key, release_lock,
)
from battle_features.extract import extract
from battle_features.inputs import (
    load_graph_metrics, load_hull_category_map, load_memberships,
    load_sub_fleet_headers, load_theater_attacker_events,
    load_theater_victim_events, spec3_membership_exists,
)
from battle_features.log import get
from battle_features.persist import write_features
from battle_features.profiles import (
    resolve_graph_profile_combo, resolve_partition_algo_version,
)


log = get(__name__)


def _parse() -> argparse.Namespace:
    p = argparse.ArgumentParser(prog="battle_features")
    sub = p.add_subparsers(dest="cmd", required=True)

    r = sub.add_parser("run", help="Extract role features for one (battle, alliance)")
    r.add_argument("--battle-id", type=int, required=True)
    r.add_argument("--alliance-id", type=int, required=True)
    r.add_argument("--partition-algo-version", type=int, default=None)
    r.add_argument("--edge-profile-version", type=int, default=None)
    r.add_argument("--algo-profile-version", type=int, default=None)
    r.add_argument("--bucket-seconds", type=int, default=30)
    r.add_argument("--dry-run", action="store_true")

    return p.parse_args()


def _completeness_summary(values: list[float]) -> dict[str, float]:
    if not values:
        return {"n": 0, "mean": 0.0, "min": 0.0, "max": 0.0, "stdev": 0.0}
    return {
        "n": len(values),
        "mean": round(statistics.fmean(values), 4),
        "min": round(min(values), 4),
        "max": round(max(values), 4),
        "stdev": round(statistics.pstdev(values), 6) if len(values) > 1 else 0.0,
    }


def run(args: argparse.Namespace) -> int:
    cfg = Config.from_env()

    with maria(cfg) as conn:
        edge_v, algo_v = resolve_graph_profile_combo(
            conn, args.battle_id, args.alliance_id,
            args.edge_profile_version, args.algo_profile_version,
        )
        part_v = resolve_partition_algo_version(
            conn, args.battle_id, args.alliance_id,
            args.partition_algo_version, edge_v, algo_v,
        )

        log.info(
            "profiles resolved",
            battle_id=args.battle_id, alliance_id=args.alliance_id,
            partition_algo_version=part_v,
            edge_profile_version=edge_v,
            algo_profile_version=algo_v,
        )

        if not spec3_membership_exists(conn, args.battle_id, args.alliance_id, part_v):
            log.error(
                "no Spec 3 membership rows found; run battle_partition first",
                battle_id=args.battle_id, alliance_id=args.alliance_id,
                partition_algo_version=part_v,
            )
            return 1

        gm_key = graph_metrics_lock_key(args.battle_id, args.alliance_id, edge_v, algo_v)
        p_key = partition_lock_key(args.battle_id, args.alliance_id, part_v)
        f_key = feature_lock_key(args.battle_id, args.alliance_id, part_v)

        acquire_lock(conn, gm_key, timeout_seconds=30)
        try:
            acquire_lock(conn, p_key, timeout_seconds=30)
            try:
                acquire_lock(conn, f_key, timeout_seconds=5)
                log.info(
                    "locks acquired",
                    gm_key=gm_key, partition_key=p_key, feature_key=f_key,
                )

                try:
                    memberships = load_memberships(
                        conn, args.battle_id, args.alliance_id, part_v,
                    )
                    sub_fleet_headers = load_sub_fleet_headers(
                        conn, args.battle_id, args.alliance_id, part_v,
                    )
                    graph_metrics = load_graph_metrics(
                        conn, args.battle_id, args.alliance_id, edge_v, algo_v,
                    )
                    attacker_events = load_theater_attacker_events(conn, args.battle_id)
                    victim_events = load_theater_victim_events(conn, args.battle_id)
                    hull_map = load_hull_category_map(conn)

                    log.info(
                        "inputs loaded",
                        members=len(memberships),
                        sub_fleets=len(sub_fleet_headers),
                        graph_rows=len(graph_metrics),
                        attacker_events=len(attacker_events),
                        victim_events=len(victim_events),
                        hull_map_size=len(hull_map),
                    )

                    result = extract(
                        memberships=memberships,
                        sub_fleet_headers=sub_fleet_headers,
                        graph_metrics=graph_metrics,
                        attacker_events=attacker_events,
                        victim_events=victim_events,
                        hull_category_map=hull_map,
                    )

                    if result.unresolvable_ship_type_ids:
                        log.warning(
                            "pilots flew hulls outside the v1 category mapping; "
                            "categorized as 'other' — consider adding to ship_class_category_mapping",
                            unresolvable_ship_type_ids=result.unresolvable_ship_type_ids,
                        )
                    if result.zero_damage_sub_fleets:
                        log.warning(
                            "sub-fleets did zero outgoing damage; damage_share=0 for all members",
                            zero_damage_sub_fleets=result.zero_damage_sub_fleets,
                        )

                    log.info(
                        "features computed",
                        rows=len(result.rows),
                        small_tier=result.small_tier,
                    )

                    summary = _completeness_summary([r.feature_completeness for r in result.rows])
                    log.info("feature_completeness distribution", **summary)

                    if args.dry_run:
                        log.info("dry-run complete — no writes")
                        return 0

                    write_features(
                        conn,
                        battle_id=args.battle_id,
                        alliance_id=args.alliance_id,
                        partition_algo_version=part_v,
                        rows=result.rows,
                        bucket_seconds=args.bucket_seconds,
                    )
                    log.info(
                        "features written",
                        run_status="success",
                        rows_written=len(result.rows),
                    )
                    return 0
                finally:
                    release_lock(conn, f_key)
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
