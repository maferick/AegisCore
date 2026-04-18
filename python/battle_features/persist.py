"""Transactional write-back of Spec 4 feature rows.

One UPSERT per (battle, alliance, sub_fleet, character) in a single
MariaDB transaction; partial output never lands. Every Spec 1 feature
column the v1 extractor doesn't populate is written as explicit NULL
(the migration relaxed those columns to NULL). degree_centrality +
pagerank may themselves be NULL on small-tier battles.
"""

from __future__ import annotations

from datetime import datetime, timezone

import pymysql

from battle_features.extract import FeatureRow


_UPSERT_SQL = """
INSERT INTO battle_character_role_features
  (battle_id, alliance_id, sub_fleet_id, partition_algo_version, character_id,
   ship_type_id, ship_class_category, is_in_subfleet_0,
   subfleet_member_count, subfleet_damage_share_of_side,
   subfleet_dominant_hull_class, subfleet_hull_class_concentration,
   subfleet_has_logi,
   presence_span, early_presence, late_presence,
   death_order_pct, kill_participation_rate,
   degree_centrality, pagerank,
   damage_share,
   feature_completeness, bucket_seconds, computed_at,
   primary_sub_fleet_share, victim_overlap_density, same_bucket_cooccurrence,
   engagement_phase_count_norm, betweenness_centrality, clustering_coefficient,
   local_blob_score, support_ring_score, edge_cluster_score,
   logi_ring_affinity, fc_core_affinity, final_blow_rate, contributed_kill_rate,
   isk_killed_share, isk_lost_norm, target_spread, focus_fire_alignment)
VALUES
  (%s, %s, %s, %s, %s,
   %s, %s, %s,
   %s, %s,
   %s, %s,
   %s,
   %s, %s, %s,
   %s, %s,
   %s, %s,
   %s,
   %s, %s, %s,
   NULL, NULL, NULL,
   NULL, NULL, NULL,
   NULL, NULL, NULL,
   NULL, NULL, NULL, NULL,
   NULL, NULL, NULL, NULL)
ON DUPLICATE KEY UPDATE
  sub_fleet_id = VALUES(sub_fleet_id),
  ship_type_id = VALUES(ship_type_id),
  ship_class_category = VALUES(ship_class_category),
  is_in_subfleet_0 = VALUES(is_in_subfleet_0),
  subfleet_member_count = VALUES(subfleet_member_count),
  subfleet_damage_share_of_side = VALUES(subfleet_damage_share_of_side),
  subfleet_dominant_hull_class = VALUES(subfleet_dominant_hull_class),
  subfleet_hull_class_concentration = VALUES(subfleet_hull_class_concentration),
  subfleet_has_logi = VALUES(subfleet_has_logi),
  presence_span = VALUES(presence_span),
  early_presence = VALUES(early_presence),
  late_presence = VALUES(late_presence),
  death_order_pct = VALUES(death_order_pct),
  kill_participation_rate = VALUES(kill_participation_rate),
  degree_centrality = VALUES(degree_centrality),
  pagerank = VALUES(pagerank),
  damage_share = VALUES(damage_share),
  feature_completeness = VALUES(feature_completeness),
  bucket_seconds = VALUES(bucket_seconds),
  computed_at = VALUES(computed_at),
  primary_sub_fleet_share = NULL,
  victim_overlap_density = NULL,
  same_bucket_cooccurrence = NULL,
  engagement_phase_count_norm = NULL,
  betweenness_centrality = NULL,
  clustering_coefficient = NULL,
  local_blob_score = NULL,
  support_ring_score = NULL,
  edge_cluster_score = NULL,
  logi_ring_affinity = NULL,
  fc_core_affinity = NULL,
  final_blow_rate = NULL,
  contributed_kill_rate = NULL,
  isk_killed_share = NULL,
  isk_lost_norm = NULL,
  target_spread = NULL,
  focus_fire_alignment = NULL
"""


def write_features(
    conn: pymysql.connections.Connection,
    *,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
    rows: list[FeatureRow],
    bucket_seconds: int = 30,
) -> None:
    now = datetime.now(timezone.utc).replace(tzinfo=None)
    params = [
        (
            battle_id, alliance_id, r.sub_fleet_id, partition_algo_version, r.character_id,
            r.ship_type_id, r.ship_class_category, 1 if r.is_in_subfleet_0 else 0,
            r.subfleet_member_count, r.subfleet_damage_share_of_side,
            r.subfleet_dominant_hull_class, r.subfleet_hull_class_concentration,
            1 if r.subfleet_has_logi else 0,
            r.presence_span, r.early_presence, r.late_presence,
            r.death_order_pct, r.kill_participation_rate,
            r.degree_centrality, r.pagerank,
            r.damage_share,
            r.feature_completeness, bucket_seconds, now,
        )
        for r in rows
    ]
    try:
        with conn.cursor() as cur:
            if params:
                cur.executemany(_UPSERT_SQL, params)
        conn.commit()
    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise
