# DB Storage Efficiency Audit — v1 freeze blocker

Snapshot: 2026-04-27.

Aegiscore data-dir: ~651 GB on disk. Undo tablespaces: ~106 GB.
This audit quantifies where the bytes live, identifies
duplication / over-indexing / over-retention, and stages a
remediation plan. **No destructive action taken yet.**

---

## DB-1 — Size breakdown

### Top tables by total size (data + index)

| table                              |       rows | data GB | idx GB | total GB | avg row B | idx/data |
|------------------------------------|-----------:|--------:|-------:|---------:|----------:|---------:|
| **market_orders**                  |  939,285,999 | 180.72 | 275.23 | **455.95** | 207 | 1.52 |
| **battle_character_role_scores**   |  121,670,714 |  17.72 |  55.59 |  **73.31** | 156 | **3.14** |
| **killmail_items**                 |  125,649,912 |  21.90 |   9.02 |   30.92 | 187 | 0.41 |
| **killmail_pilot_role**            |   25,855,981 |   6.55 |   6.50 |   13.06 | 272 | 0.99 |
| **killmail_attackers**             |   35,833,445 |   2.97 |   4.19 |    7.16 |  89 | 1.41 |
| **outbox**                         |    7,080,327 |   3.75 |   0.58 |    4.33 | 568 | 0.16 |
| market_history                     |   23,273,103 |   2.50 |   1.19 |    3.70 | 116 | 0.48 |
| battle_theater_participants        |    7,734,090 |   0.95 |   2.51 |    3.46 | 132 | 2.63 |
| killmails                          |    7,687,259 |   1.76 |   1.19 |    2.95 | 246 | 0.68 |
| battle_character_role_features     |    4,216,143 |   0.97 |   1.37 |    2.33 | 246 | 1.41 |
| battle_character_role_inference    |    3,233,679 |   0.68 |   1.61 |    2.29 | 227 | **2.35** |
| battle_character_sub_fleet_membership | 4,611,252 |   0.70 |   1.37 |    2.06 | 162 | 1.96 |
| battle_character_graph_metrics     |    3,761,346 |   0.79 |   1.09 |    1.88 | 224 | 1.39 |

5 tables (market_orders, battle_character_role_scores,
killmail_items, killmail_pilot_role, killmail_attackers,
outbox) account for ~585 GB / ~90% of the database footprint.

### Tables with index oversizing (idx/data > 1.0, total > 1 GB)

| table                              | data GB | idx GB | idx/data |
|------------------------------------|--------:|-------:|---------:|
| battle_character_role_scores       |  17.72  |  55.59 | **3.14** |
| battle_character_role_inference    |   0.68  |   1.61 | 2.35     |
| battle_theater_participants        |   0.95  |   2.51 | 2.63     |
| market_orders                      | 180.72  | 275.23 | 1.52     |
| killmail_attackers                 |   2.97  |   4.19 | 1.41     |

### Per-index breakdown — battle_character_role_scores (73 GB)

| index                                   | cols                                                                                  | size GB |
|-----------------------------------------|---------------------------------------------------------------------------------------|--------:|
| PRIMARY                                 | battle_id, alliance_id, sub_fleet_id, character_id, partition_algo_version, weight_version, role_key, score_class | 17.72 |
| idx_bcrs_role_class                     | role_key, score_class, weight_version                                                 | 12.36 |
| idx_bcrs_character_version              | battle_id, alliance_id, character_id, weight_version                                  | 10.86 |
| **fk_bcrs_subfleet**                    | battle_id, alliance_id, sub_fleet_id, partition_algo_version                          | **10.79 redundant prefix of PK** |
| **idx_bcrs_battle_side_subfleet_version** | battle_id, alliance_id, sub_fleet_id, weight_version                                | **10.79 large overlap with PK** |
| fk_bcrs_weight_version                  | weight_version                                                                        | 10.79 |

Three indexes redundantly cover battle_id+alliance_id+sub_fleet_id
prefixes already in PRIMARY. ~21 GB recovery target.

### Per-index breakdown — killmail_items (31 GB)

| index                          | cols                          | size GB |
|--------------------------------|-------------------------------|--------:|
| PRIMARY                        | id                            | 21.90   |
| idx_km_items_killmail_slot     | killmail_id, slot_category    |  4.51   |
| **idx_km_items_killmail**      | killmail_id                   | **2.44 redundant — covered by killmail_slot prefix** |
| idx_km_items_type              | type_id                       |  2.07   |

### Duplicate index candidates (sample — full list ~30+)

