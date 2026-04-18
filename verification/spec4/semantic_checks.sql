-- Spec 4 semantic sanity checks on the 8 validation battles.
-- Run with:
--   docker compose ... exec -T mariadb mariadb ... aegiscore < semantic_checks.sql
--
-- Each query labels itself. A "pass" is:
--   - mismatch / violation queries return zero rows
--   - per-battle queries return values matching verification/spec4/README.md

-- ========================================================
-- BOUNDS AND CONSISTENCY
-- ========================================================

-- 1. damage_share per sub-fleet must sum to 1.0 or 0.0 (within DECIMAL(5,4)
--    precision: N * 5e-5 + 1e-6). Rows returned = potential bug.
SELECT 'damage_share sum out of tolerance' AS chk,
       battle_id, alliance_id, sub_fleet_id,
       COUNT(*) AS n,
       ROUND(SUM(damage_share), 6) AS sum_ds
  FROM battle_character_role_features
 WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553)
 GROUP BY battle_id, alliance_id, sub_fleet_id
HAVING ABS(SUM(damage_share) - 1.0) > (COUNT(*) * 0.00005 + 0.000001)
   AND SUM(damage_share) <> 0.0
 ORDER BY battle_id, sub_fleet_id;

-- 2. subfleet_damage_share_of_side per side must sum to 1.0 within
--    the same precision envelope.
SELECT 'subfleet_damage_share_of_side sum out of tolerance' AS chk, battle_id, alliance_id,
       ROUND(SUM(sf_ds), 6) AS side_total
  FROM (
      SELECT battle_id, alliance_id, sub_fleet_id,
             MIN(subfleet_damage_share_of_side) AS sf_ds,
             COUNT(*) AS sf_n
        FROM battle_character_role_features
       WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553)
       GROUP BY battle_id, alliance_id, sub_fleet_id
  ) t
 GROUP BY battle_id, alliance_id
HAVING ABS(SUM(sf_ds) - 1.0) > 0.001
 ORDER BY battle_id;

-- 3. is_in_subfleet_0 must be 1 iff sub_fleet_id = 0.
SELECT 'is_in_subfleet_0 mismatch' AS chk, COUNT(*) AS violations
  FROM battle_character_role_features
 WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553)
   AND ( (sub_fleet_id = 0 AND is_in_subfleet_0 <> 1)
      OR (sub_fleet_id <> 0 AND is_in_subfleet_0 <> 0));

-- 4. early_presence / late_presence must be exactly 0.0 or 1.0.
SELECT 'non-binary early/late' AS chk, COUNT(*) AS violations
  FROM battle_character_role_features
 WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553)
   AND (early_presence NOT IN (0.0000, 1.0000)
        OR late_presence NOT IN (0.0000, 1.0000));

-- 5. Every in-range feature must fall in [0, 1].
SELECT 'bounds audit [0,1]' AS chk,
       SUM(damage_share < 0 OR damage_share > 1) AS bad_ds,
       SUM(kill_participation_rate < 0 OR kill_participation_rate > 1) AS bad_kpr,
       SUM(presence_span < 0 OR presence_span > 1) AS bad_ps,
       SUM(death_order_pct < 0 OR death_order_pct > 1) AS bad_dop,
       SUM(degree_centrality IS NOT NULL AND (degree_centrality < 0 OR degree_centrality > 1)) AS bad_deg,
       SUM(pagerank IS NOT NULL AND (pagerank < 0 OR pagerank > 1)) AS bad_pr,
       SUM(subfleet_damage_share_of_side < 0 OR subfleet_damage_share_of_side > 1) AS bad_sf_ds,
       SUM(subfleet_hull_class_concentration IS NOT NULL AND (subfleet_hull_class_concentration < 0 OR subfleet_hull_class_concentration > 1)) AS bad_conc
  FROM battle_character_role_features
 WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553);

-- 6. feature_completeness uniformity per battle (distribution stdev = 0).
SELECT 'feature_completeness stdev per battle' AS chk, battle_id, alliance_id,
       ROUND(STDDEV_POP(feature_completeness), 6) AS stdev,
       ROUND(AVG(feature_completeness), 4) AS mean_fc,
       COUNT(*) AS rows_
  FROM battle_character_role_features
 WHERE battle_id IN (40365,40228,40374,40541,40478,40537,40605,40553)
 GROUP BY battle_id, alliance_id
 ORDER BY battle_id;

-- ========================================================
-- PER-BATTLE SEMANTIC EXPECTATIONS
-- ========================================================

-- 40541 U-L4KS — Monitor pilot categorization
SELECT '40541 Monitor ship_class_category' AS chk, f.character_id,
       rit.name AS ship, f.ship_class_category, f.sub_fleet_id,
       ROUND(f.degree_centrality, 4) AS degree,
       ROUND(f.pagerank, 4) AS pagerank,
       f.presence_span, f.early_presence, f.late_presence,
       f.death_order_pct
  FROM battle_character_role_features f
  JOIN ref_item_types rit ON rit.id = f.ship_type_id
 WHERE f.battle_id = 40541 AND f.alliance_id = 99011223 AND rit.name = 'Monitor';

-- 40478 Atioth — sub-fleet 2 is a bomber wing
SELECT '40478 sf2 hull distribution' AS chk, ship_class_category, COUNT(*) AS pilots
  FROM battle_character_role_features
 WHERE battle_id = 40478 AND alliance_id = 99003581 AND sub_fleet_id = 2
 GROUP BY ship_class_category
 ORDER BY pilots DESC;

SELECT '40478 sf2 denormalized' AS chk,
       MIN(subfleet_dominant_hull_class) AS dom,
       ROUND(MIN(subfleet_hull_class_concentration), 4) AS conc,
       MIN(subfleet_has_logi) AS has_logi,
       MIN(subfleet_member_count) AS sf_n
  FROM battle_character_role_features
 WHERE battle_id = 40478 AND alliance_id = 99003581 AND sub_fleet_id = 2;

-- 40605 9S-GPT — Scimitar pilots are logi, low damage, high participation
SELECT '40605 Scimi pilots' AS chk,
       COUNT(*) AS n_logi,
       ROUND(MAX(damage_share), 4) AS max_ds,
       ROUND(MIN(kill_participation_rate), 4) AS min_kpr,
       ROUND(AVG(kill_participation_rate), 4) AS avg_kpr
  FROM battle_character_role_features f
  JOIN ref_item_types rit ON rit.id = f.ship_type_id
 WHERE f.battle_id = 40605 AND f.alliance_id = 99012122 AND rit.name = 'Scimitar';

SELECT '40605 sf0 has_logi' AS chk, MIN(subfleet_has_logi) AS hl, COUNT(*) AS pilots
  FROM battle_character_role_features
 WHERE battle_id = 40605 AND alliance_id = 99012122 AND sub_fleet_id = 0;

-- 40553 6RQ9-A — single cohesive sub-fleet
SELECT '40553 single sub-fleet' AS chk, COUNT(*) AS rows_,
       MIN(is_in_subfleet_0) AS min_is0, MAX(is_in_subfleet_0) AS max_is0,
       MIN(subfleet_damage_share_of_side) AS sf_ds,
       MIN(subfleet_member_count) AS sf_mc
  FROM battle_character_role_features
 WHERE battle_id = 40553 AND alliance_id = 99011223;
