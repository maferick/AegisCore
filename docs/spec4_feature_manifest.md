# Spec 4 Feature Manifest (v1, Scoped)

Authoritative list of every per-character feature written by the
`battle_features` worker into `battle_character_role_features`. If
this file and the extractor disagree, the extractor
(`python/battle_features/extract.py`) wins; this document is a
derivative contract meant for human readers and downstream
role-scoring authors.

**Non-claim.** These features are computed correctly against the
definitions below and the killmail/graph/partition inputs they read
from. They are **not calibrated**, **not claimed to be predictive of
role**, and have not been validated against ground-truth role labels.
Spec 5 is where calibration and role inference live; Spec 4's promise
is only "these numbers mean what the formula says they mean."

## Column mapping (Spec 1 names retained)

Spec 1 reserved `degree_centrality` and `pagerank` as column names.
v1 writes the sub-fleet-relative normalizations into those columns
rather than adding new `weighted_degree_within_sub_fleet` /
`pagerank_within_sub_fleet` columns. The names are aliases,
documented here and in `python/battle_features/README.md`.

| v1 feature name                     | Spec 1 column       |
|-------------------------------------|---------------------|
| weighted_degree_within_sub_fleet    | `degree_centrality` |
| pagerank_within_sub_fleet           | `pagerank`          |

## The 15 features

Every feature listed here is populated by v1. The extractor hardcodes
the count (`POPULATED_FEATURES_NORMAL = 15` /
`POPULATED_FEATURES_SMALL_TIER = 13`) in `extract.py` — the manifest
is not derived from schema.

Every range is 0..1 except where noted. Binary features are stored as
`0.0000` or `1.0000`. Booleans are `0` / `1` (TINYINT(1)).

### 1. `damage_share` — **sub-fleet relative**

```python
denom = sum(e.damage_done for e in attackers if membership(e.character_id).sub_fleet_id == this.sub_fleet_id)
damage_share = (sum(e.damage_done for e in attackers if e.character_id == this.character_id) / denom) if denom > 0 else 0.0
```

```sql
-- char dmg numerator
SELECT SUM(damage_done)
  FROM battle_theater_killmails btk
  JOIN killmail_attackers a ON a.killmail_id = btk.killmail_id
 WHERE btk.theater_id = :battle_id AND a.character_id = :character_id;
-- sub-fleet dmg denominator
SELECT SUM(damage_done)
  FROM battle_theater_killmails btk
  JOIN killmail_attackers a ON a.killmail_id = btk.killmail_id
 WHERE btk.theater_id = :battle_id
   AND a.character_id IN (SELECT character_id
                            FROM battle_character_sub_fleet_membership
                           WHERE battle_id = :battle_id AND alliance_id = :alliance_id
                             AND sub_fleet_id = :sub_fleet_id
                             AND partition_algo_version = :partition_algo_version);
```

Per-sub-fleet sum = 1.0 (or 0.0 if sub-fleet dealt no damage) within
`DECIMAL(5,4)` precision envelope (`N * 5e-5 + 1e-6`, where N is rows
in the sub-fleet).

### 2. `kill_participation_rate` — side scoped

```python
char_kms = set(e.killmail_id for e in attackers if e.character_id == this.character_id)
side_kms = set(e.killmail_id for e in attackers if e.character_id in membership_set)
kpr = len(char_kms) / len(side_kms) if side_kms else 0.0
```

```sql
-- numerator: distinct killmails the char was attacker on
SELECT COUNT(DISTINCT a.killmail_id)
  FROM battle_theater_killmails btk JOIN killmail_attackers a ON a.killmail_id = btk.killmail_id
 WHERE btk.theater_id = :battle_id AND a.character_id = :character_id;
-- denominator: distinct killmails involving any side member
SELECT COUNT(DISTINCT a.killmail_id)
  FROM battle_theater_killmails btk JOIN killmail_attackers a ON a.killmail_id = btk.killmail_id
 WHERE btk.theater_id = :battle_id
   AND a.character_id IN (SELECT character_id FROM battle_character_sub_fleet_membership
                           WHERE battle_id=:battle_id AND alliance_id=:alliance_id
                             AND partition_algo_version=:partition_algo_version);
```

### 3. `presence_span` — battle-span relative

```python
events = [e.killed_at for e in attackers if e.character_id == char] \
       + [v.killed_at for v in victims  if v.character_id == char]
presence_span = (max(events) - min(events)) / (battle_end - battle_start) if (events and battle_duration>0) else 0.0
```