`battle_character_role_features`: fk_bcrf_membership + fk_bcrf_subfleet
+ idx_bcrf_battle_character + idx_bcrf_battle_side_subfleet — five
indexes with overlapping prefixes on the same composite key chain.

`alliance_operational_profiles`: idx_aop_director + uniq_aop both
lead with viewer_bloc_id+window_end.

`battle_character_graph_metrics`: idx_bcgm_battle_side overlaps PK
+ idx_bcgm_community.

These need column-by-column analysis before drop, but the bulk
of bloat is on `battle_character_role_*` family.

---

## DB-2 — Duplication audit

### Killmail domain

- `killmails` (8 M rows, 3 GB) — canonical row per killmail.
- `killmail_items` (126 M, 31 GB) — fitting items per killmail.
  Avg ~16 items per killmail. **Row count + idx/data 0.41 looks
  healthy; bloat is purely volume.**
- `killmail_attackers` (36 M, 7 GB) — avg ~5 attackers/kill.
  4 single-column secondary indexes (char/corp/alliance/ship) +
  killmail_id index. Real workload need to be confirmed before
  any drop.
- `killmail_pilot_role` (26 M, 13 GB) — per (killmail, character).
  Cheap join on hull→role, kept small. Likely fine.
- **No JSON evidence duplication found between killmail tables**:
  enrichment writes scalar columns, no raw payload mirrors.

### EVE log domain

- `eve_log_events` (216 K rows, 0.12 GB) — **already manageable**.
  Phase 4.9C retention will keep it bounded at 90 days.
- `eve_log_parse_errors` (115 K, 0.04 GB) — fine.
- `eve_log_entity_resolutions` (57 K, 0.03 GB) — fine.
- `operational_*` tables — sub-GB each. None of them store raw
  JSONL chunks; they reference parent IDs.
- **No raw-chunk → events double-storage confirmed.**

### Dscan domain

- `eve_log_dscan_snapshots` (432 rows, 0.13 MB) — already
  swept by Phase 4.9C (241 stale rows dropped today).
- Parsed ship_types_json kept inline, ~50-200 KB per snapshot.
  No raw HTML retained.
- **Cluster/incident JSON columns reference snapshot_ids, do
  not embed snapshot bodies.** No duplication.

### Operational intelligence

- `operational_incidents` (9 K, 7 MB) — `evidence_json` typically
  100-300 bytes (cluster_ids + signal_types + qualities). Cheap.
- `incident_narratives` (300, ~100 KB) — small.
- `intel_export_artifacts` (1, 64 KB) — small now; will grow.
  body_md + body_json both stored — **export does duplicate
  payload across formats**, but each artifact is bounded by
  expires_at + 30-day TTL.
- **No find: large-text snapshot fields duplicating source rows.**

**Conclusion:** the only meaningful duplication is in
`battle_character_role_*` index space (Phase 1 above) and
`outbox.payload` backlog (Phase DB-3 below).

---

## DB-3 — Large JSON / TEXT column audit

### Confirmed bloat columns

| table                            | column                | data type   | est. impact |
|----------------------------------|-----------------------|-------------|-------------|
| **outbox**                       | payload               | longtext    | **3.75 GB / 7.08 M rows / 568 B avg**. 8.12 M rows currently UNPROCESSED — relay was down 14h, backlog accumulated. Fixed today (relay restarted), should drain. |
| outbox                           | last_error            | text        | rare; small |
| battle_character_role_inference  | role_reason_json      | longtext    | 0.68 GB / 3.23 M rows / ~227 B avg. Operationally needed for scoring transparency. |
| ref_item_types                   | data                  | longtext    | 0.24 GB / 38.7 K rows / 6.6 KB avg. Owned by SDE importer. Needed for full type metadata. |
| ref_moons                        | data                  | longtext    | 0.24 GB / 327 K rows / 777 B avg. SDE-owned; rarely changes. |
| auto_doctrine_modules            | variants_json         | longtext    | 0.16 GB / 1.12 M rows. Doctrine learner output. Hot. |

### Recommendations per column

| column | recommendation |
|--------|----------------|
| outbox.payload | **drain backlog ASAP** (8M unprocessed). Already-restarted relay should consume at ~50/sec; 8M rows = ~44 hours full drain. Once drained, payload TTL: keep 7 days post-process for replay/debug, then NULL out. Already TTL'd at 30 days by retention sweep but row stays. |
| outbox.last_error | keep, small |
| battle_character_role_inference.role_reason_json | keep — used in scoring dossier |
| ref_item_types.data | keep — SDE source of truth for type metadata. Does NOT need partitioning. |
| ref_moons.data | keep — same reasoning |
| auto_doctrine_modules.variants_json | keep — hot doctrine learner output |

