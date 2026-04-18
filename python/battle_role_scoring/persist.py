"""Transactional write-back of score + inference rows.

Score rows UPSERT on the Spec 5-post-migration PK:
  (battle_id, alliance_id, sub_fleet_id, character_id, partition_algo_version,
   weight_version, role_key, score_class)

Inference rows UPSERT on:
  (battle_id, alliance_id, sub_fleet_id, character_id, partition_algo_version,
   weight_version)

Both writes share a single MariaDB transaction; partial output never lands.
Re-running under the same weight_version is byte-identical modulo
computed_at (which advances).
"""

from __future__ import annotations

from datetime import datetime, timezone

import pymysql

from battle_role_scoring.score import InferenceRow, ScoreRow


_UPSERT_SCORE = """
INSERT INTO battle_character_role_scores
  (battle_id, alliance_id, sub_fleet_id, character_id, partition_algo_version,
   weight_version, role_key, score_class, score_value, computed_at)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
  score_value = VALUES(score_value),
  computed_at = VALUES(computed_at)
"""

_UPSERT_INFERENCE = """
INSERT INTO battle_character_role_inference
  (battle_id, alliance_id, sub_fleet_id, character_id, partition_algo_version,
   weight_version, primary_role_key, primary_score, second_best_score,
   confidence, confidence_band, ui_state, computed_at)
VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
ON DUPLICATE KEY UPDATE
  primary_role_key = VALUES(primary_role_key),
  primary_score = VALUES(primary_score),
  second_best_score = VALUES(second_best_score),
  confidence = VALUES(confidence),
  confidence_band = VALUES(confidence_band),
  ui_state = VALUES(ui_state),
  computed_at = VALUES(computed_at)
"""


def write_scores_and_inference(
    conn: pymysql.connections.Connection,
    *,
    battle_id: int,
    alliance_id: int,
    partition_algo_version: int,
    weight_version: int,
    scores: list[ScoreRow],
    inferences: list[InferenceRow],
) -> None:
    now = datetime.now(timezone.utc).replace(tzinfo=None)

    score_params = [
        (
            battle_id, alliance_id, s.sub_fleet_id, s.character_id, partition_algo_version,
            weight_version, s.role_key, s.score_class, round(s.score_value, 4), now,
        )
        for s in scores
    ]
    inf_params = [
        (
            battle_id, alliance_id, i.sub_fleet_id, i.character_id, partition_algo_version,
            weight_version, i.primary_role_key, i.primary_score, i.second_best_score,
            i.confidence, i.confidence_band, "beta", now,
        )
        for i in inferences
    ]

    try:
        with conn.cursor() as cur:
            if score_params:
                cur.executemany(_UPSERT_SCORE, score_params)
            if inf_params:
                cur.executemany(_UPSERT_INFERENCE, inf_params)
        conn.commit()
    except Exception:
        try:
            conn.rollback()
        except Exception:
            pass
        raise
