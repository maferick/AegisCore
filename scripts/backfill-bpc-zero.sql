-- Backfill BPC pricing — zero out items where singleton=2 (blueprint
-- copy) and recompute affected killmails' aggregate values.
--
-- Background: EnrichKillmail v1 priced singleton=2 (BPC) items at the
-- BPO Jita price, wildly overstating losses that contained blueprint
-- cargo. Per the BPC zero rule (zKillboard convention) every BPC unit
-- is priced at 0.
--
-- Step 1: zero the items.
UPDATE killmail_items
   SET unit_value = 0,
       total_value = 0,
       valuation_source = 'bpc_zero',
       updated_at = NOW()
 WHERE singleton = 2
   AND total_value > 0;

-- Step 2: snapshot the killmail ids that need recompute. Materialise
-- to a temp table so the per-aggregate UPDATEs all hit the same set.
DROP TEMPORARY TABLE IF EXISTS _bpc_affected_kms;
CREATE TEMPORARY TABLE _bpc_affected_kms (
    killmail_id BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=MEMORY;
INSERT INTO _bpc_affected_kms
SELECT DISTINCT killmail_id FROM killmail_items WHERE valuation_source = 'bpc_zero';

-- Step 3: recompute fitted / cargo / drone / total per affected
-- killmail. Mirrors the EnrichKillmail::computeAggregates math so the
-- backfill produces the same values a re-enrich would.
UPDATE killmails k
   JOIN _bpc_affected_kms a ON a.killmail_id = k.killmail_id
   LEFT JOIN (
       SELECT killmail_id,
              SUM(CASE WHEN slot_category IN ('high','mid','low','rig','subsystem','service') THEN total_value ELSE 0 END) AS fitted,
              SUM(CASE WHEN slot_category = 'cargo' THEN total_value ELSE 0 END) AS cargo,
              SUM(CASE WHEN slot_category IN ('drone_bay','fighter_bay') THEN total_value ELSE 0 END) AS drone,
              SUM(CASE WHEN slot_category = 'implant' THEN total_value ELSE 0 END) AS implant,
              SUM(CASE WHEN slot_category = 'other' THEN total_value ELSE 0 END) AS other_val
         FROM killmail_items
        GROUP BY killmail_id
   ) agg ON agg.killmail_id = k.killmail_id
   SET k.fitted_value = COALESCE(agg.fitted, 0),
       k.cargo_value = COALESCE(agg.cargo, 0),
       k.drone_value = COALESCE(agg.drone, 0),
       k.total_value = COALESCE(k.hull_value, 0)
                     + COALESCE(agg.fitted, 0)
                     + COALESCE(agg.cargo, 0)
                     + COALESCE(agg.drone, 0)
                     + COALESCE(agg.implant, 0)
                     + COALESCE(agg.other_val, 0),
       k.updated_at = NOW();

-- Step 4: report.
SELECT 'bpc_items_zeroed', ROW_COUNT() AS row_count_step3
  FROM (SELECT 1) t;
SELECT 'killmails_recomputed', COUNT(*) FROM _bpc_affected_kms;

DROP TEMPORARY TABLE _bpc_affected_kms;