No long-term cold storage migration recommended for v1; the
JSON bloat is overwhelmingly the outbox backlog, which clears
on its own once the relay catches up.

---

## DB-4 — Index audit

### High-confidence drop candidates (post-verification)

| table                              | index                                  | reclaim GB | reason |
|------------------------------------|----------------------------------------|-----------:|--------|
| battle_character_role_scores       | fk_bcrs_subfleet                       | ~10.8      | full prefix of PRIMARY (battle_id, alliance_id, sub_fleet_id, partition_algo_version) |
| battle_character_role_scores       | idx_bcrs_battle_side_subfleet_version  | ~10.8      | overlaps PRIMARY on first 4 columns; weight_version not selective enough to justify alone |
| killmail_items                     | idx_km_items_killmail                  | ~2.4       | covered by idx_km_items_killmail_slot prefix |

**Estimated reclaim from these 3 drops alone: ~24 GB.**

### Soft candidates (need more analysis)

- `battle_character_role_features` index family — same multi-overlap
  pattern as scores. Need EXPLAIN of the actual scoring queries
  before dropping anything.
- `killmail_attackers` 5 secondary indexes — each used by a
  different query path (alliance lookup, char dossier, ship
  popularity). Drop any → regression risk.
- `alliance_operational_profiles.idx_aop_director` — was added by
  Phase 4.9B audit; uniq_aop covers same prefix. Could be
  consolidated.

### No drops without prior EXPLAIN against current dashboard
queries. Stage 3 in the remediation plan covers this.

### Missing-index / low-cardinality findings

None found requiring attention. All hot dashboard queries verified
against indexes in the Phase 4.9B materialization audit.

---

## DB-5 — Retention model proposal

Add to Phase 4.9C TTL ladder (post-audit):

### Hot (daily-use)

- recent killmails (≤ 90 days) — already kept
- recent eve_log_events (≤ 90 days) — TTL configured
- recent dscan parsed summaries (≤ 60 days) — TTL configured
- operational_incidents / clusters (recent 90 days) — full data

### Warm (weekly-use)

- aggregated incidents 90-365 days — keep but consider
  compressing evidence_json
- system_operational_activity — TTL'd 180 days
- threat surface snapshots — TTL'd 90 days
- daily digests — TTL'd 90 days

### Cold (audit-use)

- raw eve_log_events past 90 days — drop
- killmail_items > 365 days — propose archive or partition drop
- battle_theater data > 365 days — propose archive (large)
- intel_export_artifacts past expires_at — sweep ASAP

### Specific new retention proposals (requires user OK before
add to phase49c_retention.RETENTION):

| table                              | proposed TTL | risk                                      |
|------------------------------------|-------------:|-------------------------------------------|
| outbox (processed=1)               |        7 d   | rep relay replay window                   |
| killmail_items                     |     1095 d   | 3y keeps long-form retros, drops bulk     |
| killmail_attackers                 |     1095 d   | same                                      |
| killmail_pilot_role                |     1095 d   | recomputable from killmails               |
| battle_theater_participants        |     1095 d   | 3y; we keep battle_theaters longer        |
| battle_character_role_*            |     365 d    | recomputable; keep long-form aggregates   |
| market_orders (per-region partitions) | varies | partitioning, not row TTL |

**market_orders is the biggest target — 456 GB.** v1
recommendation is **range partitioning by month** (already
PARTITION-ready: PRIMARY KEY leads with observed_at). Drop
partitions older than 90 days. ESI history is recoverable;
local snapshots before that are noise.

---

## DB-6 — Undo / purge health

```
Innodb_history_list_length: 588,168
long-running transactions (>30min): 0
```

History list of 588K is **elevated but not catastrophic** —
typical alert threshold is 1 M+. No long-running transactions
holding read views. Likely cause: heavy bulk DELETE/UPDATE
volume on `outbox` (8 M rows pending) + `killmail_items` ingest
churn.

Action: **no manual undo intervention.** Once outbox drains and
killmail backfill stabilises, history list should shrink
naturally. If it climbs past 1 M without query pressure, that's
when to investigate. Document in RUNBOOK.

`SHOW ENGINE INNODB STATUS\G` (full output not embedded — too
large; operator can run on demand). No transient OOM warnings,
no LATEST DETECTED DEADLOCK in the last hour.

### market_history partitions
Already RANGE-partitioned by trade_date, monthly. Each ~150 MB.
Healthy.

