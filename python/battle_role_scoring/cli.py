"""CLI + orchestrator for Spec 5 scoring runs.

Usage:
    python -m battle_role_scoring run --battle-id 40541 --alliance-id 99011223
    python -m battle_role_scoring run ... --weight-version 2
    python -m battle_role_scoring run ... --weight-label v0_scoring_seed
    python -m battle_role_scoring run ... --dry-run

Three-lock protocol:
  - graph-metrics lock  (shared with Specs 2, 3, 4)
  - partition lock      (shared with Specs 3, 4)
  - scoring lock        (Spec-5-specific, 'bs_' prefix, scoped by
                          weight_version so different weight versions
                          don't block each other)
"""

from __future__ import annotations

import argparse
import sys

from battle_role_scoring.config import Config
from battle_role_scoring.db import (
    acquire_lock, graph_metrics_lock_key, maria, partition_lock_key,
    release_lock, scoring_lock_key,
)
from battle_role_scoring.inputs import (
    features_exist, load_coefficients, load_features, resolve_weight_version_id,
)
from battle_role_scoring.log import get
from battle_role_scoring.persist import write_scores_and_inference
from battle_role_scoring.profiles import (
    resolve_graph_profile_combo, resolve_partition_algo_version,
)
from battle_role_scoring.score import ACTIVE_CLASSES, score_battle


log = get(__name__)


def _parse() -> argparse.Namespace:
    p = argparse.ArgumentParser(prog="battle_role_scoring")
    sub = p.add_subparsers(dest="cmd", required=True)

    r = sub.add_parser("run", help="Score one (battle, alliance)")
    r.add_argument("--battle-id", type=int, required=True)
    r.add_argument("--alliance-id", type=int, required=True)
    r.add_argument("--partition-algo-version", type=int, default=None)
    g = r.add_mutually_exclusive_group()
    g.add_argument("--weight-version", type=int, default=None)
    g.add_argument("--weight-label", type=str, default=None)
    r.add_argument("--dry-run", action="store_true")

    return p.parse_args()


def run(args: argparse.Namespace) -> int:
    cfg = Config.from_env()

    with maria(cfg) as conn:
        # Resolve weight version (by id, label, or default label).
        label = args.weight_label or (None if args.weight_version is not None else cfg.default_weight_label)
        wv_id, wv_label = resolve_weight_version_id(conn, args.weight_version, label)

        # Resolve upstream profile combo + partition version.
        edge_v, algo_v = resolve_graph_profile_combo(conn, args.battle_id, args.alliance_id)
        part_v = resolve_partition_algo_version(
            conn, args.battle_id, args.alliance_id,
            args.partition_algo_version, edge_v, algo_v,
        )

        log.info(
            "profiles resolved",
            battle_id=args.battle_id, alliance_id=args.alliance_id,
            partition_algo_version=part_v,
            edge_profile_version=edge_v, algo_profile_version=algo_v,
            weight_version=wv_id, weight_label=wv_label,
        )

        if not features_exist(conn, args.battle_id, args.alliance_id, part_v):
            log.error(
                "no Spec 4 features found; run battle_features first",
                battle_id=args.battle_id, alliance_id=args.alliance_id,
                partition_algo_version=part_v,
            )
            return 1

        gm_key = graph_metrics_lock_key(args.battle_id, args.alliance_id, edge_v, algo_v)
        p_key = partition_lock_key(args.battle_id, args.alliance_id, part_v)
        s_key = scoring_lock_key(args.battle_id, args.alliance_id, part_v, wv_id)

        acquire_lock(conn, gm_key, timeout_seconds=30)
        try:
            acquire_lock(conn, p_key, timeout_seconds=30)
            try:
                acquire_lock(conn, s_key, timeout_seconds=5)
                log.info(
                    "locks acquired",
                    gm_key=gm_key, partition_key=p_key, scoring_key=s_key,
                )

                try:
                    features = load_features(conn, args.battle_id, args.alliance_id, part_v)
                    coefs = load_coefficients(conn, wv_id)
                    log.info(
                        "inputs loaded",
                        features=len(features), coefficients=len(coefs),
                        active_classes=list(ACTIVE_CLASSES),
                    )

                    result = score_battle(features, coefs, active_classes=ACTIVE_CLASSES)

                    for diag in result.per_sub_fleet_diagnostics:
                        log.info("sub_fleet diagnostic", **diag)

                    log.info(
                        "scoring computed",
                        score_rows=len(result.scores),
                        inference_rows=len(result.inferences),
                        winners_by_role=_role_counts(result.inferences),
                    )

                    if args.dry_run:
                        log.info("dry-run complete — no writes")
                        return 0

                    write_scores_and_inference(
                        conn,
                        battle_id=args.battle_id,
                        alliance_id=args.alliance_id,
                        partition_algo_version=part_v,
                        weight_version=wv_id,
                        scores=result.scores,
                        inferences=result.inferences,
                    )
                    log.info(
                        "scores written",
                        run_status="success",
                        score_rows=len(result.scores),
                        inference_rows=len(result.inferences),
                    )
                    return 0
                finally:
                    release_lock(conn, s_key)
            finally:
                release_lock(conn, p_key)
        finally:
            release_lock(conn, gm_key)


def _role_counts(inferences: list) -> dict[str, int]:
    counts: dict[str, int] = {}
    for i in inferences:
        counts[i.primary_role_key] = counts.get(i.primary_role_key, 0) + 1
    return counts


def main() -> int:
    args = _parse()
    if args.cmd == "run":
        return run(args)
    raise SystemExit(f"unknown command: {args.cmd}")


if __name__ == "__main__":
    sys.exit(main())
