# Monitor FC audit — battle 40541, alliance 99011223

Hand-reconstruction of all 15 v1 features for one pilot
(`character_id = 93444333`, flying a Monitor in sub-fleet 1). Every
stored value is reproduced with SQL against raw inputs and compared
to the value in `battle_character_role_features`. Any delta outside
`DECIMAL(5,4)` storage precision (±0.00005) is a bug.

Run this audit with:

```
docker compose --env-file .env -f infra/docker-compose.yml exec -T mariadb \
    mariadb -u root -p"$MARIADB_ROOT_PASSWORD" aegiscore
```

then paste the numbered SQL blocks below.

## Stored row

Extracted 2026-04-18 02:44:46:

| column | value |
|--------|-------|
| sub_fleet_id | 1 |
| ship_type_id | 45534 (Monitor) |
| ship_class_category | `command` |
| is_in_subfleet_0 | 0 |
| damage_share | 0.0000 |
| kill_participation_rate | 0.2500 |
| presence_span | 0.0000 |
| early_presence | 0.0000 |
| late_presence | 0.0000 |
| death_order_pct | 1.0000 |
| degree_centrality | 0.6388 |
| pagerank | 0.6980 |
| subfleet_member_count | 18 |
| subfleet_damage_share_of_side | 0.5443 |
| subfleet_dominant_hull_class | `other` |
| subfleet_hull_class_concentration | 0.8333 |
| subfleet_has_logi | 0 |
| feature_completeness | 1.0000 |

## 1. damage_share — sub-fleet relative

```sql
SELECT
  (SELECT COALESCE(SUM(a.damage_done),0)
     FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541 AND a.character_id=93444333) AS char_dmg,
  (SELECT COALESCE(SUM(a.damage_done),0)
     FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541
      AND a.character_id IN (SELECT character_id
                               FROM battle_character_sub_fleet_membership
                              WHERE battle_id=40541 AND alliance_id=99011223
                                AND sub_fleet_id=1 AND partition_algo_version=1)) AS sf1_dmg;
```

Result: `char_dmg = 0`, `sf1_dmg = 12395`.
Computed: `0 / 12395 = 0.0000`.
Stored: `0.0000`. **Δ = 0.0000**. ✓

## 2. kill_participation_rate — side scoped

```sql
SELECT
  (SELECT COUNT(DISTINCT a.killmail_id)
     FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541 AND a.character_id=93444333) AS char_kms,
  (SELECT COUNT(DISTINCT a.killmail_id)
     FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541
      AND a.character_id IN (SELECT character_id
                               FROM battle_character_sub_fleet_membership
                              WHERE battle_id=40541 AND alliance_id=99011223
                                AND partition_algo_version=1)) AS side_kms;
```

Result: `char_kms = 1`, `side_kms = 4`.
Computed: `1 / 4 = 0.25`.
Stored: `0.2500`. **Δ = 0**. ✓

## 3. presence_span — battle-span relative

```sql
SELECT
  (SELECT MIN(k.killed_at)
     FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
     JOIN killmails k ON k.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541 AND a.character_id=93444333) AS char_first,
  (SELECT MAX(k.killed_at)
     FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
     JOIN killmails k ON k.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541 AND a.character_id=93444333) AS char_last,
  (SELECT MIN(k.killed_at) FROM battle_theater_killmails btk
     JOIN killmails k ON k.killmail_id=btk.killmail_id WHERE btk.theater_id=40541) AS bstart,
  (SELECT MAX(k.killed_at) FROM battle_theater_killmails btk
     JOIN killmails k ON k.killmail_id=btk.killmail_id WHERE btk.theater_id=40541) AS bend;
```

Result: `char_first = char_last = 2026-04-11 19:02:01`;
`bstart = 17:15:01`, `bend = 20:16:13`.
Computed: `(19:02:01 - 19:02:01) / (20:16:13 - 17:15:01) = 0 / 10872s = 0.0000`.
Stored: `0.0000`. **Δ = 0**. ✓

## 4. early_presence — binary, first-timestamp in first 20%

`battle_duration = 10872s`.
`early_cutoff = 17:15:01 + 0.2 * 10872s = 17:15:01 + 2174s ≈ 17:51:15`.
`char_first = 19:02:01`.
`19:02:01 > 17:51:15` → `early_presence = 0.0`.

Stored: `0.0000`. **Δ = 0**. ✓

## 5. late_presence — binary, last-timestamp in last 20%

`late_cutoff = 20:16:13 − 2174s ≈ 19:39:59`.
`char_last = 19:02:01`.
`19:02:01 < 19:39:59` → `late_presence = 0.0`.

Stored: `0.0000`. **Δ = 0**. ✓

## 6. death_order_pct — sub-fleet relative

```sql
SELECT
  (SELECT COUNT(*) FROM battle_theater_killmails btk
     JOIN killmails k ON k.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541 AND k.victim_character_id=93444333) AS monitor_deaths,
  (SELECT COUNT(*) FROM battle_theater_killmails btk
     JOIN killmails k ON k.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541
      AND k.victim_character_id IN (SELECT character_id
                                      FROM battle_character_sub_fleet_membership
                                     WHERE battle_id=40541 AND alliance_id=99011223
                                       AND sub_fleet_id=1 AND partition_algo_version=1)) AS sf1_deaths;
```

Result: `monitor_deaths = 0`, `sf1_deaths = 0`.
Rule: sub-fleet with zero deaths → `death_order_pct = 1.0` for everyone.
Stored: `1.0000`. **Δ = 0**. ✓

## 7. degree_centrality — sub-fleet-normalized weighted degree

