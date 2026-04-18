-- Spec 6 structural checks.

-- 1. Attestation table exists with correct PK + FKs
SELECT 'table exists' AS chk, COUNT(*) AS n
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'battle_fc_user_attestations';

SELECT 'FK fk_bfua_subfleet present' AS chk, COUNT(*) AS n
  FROM information_schema.referential_constraints
 WHERE constraint_schema = DATABASE()
   AND constraint_name = 'fk_bfua_subfleet';

SELECT 'FK fk_bfua_user present' AS chk, COUNT(*) AS n
  FROM information_schema.referential_constraints
 WHERE constraint_schema = DATABASE()
   AND constraint_name = 'fk_bfua_user';

-- 2. Role inference tables populated (Spec 5 output still present)
SELECT 'inference rows under v0_scoring_seed' AS chk, COUNT(*) AS n
  FROM battle_character_role_inference
 WHERE weight_version = (SELECT weight_version FROM battle_role_weight_versions WHERE label='v0_scoring_seed');

-- 3. Mode A query shape — latest attestation per (sub-fleet, user)
--    This is the query Spec 7 will run to consume attestations.
SELECT 'spec7 consumption shape' AS chk, 'ok' AS status
  FROM DUAL
 WHERE EXISTS (
     SELECT 1 FROM (
         SELECT a.*,
                ROW_NUMBER() OVER (
                    PARTITION BY battle_id, alliance_id, sub_fleet_id, partition_algo_version, user_id
                    ORDER BY attested_at DESC, attestation_id DESC
                ) rn
         FROM battle_fc_user_attestations a
     ) t
     LIMIT 1
 ) OR NOT EXISTS (SELECT 1 FROM battle_fc_user_attestations);

-- 4. No attestation can reference a nonexistent sub-fleet (enforced by FK).
--    The test query here counts orphans; should always be 0.
SELECT 'attestation orphans' AS chk, COUNT(*) AS n
  FROM battle_fc_user_attestations a
  LEFT JOIN battle_sub_fleets sf
    ON sf.battle_id = a.battle_id
   AND sf.alliance_id = a.alliance_id
   AND sf.sub_fleet_id = a.sub_fleet_id
   AND sf.partition_algo_version = a.partition_algo_version
 WHERE sf.battle_id IS NULL;

-- 5. Append-only shape (informational): a user may have multiple
--    rows per (battle, alliance, sub_fleet). Counts by user give a
--    sense of aggregate use.
SELECT 'per-user attestation counts' AS chk, user_id, COUNT(*) AS rows_total,
       COUNT(DISTINCT CONCAT(battle_id, '-', alliance_id, '-', sub_fleet_id)) AS distinct_sub_fleets
  FROM battle_fc_user_attestations
 GROUP BY user_id;
