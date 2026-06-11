-- Phase A — spy detection signal expansion semantic checks
-- run after dossier renders today's data:
--   php artisan tinker --execute='app(\App\Domains\CounterIntel\Services\CounterIntelDossierService::class)->dossier(2124244672, 1);'

-- 1. Bakkanta one — should now fire 2 new signals if anomaly row is fresh
SELECT character_id, rendered_on, rendered_band, raw_band, flag_count, note_count,
       SUBSTRING(rendered_signals_json, 1, 400) AS signals_preview
  FROM ci_render_diagnostics
 WHERE character_id = 2124244672 AND viewer_bloc_id = 1
 ORDER BY rendered_on DESC
 LIMIT 3;

-- 2. New signal `low_contribution` shape — pull any character with avg_damage_share <= 0.05 and >=5 attacker kms
SELECT f.character_id, en.name, f.battles, f.killmails_attacker, f.avg_damage_share
  FROM ci_character_features_rolling f
  LEFT JOIN esi_entity_names en ON en.entity_id=f.character_id AND en.category='character'
 WHERE f.window_end_date = (SELECT MAX(window_end_date) FROM ci_character_features_rolling)
   AND f.avg_damage_share <= 0.05
   AND f.killmails_attacker >= 5
   AND f.battles >= 2
 ORDER BY f.killmails_attacker DESC
 LIMIT 20;

-- 3. asymmetric_pair fires below the old gate (3-4 shared days)
SELECT a.character_id, en.name, a.asymmetric_top_pair_battles AS shared_days,
       a.asymmetric_top_pair_outbound_pct, a.asymmetric_top_pair_inbound_pct
  FROM ci_character_anomalies_rolling a
  LEFT JOIN esi_entity_names en ON en.entity_id=a.character_id AND en.category='character'
 WHERE a.window_end_date = (SELECT MAX(window_end_date) FROM ci_character_anomalies_rolling WHERE viewer_bloc_id=1)
   AND a.viewer_bloc_id = 1
   AND a.asymmetric_top_pair_battles BETWEEN 3 AND 4
   AND a.asymmetric_top_pair_outbound_pct >= 0.30
 ORDER BY a.asymmetric_top_pair_outbound_pct DESC
 LIMIT 30;

-- 4. community_mismatch fires below the old gate (5-19 neighbours)
SELECT a.character_id, en.name, a.community_neighbor_count AS n_neighbors,
       a.community_hostile_pct
  FROM ci_character_anomalies_rolling a
  LEFT JOIN esi_entity_names en ON en.entity_id=a.character_id AND en.category='character'
 WHERE a.window_end_date = (SELECT MAX(window_end_date) FROM ci_character_anomalies_rolling WHERE viewer_bloc_id=1)
   AND a.viewer_bloc_id = 1
   AND a.community_neighbor_count BETWEEN 5 AND 19
   AND a.community_hostile_pct >= 0.40
 ORDER BY a.community_hostile_pct DESC, a.community_neighbor_count DESC
 LIMIT 30;

-- 5. band distribution — sanity check the new gates didn't blow out the queue
SELECT rendered_band, COUNT(*) AS n
  FROM ci_render_diagnostics
 WHERE rendered_on = CURDATE() AND viewer_bloc_id = 1
 GROUP BY rendered_band
 ORDER BY FIELD(rendered_band,'critical','high','elevated','note_only','clean','insufficient_history');

-- 6. calibration paper trail
SELECT id, surface, field, prior_value, proposed_value, status, baseline_ref
  FROM calibration_proposals
 WHERE baseline_ref = 'phase-a-spy-signals';
