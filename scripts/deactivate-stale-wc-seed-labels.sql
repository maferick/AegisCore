-- Deactivate legacy source='seed' rows in coalition_entity_labels that
-- survived the 2026-04-19 wiki reconciliation
-- (scripts/seed-winterco-coalition-labels.sql).
--
-- Two categories:
--   (a) duplicates of wiki rows (same entity_id, same bloc) — kept
--       the wiki row, drop the seed.
--   (b) alliances labeled WinterCo by an old seed but not present in
--       https://wiki.winterco.org/en/guide/coalition/winter_coalition_member_and_allied_alliances
--
-- Cross-checked against alliance_pair_behavior_rolling: the stale
-- entries that still have observable activity (Sigma Grindset
-- 99011223 and Minmatar Fleet Associates 99012009) show
-- avg_affinity≈0 / avg_hostility≈1 vs confirmed WC members. Behavior
-- agrees with the wiki — not friendly.

UPDATE coalition_entity_labels
   SET is_active = 0, updated_at = NOW()
 WHERE entity_type = 'alliance'
   AND source = 'seed'
   AND is_active = 1
   AND bloc_id = 1
   AND entity_id IN (
     99005393,   -- Blades of Grass (dup of wiki row)
     99003581,   -- Fraternity. (dup of wiki row)
     99012009,   -- Literally Triggered → now Minmatar Fleet Associates (hostile)
     99011223,   -- Mistakes Were Made. → now Sigma Grindset (hostile)
     99003838,   -- Ranger Regiment (defunct / no 90d activity)
     99009310,   -- Siege Green. → now VENI VIDI VICI.
     99011834,   -- Valkyrie Alliance (defunct / no 90d activity)
     99010562    -- Winter Coalition (synthetic — not a real alliance entity)
   );