```sql
SELECT
  (SELECT weighted_degree_raw FROM battle_character_graph_metrics
    WHERE battle_id=40541 AND alliance_id=99011223 AND character_id=93444333
      AND edge_profile_version=1 AND algo_profile_version=1) AS mon_deg_raw,
  (SELECT MAX(weighted_degree_raw) FROM battle_character_graph_metrics
    WHERE battle_id=40541 AND alliance_id=99011223
      AND edge_profile_version=1 AND algo_profile_version=1
      AND character_id IN (SELECT character_id
                             FROM battle_character_sub_fleet_membership
                            WHERE battle_id=40541 AND alliance_id=99011223
                              AND sub_fleet_id=1 AND partition_algo_version=1)) AS sf1_max_deg_raw;
```

Result: `mon_deg_raw = 23.7000`, `sf1_max_deg_raw = 37.1000`.
Computed: `23.7 / 37.1 = 0.638814...`.
Stored: `0.6388`. **Δ < 1e-4** (DECIMAL(5,4) rounding). ✓

## 8. pagerank — sub-fleet-normalized

Same SQL with `pagerank_raw` substituted.

Result: `mon_pr_raw = 0.668243`, `sf1_max_pr_raw = 0.957304`.
Computed: `0.668243 / 0.957304 = 0.698048...`.
Stored: `0.6980`. **Δ < 1e-4**. ✓

## 9. ship_class_category — hull lookup

```sql
SELECT category FROM ship_class_category_mapping WHERE ship_type_id = 45534;
```

Result: `command`.
Stored: `command`. ✓

## 10. is_in_subfleet_0 — `sub_fleet_id = 0` check

`sub_fleet_id = 1 ⇒ is_in_subfleet_0 = 0`.
Stored: `0`. ✓

## 11. subfleet_member_count — from sub-fleet header

```sql
SELECT member_count FROM battle_sub_fleets
 WHERE battle_id=40541 AND alliance_id=99011223 AND sub_fleet_id=1 AND partition_algo_version=1;
```

Result: `18`.
Stored: `18`. ✓

## 12. subfleet_damage_share_of_side — sub-fleet fraction of side damage

```sql
SELECT
  (SELECT COALESCE(SUM(a.damage_done),0) FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541
      AND a.character_id IN (SELECT character_id FROM battle_character_sub_fleet_membership
                              WHERE battle_id=40541 AND alliance_id=99011223
                                AND sub_fleet_id=1 AND partition_algo_version=1)) AS sf1_dmg,
  (SELECT COALESCE(SUM(a.damage_done),0) FROM battle_theater_killmails btk
     JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
    WHERE btk.theater_id=40541
      AND a.character_id IN (SELECT character_id FROM battle_character_sub_fleet_membership
                              WHERE battle_id=40541 AND alliance_id=99011223
                                AND partition_algo_version=1)) AS side_dmg;
```

Result: `sf1_dmg = 12395`, `side_dmg = 22771`.
Computed: `12395 / 22771 = 0.54432...`.
Stored: `0.5443`. **Δ < 1e-4**. ✓

## 13. subfleet_dominant_hull_class — plurality

```sql
SELECT COALESCE(sccm.category,'other') AS cat, COUNT(*) AS n
  FROM battle_character_sub_fleet_membership m
  JOIN (
      SELECT a.character_id, a.ship_type_id, COUNT(*) AS uses
        FROM battle_theater_killmails btk
        JOIN killmail_attackers a ON a.killmail_id=btk.killmail_id
       WHERE btk.theater_id=40541
         AND a.character_id IN (SELECT character_id FROM battle_character_sub_fleet_membership
                                 WHERE battle_id=40541 AND alliance_id=99011223
                                   AND sub_fleet_id=1 AND partition_algo_version=1)
         AND a.ship_type_id IS NOT NULL
       GROUP BY a.character_id, a.ship_type_id
  ) ps ON ps.character_id = m.character_id
  LEFT JOIN ship_class_category_mapping sccm ON sccm.ship_type_id = ps.ship_type_id
 WHERE m.battle_id=40541 AND m.alliance_id=99011223
   AND m.sub_fleet_id=1 AND m.partition_algo_version=1
 GROUP BY cat ORDER BY n DESC;
```

Result: `other = 15`, `tackle = 2`, `command = 1`.
Plurality = `other`.
Stored: `other`. ✓

## 14. subfleet_hull_class_concentration

Computed: `15 / 18 = 0.83333...`.
Stored: `0.8333`. **Δ < 1e-4**. ✓

## 15. subfleet_has_logi

Sub-fleet 1 category tally contains no `logi`.
Stored: `0`. ✓

## Verdict

All 15 features reconstruct correctly against raw inputs within
`DECIMAL(5,4)` storage precision. The extractor is emitting the
defined formulas.

## Finding: Monitor FCs have near-zero presence-feature signal

The Monitor pilot has `presence_span = 0`, `early_presence = 0`,
`late_presence = 0` because they appear on exactly one killmail record
in the entire theater. Monitor-class ships deal effectively no damage
and (being near-invulnerable) do not appear as victims, so they
rarely land on any killmail's attacker list.

Ranking the 18 sub-fleet-1 members by `degree_centrality` places the
Monitor at position 16/18 (bottom 15%), not in the top 20%. This is
a data reality — the Spec 2 graph is projected off killmails, and
Monitors are near-absent from that surface.

Implication for downstream role inference: a scorer that identifies
FCs from presence features or raw centrality will miss Monitor-class
FCs. A different signal (e.g. command-bonus radius membership, or an
explicit hull-class prior on Monitor → FC) is needed. This is out of
scope for Spec 4 compute; it belongs to Spec 5 scoring.
