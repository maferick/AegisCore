"""Battle-scoped sub-fleet partitioning and membership materialization.

Implements Spec 3. Consumes the battle_character_graph_metrics rows
Spec 2 produced for a given (battle_id, alliance_id,
edge_profile_version, algo_profile_version) combo, applies a
deterministic partition rule, and writes authoritative sub-fleet
headers + per-character membership.

Shares an advisory-lock key with Spec 2 (same sha1 derivation on the
same tuple) so mid-write reads from Spec 2 can't tear into Spec 3's
partition pass.
"""
