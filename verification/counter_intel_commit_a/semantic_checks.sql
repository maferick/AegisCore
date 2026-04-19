-- Counter-Intel Commit A semantic checks.
-- Run against aegiscore MariaDB after `make ci-anomalies VIEWER_BLOC=1`.

-- 1. Subject-set integrity: zero external hostiles in anomaly table.
--    Expect: empty result.
SELECT a.character_id, en.name, a.review_priority_band
  FROM ci_character_anomalies_rolling a
  LEFT JOIN esi_entity_names en ON en.entity_id=a.character_id AND en.category='character'
 WHERE a.viewer_bloc_id=1
   AND a.character_id NOT IN (
        SELECT DISTINCT cch.character_id
          FROM character_corporation_history cch
          JOIN corporation_alliance_history cah ON cah.corporation_id=cch.corporation_id
          JOIN coalition_entity_labels cel ON cel.entity_id=cah.alliance_id
                                          AND cel.entity_type='alliance'
                                          AND cel.is_active=1
         WHERE cch.is_deleted=0
           AND (cch.end_date IS NULL OR cch.end_date>NOW())
           AND (cah.end_date IS NULL OR cah.end_date>NOW())
           AND cel.bloc_id=1
   )
 LIMIT 20;

-- 2. Band distribution.
SELECT review_priority_band, COUNT(*) AS n
  FROM ci_character_anomalies_rolling
 WHERE viewer_bloc_id=1
 GROUP BY review_priority_band
 ORDER BY FIELD(review_priority_band,'critical','high','elevated','below_threshold','cohort_unavailable');

-- 3. Score distribution sanity (min/p25/p50/p75/max of scored rows).
SELECT
  MIN(review_priority_score) AS min_s,
  MAX(review_priority_score) AS max_s,
  AVG(review_priority_score) AS avg_s,
  COUNT(*) AS n
  FROM ci_character_anomalies_rolling
 WHERE viewer_bloc_id=1 AND review_priority_score IS NOT NULL;

-- 4. Any internal high/critical with ZERO hostile signals? (shouldn't be many —
--    they'd only arrive through bridge + churn combinations).
SELECT character_id, review_priority_score, review_priority_band,
       hostile_alliance_count_history, hostile_cooccurrence_count,
       bridge_anomaly_pct, affiliation_churn_pct
  FROM ci_character_anomalies_rolling
 WHERE viewer_bloc_id=1
   AND review_priority_band IN ('critical','high')
   AND hostile_alliance_count_history=0
   AND hostile_cooccurrence_count=0
 LIMIT 20;

-- 5. Recent-hostile-join flag check: anyone flagged with join <30d AND
--    their most recent alliance actually IS hostile-tagged?
SELECT a.character_id, en.name, a.review_priority_score, a.recent_hostile_join
  FROM ci_character_anomalies_rolling a
  LEFT JOIN esi_entity_names en ON en.entity_id=a.character_id AND en.category='character'
 WHERE a.viewer_bloc_id=1 AND a.recent_hostile_join=1
 LIMIT 20;