Battle start/end = min/max `killed_at` over **all** theater killmails
(not just the side's).

### 4. `early_presence` — **binary**

```python
early_cutoff = battle_start + 0.20 * battle_duration
early_presence = 1.0 if min(char_event_timestamps) <= early_cutoff else 0.0
```

### 5. `late_presence` — **binary**

```python
late_cutoff = battle_start + 0.80 * battle_duration
late_presence = 1.0 if max(char_event_timestamps) >= late_cutoff else 0.0
```

### 6. `death_order_pct` — **sub-fleet relative**

```python
# Deaths of sub-fleet members only; sort (killed_at, killmail_id, character_id)
sub_fleet_deaths = sorted(v for v in victims if sub_fleet_of(v.character_id) == this.sub_fleet_id,
                          key=lambda v: (v.killed_at, v.killmail_id, v.character_id))
n = len(unique_characters_in(sub_fleet_deaths))
if n == 0:
    death_order_pct = 1.0         # sub-fleet with zero deaths
elif char died:
    rank = index_of_first_death(char, sub_fleet_deaths)
    death_order_pct = 0.0 if n == 1 else rank / (n - 1)
else:
    death_order_pct = 1.0         # survived
```

### 7. `degree_centrality` — sub-fleet-normalized

```python
denom = max(g.weighted_degree_raw for g in graph_metrics
            if sub_fleet_of(g.character_id) == this.sub_fleet_id and g.weighted_degree_raw is not None)
degree_centrality = (graph_metrics[char].weighted_degree_raw / denom) if denom > 0 else 0.0
```

Small-tier battle (every `skip_reason` non-null) → **NULL**.

### 8. `pagerank` — sub-fleet-normalized

Same formula as `degree_centrality` using `pagerank_raw`.
Small-tier → **NULL**.

### 9. `ship_class_category`

```python
primary_ship = mode(a.ship_type_id for a in attackers if a.character_id==char and a.ship_type_id is not None)
# ties broken by min(ship_type_id)
if primary_ship is None:
    ship_class_category = None   # no ship_type_id ever observed
else:
    ship_class_category = hull_map.get(primary_ship, 'other')
```

`'other'` is a first-class stored value meaning "hull known, outside
v1 scope." `NULL` is reserved for "no ship_type_id observed at all"
(rare edge case).

### 10. `is_in_subfleet_0` — binary

```python
is_in_subfleet_0 = 1 if sub_fleet_id == 0 else 0
```

Column is `TINYINT(1) NOT NULL` with **no default** so the extractor
cannot silently omit the write.

### 11. `subfleet_member_count`

Directly copied from `battle_sub_fleets.member_count` for the
sub-fleet.

### 12. `subfleet_damage_share_of_side`

```python
sub_dmg = sum(damage_done for attackers in sub-fleet members)
side_dmg = sum(damage_done for attackers in all side members)
subfleet_damage_share_of_side = sub_dmg / side_dmg if side_dmg > 0 else 0.0
```

Sum across a side's sub-fleets = 1.0 (within precision envelope) or
0.0 if the side did no damage.

### 13. `subfleet_dominant_hull_class`

```python
cats = [ship_class_category for member in sub-fleet if category is not None]
dominant = plurality(cats)    # tie-break: bomber < command < logi < mainline < tackle < other
```

NULL only if no member had a categorized primary hull.

### 14. `subfleet_hull_class_concentration`

```python
concentration = count(members with category == dominant) / subfleet_member_count
```

NULL iff `subfleet_dominant_hull_class` is NULL.

### 15. `subfleet_has_logi`

```python
subfleet_has_logi = 1 if any(member.ship_class_category == 'logi') else 0
```

## `feature_completeness`

```python
POPULATED_FEATURES_NORMAL      = 15
POPULATED_FEATURES_SMALL_TIER  = 13    # degree_centrality, pagerank absent

feature_completeness = POPULATED_FEATURES_SMALL_TIER / 15.0 if small_tier else POPULATED_FEATURES_NORMAL / 15.0
```

- Normal tier: 1.0000 for every row in the battle.
- Small tier: 0.8667 for every row in the battle.

Stdev across rows in a single battle is always 0.0 — verified by the
`feature_completeness stdev per battle` query in
`verification/spec4/semantic_checks.sql`.

## Columns v1 does NOT populate (deferred to future specs)

The migration relaxed these Spec 1 columns to nullable; v1 writes
them as explicit `NULL`:

- `primary_sub_fleet_share`  (deferred — fuzzy membership)
- `victim_overlap_density`, `same_bucket_cooccurrence`,
  `engagement_phase_count_norm` (deferred — co-occurrence features)
- `betweenness_centrality`, `clustering_coefficient`,
  `local_blob_score`, `support_ring_score`, `edge_cluster_score`
  (deferred — additional graph features)
- `logi_ring_affinity`, `fc_core_affinity` (deferred — role-affinity
  scores, live in Spec 5)
- `final_blow_rate`, `contributed_kill_rate`, `isk_killed_share`,
  `isk_lost_norm` (deferred — extended damage features)
- `target_spread`, `focus_fire_alignment` (deferred — targeting
  analysis)

`degree_centrality` and `pagerank` are populated in normal tier and
**NULL** in small tier (Spec 1's NOT NULL DEFAULT 0.0000 was relaxed
in migration `2026_04_18_020000_spec4_fix_pack.php`).

## Concurrency

Extractor holds three `GET_LOCK` keys, acquired in order and released
in reverse order on every exit path (success or failure):

- **graph_metrics_lock_key** — `bg_` + sha1 of
  `"battle_graph:{battle}:{alliance}:{edge_v}:{algo_v}"`. Shared with
  Spec 2 + Spec 3.
- **partition_lock_key** — `bp_` + sha1 of
  `"battle_partition:{battle}:{alliance}:{partition_v}"`. Shared
  with Spec 3.
- **feature_lock_key** — `bf_` + sha1 of the **same** hash input as
  the partition lock. Spec 4-specific; prevents two concurrent Spec 4
  runs on the same tuple.

Key formulas are in `python/battle_features/db.py`. They MUST stay
byte-identical to the producer modules; the comment there is the
enforcement because the three workers ship as separate Docker images.

## Verification discipline

`verification/spec4/` contains:

- `semantic_checks.sql` — bounds + consistency + per-battle checks
- `monitor_audit_40541.md` — one-pilot hand-reconstruction with
  stored-vs-computed deltas for all 15 features
- `run_batch.sh` — replay script for the 8 validation battles

Any change to `battle_features/` must keep the bounds queries
zero-violation and keep the Monitor audit within DECIMAL(5,4)
precision envelope. If the audit or bounds queries drift,
`battle_features/` must be reverted or the manifest updated
consistently — not silently accepted.
