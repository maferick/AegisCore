"""Battle-scoped role feature extraction (Spec 4, v1).

Reads Spec 2 graph metrics + Spec 3 sub-fleet membership for one
(battle_id, alliance_id) tuple, computes 15 per-character features
described in docs/spec4_feature_manifest.md, and writes them into
battle_character_role_features.

Shares MariaDB GET_LOCK keys with Spec 2 and Spec 3 so the inputs
cannot be rewritten mid-pass:
  - graph-metrics lock (shared with battle_graph) — same sha1 as
    battle_partition/db.py::graph_metrics_lock_key
  - partition lock     (shared with battle_partition) — same sha1 as
    battle_partition/db.py::partition_lock_key

Run holds both as short session-level locks, reads inputs, computes,
writes, releases.
"""