### market_orders partitions
Some empty p2025_01 / p2025_02 / p2025_03 partitions visible.
Older partitions (pre-2025) likely already purged. No active
partition rotation cron — recommended add (Stage 1).

---

## DB-7 — Staged remediation plan

### Stage 1 — safe wins (~12-50 GB recovery)

| action                                                              | reclaim    | risk | rollback                                       |
|---------------------------------------------------------------------|-----------:|------|------------------------------------------------|
| **drain outbox backlog** — relay restarted, monitor                 | ~3.5 GB    | none | n/a — relay already running                    |
| **install retention sweep cron** (15 4 * * *)                       | trickle    | none | remove cron line                               |
| **drop expired intel_export_artifacts** (already TTL'd; ensure sweep running) | trickle    | none | restore from backup                            |
| **collapse outbox processed older than 7d**                         | small      | low  | restore from backup                            |
| **add market_orders monthly partition rotation cron** (drop > 90 d) | **30-100 GB at first run** | medium | partitioning is non-destructive; rotation drops oldest only |

Stage 1 SQL drafts:

```sql
-- once outbox drained, drop processed > 7 days
DELETE FROM outbox WHERE processed_at IS NOT NULL AND processed_at < NOW() - INTERVAL 7 DAY LIMIT 10000;
-- repeat in batches

-- add to phase49c_retention.RETENTION:
("outbox", "processed_at", 7, "processed_at IS NOT NULL", 5000),
```

### Stage 2 — medium-risk (~24 GB index recovery)

| action                                                          | reclaim | risk | rollback                  |
|-----------------------------------------------------------------|--------:|------|---------------------------|
| EXPLAIN scoring queries against battle_character_role_scores    | n/a    | none | n/a                       |
| drop battle_character_role_scores.fk_bcrs_subfleet              | ~10.8  | low  | re-add via ALTER TABLE    |
| drop battle_character_role_scores.idx_bcrs_battle_side_subfleet_version | ~10.8 | low | re-add via ALTER TABLE  |
| drop killmail_items.idx_km_items_killmail                       | ~2.4   | low  | re-add via ALTER TABLE    |
| OPTIMIZE TABLE battle_character_role_scores after drops         | rebuild compaction | medium-blocking | run during low-traffic window |

```sql
-- DO NOT RUN UNTIL EXPLAIN VERIFIED:
ALTER TABLE battle_character_role_scores DROP INDEX fk_bcrs_subfleet;
ALTER TABLE battle_character_role_scores DROP INDEX idx_bcrs_battle_side_subfleet_version;
ALTER TABLE killmail_items DROP INDEX idx_km_items_killmail;
```

### Stage 3 — high-risk (~100-300 GB reclaim, requires
downtime planning)

| action                                                | reclaim     | risk | rollback                   |
|-------------------------------------------------------|------------:|------|----------------------------|
| range-partition + 90-day rotate market_orders         | **180-300 GB** | high | partitioning ALTER takes hours; rollback requires re-import |
| consolidate redundant battle_character_role_features indexes | ~2-5 GB | medium | re-add indexes if regression |
| OPTIMIZE TABLE outbox after backlog drains            | ~2 GB       | medium | locks table; do during scheduler down |
| archive battle_theater_participants > 365 days        | ~1 GB       | low  | restore from backup         |
| killmail_items + killmail_attackers > 1095 days TTL   | varies      | low  | restore from backup         |

---

## Open follow-ups for v1 closure

- **outbox drain status**: monitor over next 48 h. Goal: <10 K
  unprocessed.
- **history list length**: re-check after outbox drains. Goal: <100 K.
- **retention cron install** (operator action; documented in
  RUNBOOK + RETENTION).
- **market_orders partition rotation cron** — propose new
  Phase 4.9C extension to drop partitions; do not execute
  without analyst sign-off given the 100+ GB blast radius.
- **Stage 2 EXPLAIN verification** against current scoring
  workload before any index drop.

## Audit summary

- 5 tables = 90% of footprint. **market_orders + battle scoring
  + killmail_items dominate**.
- **No raw payload duplication** between log/dscan/operational
  domains. Materialised tables reference parent IDs cleanly.
- **24 GB recoverable from 3 redundant indexes** — verify with
  EXPLAIN, then drop.
- **30-300 GB recoverable from market_orders partition rotation**
  — biggest v1 win, but biggest risk; propose monthly partition
  drop > 90 days as the next staged change.
- **Outbox backlog (8M rows)** — operational, not architectural.
  Drains naturally now relay is running.
- **History list 588K** — elevated but not actionable. Will
  shrink as outbox drains.

No destructive action taken. All findings + recommendations.
