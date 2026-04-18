-- Spec 5 structural + determinism checks. Not an accuracy gate.

-- ========================================================
-- STRUCTURAL
-- ========================================================

-- 1. Every char has exactly 12 score rows under v0 (3 roles × 4 classes)
SELECT 'score row count per char' AS chk,
       SUM(score_rows = 12) AS chars_with_12,
       SUM(score_rows <> 12) AS chars_with_wrong_count
  FROM (
    SELECT battle_id, alliance_id, character_id, partition_algo_version, COUNT(*) AS score_rows
      FROM battle_character_role_scores
     WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553)
       AND weight_version=(SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed')
     GROUP BY battle_id, alliance_id, character_id, partition_algo_version
  ) t;

-- 2. All `final` score values are in [0, 1]
SELECT 'final score bounds' AS chk,
       SUM(score_value < 0 OR score_value > 1) AS out_of_range
  FROM battle_character_role_scores
 WHERE score_class='final'
   AND weight_version=(SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed');

-- 3. Each char has at most one inference row per weight_version (option C: single-winner)
SELECT 'inference row count per char' AS chk,
       SUM(n = 1) AS chars_with_one_row,
       SUM(n > 1) AS chars_with_multiple
  FROM (
    SELECT battle_id, alliance_id, character_id, partition_algo_version, COUNT(*) AS n
      FROM battle_character_role_inference
     WHERE weight_version=(SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed')
     GROUP BY battle_id, alliance_id, character_id, partition_algo_version
  ) t;

-- 4. All confidence_band values are in the allowed set
SELECT 'confidence_band values' AS chk,
       confidence_band, COUNT(*) AS n
  FROM battle_character_role_inference
 WHERE weight_version=(SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed')
 GROUP BY confidence_band;

-- 5. Confidence in [0, 1]
SELECT 'confidence bounds' AS chk,
       SUM(confidence < 0 OR confidence > 1) AS out_of_range
  FROM battle_character_role_inference
 WHERE weight_version=(SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed');

-- 6. Every inference row's (char, sub_fleet_id) appears in features (FK integrity)
SELECT 'inference orphans' AS chk, COUNT(*) AS orphans
  FROM battle_character_role_inference i
  LEFT JOIN battle_character_role_features f
    ON f.battle_id=i.battle_id AND f.alliance_id=i.alliance_id
   AND f.sub_fleet_id=i.sub_fleet_id AND f.character_id=i.character_id
   AND f.partition_algo_version=i.partition_algo_version
 WHERE i.weight_version=(SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed')
   AND f.character_id IS NULL;

-- ========================================================
-- OUTCOME SUMMARY (diagnostic, not pass/fail)
-- ========================================================

-- 7. Inference counts per battle + role
SELECT battle_id, alliance_id, primary_role_key, COUNT(*) AS n,
       ROUND(AVG(primary_score), 4) AS avg_score,
       ROUND(AVG(confidence), 4) AS avg_conf
  FROM battle_character_role_inference
 WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553)
   AND weight_version=(SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed')
 GROUP BY battle_id, alliance_id, primary_role_key
 ORDER BY battle_id, primary_role_key;
