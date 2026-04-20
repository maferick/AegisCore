# Find-the-spy + Neo4j upgrade plan (v2, 2026-04-20)

Owner: director / counter-intel team
Scope: decision latency on "is this pilot a spy?" and convert Neo4j from
passive graph store to active intel engine using the full stack
available: **GDS 2026.03 + APOC 425-proc core + APOC Extended (ML, JDBC,
triggers, import/export)**.

v1 of this doc (committed 81f73ce) under-counted the data we have and
ignored APOC entirely. v2 rebuilds against the real inventory.

---

## Part 0 — actual data inventory (what we have *right now*)

### Neo4j state

| Label | Count | Source |
|---|---|---|
| CICharacter | 204,211 | counter_intel projection |
| Character | 17,965 | battle_graph projection (separate!) |
| CIAlliance | 8,064 | old bloc-overlay in CI graph |
| System | 5,485 | universe sync |
| Station | 5,150 | universe sync |
| Alliance | 843 | bloc_intel projection (new today) |
| Constellation | 799 | universe sync |
| Region | 70 | universe sync |

| Edge | Count | Source |
|---|---|---|
| CI_SIMILAR_TO | 11.1M | counter_intel similarity |
| CO_ENGAGED | 7.26M | battle_graph |
| CI_CO_OCCURS_WITH | 3.83M | counter_intel |
| CI_MEMBER_OF | 471K | character↔alliance (CI side) |
| CI_FOUGHT_AGAINST | 163K | counter_intel |
| ALLIED_WITH | 12.8K | battle allegiance |
| OPPOSED | 9.4K | battle allegiance |
| JUMPS_TO | 6.98K | universe gate edges |
| ALLIANCE_RELATES_TO | 4,948 | bloc_intel (today) |

**Dupe alert**: `Character` (18K, battle_graph) and `CICharacter` (204K,
counter_intel) are the same real-world entity. Ditto `Alliance` vs
`CIAlliance`. Schema split = parallel subgraphs that don't query together.

### MariaDB — tables not yet in Neo4j but should be

| Table | Rows | What it'd become |
|---|---|---|
| `character_corporation_history` | 4.4M | `(c:Character)-[:IN_CORP {start, end}]->(:Corporation)` |
| `corporation_alliance_history` | 161K | `(:Corporation)-[:IN_ALLIANCE {start, end}]->(:Alliance)` |
| `alliance_leadership` | 1,616 | `Alliance` props + `(:Character)-[:FOUNDED]->(:Alliance)` |
| `corporation_fw_enlistment` | 4,632 | `Corporation` props (566 enlisted) |
| `ci_character_anomalies_rolling` | 37K | `CICharacter` props (score, band, bridge pct, etc.) |
| `ci_character_graph_features_rolling` | 51K | `CICharacter` props (ring_id, size, bridge_internal_pct) |
| `alliance_pair_behavior_rolling` | 10K | `ALLIANCE_RELATES_TO` props (already projecting) |
| `battle_character_role_inference` | 154K | per-battle `ROLE_IN` edges or aggregated `Character` props |
| `battle_theater_participants` | 7.47M | `(:Character)-[:FOUGHT_IN {side, role}]->(:Theater)` |
| `character_role_historical_priors` | 5.3K | `Character` props (`prior_fc`, `prior_logi`, etc.) |
| `ship_class_category_mapping` | 334 | `(:Hull)-[:IN_CLASS]->(:HullClass)` taxonomy |
| `coalition_entity_labels` | 62 | `Alliance` / `Corporation` `bloc_id` props |
| `coalition_blocs` | 6 | `(:Bloc)` nodes + `(:Alliance)-[:IN_BLOC]->(:Bloc)` |
| `system_sovereignty` | 8,878 | `(:System).sov_holder_id` + `(:Alliance)-[:HOLDS_SOV]->(:System)` |
| `ansiblex_jump_bridges` | 33 | `(:System)-[:ANSIBLEX_TO]->(:System)` |
| `system_titan_bridges` | 372K | `(:System)-[:TITAN_RANGE]->(:System)` |
| `auto_doctrine_pilots` | 432K | `(:Character)-[:FLIES_DOCTRINE {n_kills}]->(:Doctrine)` |

That's ~14M additional graph edges we could carry if we projected
everything. Current graph has ~22.7M edges. **Headroom is plenty** — Neo4j
5.x on a 64-GB host handles 100M+ edges cleanly.

### Available plugins (verified live)

- **APOC** — 425 procedures. Key ones we aren't using:
  - `apoc.load.jdbc` / `apoc.load.jdbcUpdate` — direct MariaDB→Neo4j in
    Cypher, replaces Python ETL for simple sync paths.
  - `apoc.periodic.iterate` — batched property writes, essential for
    the 7.47M participants projection.
  - `apoc.trigger.*` — reactive graph updates; could drive live sync
    from outbox events.
  - `apoc.export.graphml` / `apoc.import.graphml` — dev / snapshot /
    portable query packs.
  - `apoc.ml.bedrock.*` — AWS Bedrock chat / completion / embedding
    callable from Cypher. GraphRAG becomes one-liner procedures.
  - `apoc.ml.cypher` / `apoc.ml.fromCypher` — LLM→Cypher and back.
  - `apoc.text.sorensenDiceSimilarity` / `apoc.coll.intersection` —
    lightweight in-query similarity helpers.
- **GDS 2026.03** — Filtered KNN, FastRP, HashGNN, Node2Vec, Leiden,
  Louvain, link-prediction pipelines, node-classification pipelines.

---

## Part 1 — UX audit (condensed — unchanged since v1)

### Friction (ranked by blast radius)

1. No side-by-side pilot compare → mental math across dossier tabs.
2. Dossier = prose, no 1-line verdict or suggested next action.
3. Band buckets hundreds of pilots with no within-band rank.
4. No watchlist / persistent case file.
5. `cohort_confidence` never explained.
6. `AllianceLookup` has no anomaly badges in the command layer.
7. Flight-crew / arch-enemies partners aren't anomaly-coloured.
8. Timelines flat — no coordinated-join / bloc-flip overlay.
9. Typical "suspicion → verdict" is 3-4 clicks + synthesis.
10. `CI_SIMILAR_TO` 11M edges never surfaced in UI.

### Missing features — every one renderable from data already on hand

Details in v1. Summary: triage card, cohort baseline chart, temporal
anomaly flag, watchlist + notes + CSV export, side-by-side dossier,
AllianceLookup anomaly badges, ring-expand, mini co-flight graph,
coordinated-join timeline, ring-stability badge.

---

## Part 2 — revised Neo4j plan, exploiting APOC + GDS

### Phase 0 — schema hygiene (1-2 days, blocker)

**0.1. Merge dupe labels.**
`Character` = `CICharacter`. `Alliance` = `CIAlliance`. Decide canonical
names and rewrite projection code:
- Keep `Character` (shorter, matches the battle-graph convention).
- Keep `Alliance` (shorter, matches bloc_intel convention).
- Drop `CICharacter` / `CIAlliance` labels — retire after one-pass
  relabel via `MATCH (n:CICharacter) SET n:Character REMOVE n:CICharacter`.
- Counter-intel subgraph gets `:Internal` secondary label to mark the
  current viewer-bloc-scoped subset.

**0.2. Introduce `:Corporation`.**
Right now corp info is inline in `Character` properties. Project
`corporation_alliance_history` and `character_corporation_history` as
edges on a `:Corporation` node. Cost: ~161K Corp nodes + 4.4M `IN_CORP`
edges. Enables path queries through the corp layer.

**0.3. Bloc nodes.**
6 rows in `coalition_blocs` → 6 `:Bloc` nodes + `(:Alliance)-[:IN_BLOC]->(:Bloc)`
edges derived from `coalition_entity_labels`. Enables
`MATCH (a)-[:IN_BLOC]->(b)-[r:ALLIANCE_RELATES_TO]-(b2:Bloc)` graph-level
bloc-vs-bloc queries.

### Phase A — APOC-driven live sync (3-5 days)

**A1. Direct JDBC sync for stable-ish tables.**
Replace bespoke Python projectors with `apoc.load.jdbc` for tables that
don't need enrichment:
```cypher
CALL apoc.load.jdbc(
  'jdbc:mariadb://mariadb:3306/aegiscore',
  "SELECT corporation_id, faction_id, is_enlisted, kills_total
     FROM corporation_fw_enlistment WHERE is_enlisted = 1"
) YIELD row
MATCH (c:Corporation {id: row.corporation_id})
SET c.fw_faction_id = row.faction_id,
    c.fw_is_enlisted = row.is_enlisted = 1,
    c.fw_kills_total = row.kills_total;
```
Eliminates a worker for FW enlistment + alliance leadership + role
priors + ship class mapping.

**A2. Periodic.iterate for the 7.47M participant projection.**
Project battle_theater_participants as FOUGHT_IN edges. One-time load:
```cypher
CALL apoc.periodic.iterate(
  "CALL apoc.load.jdbc('...', 'SELECT character_id, theater_id, alliance_id FROM battle_theater_participants') YIELD row RETURN row",
  "MATCH (c:Character {id: row.character_id})
   MATCH (t:Theater {id: row.theater_id})
   MERGE (c)-[:FOUGHT_IN {alliance_id: row.alliance_id}]->(t)",
  {batchSize: 10000, parallel: false}
);
```
Running one-shot lets us do queries like "pilots who fought in ≥ N
theaters with Fraternity on opposing side" — a pure spy signal.

**A3. APOC triggers on Character properties.**
When `ci_character_anomalies_rolling.review_priority_score` changes in
MariaDB, the outbox relays an event. Neo4j trigger reads the outbox and
updates `Character.score` + `Character.band` in place, instead of
waiting for the next full sweep. Cuts projection lag from ~24 h to
~30 s.

**A4. Index + PROFILE audit.**
Every hot read Cypher should hit an index. Create:
```cypher
CREATE INDEX character_band_score IF NOT EXISTS
  FOR (c:Character) ON (c.viewer_bloc_id, c.review_priority_band, c.score);
CREATE INDEX character_ring IF NOT EXISTS
  FOR (c:Character) ON (c.ring_id);
CREATE INDEX alliance_bloc IF NOT EXISTS
  FOR (a:Alliance) ON (a.bloc_id);
```
`PROFILE` every operator-facing query. Target p95 < 200 ms on 500K
nodes. Document results in `docs/neo4j-queries.md`.

### Phase B — signal enrichment (1 week)

**B1. Project all MariaDB anomaly signals as node props.**
Flatten `ci_character_anomalies_rolling` and `ci_character_graph_features_rolling`
into `Character` node properties (score, band, cohort_confidence,
ring_id, ring_size, bridge_internal_pct, seed_neighbors_max). No new
storage, faster queries — don't need to cross-reference MariaDB from
the graph.

**B2. Hull + role taxonomy.**
Add `(:HullClass)` nodes from `ship_class_category_mapping`. Each
killmail contributes `(c:Character)-[:FLEW {n_km, total_damage}]->(:Hull)`
edges, `Hull -> HullClass`. Enables role-aware similarity:
"pilots whose *hull composition* matches X's" rather than just
"pilots who shared killmails with X".

**B3. Doctrine layer.**
Project `auto_doctrines` + `auto_doctrine_pilots` as `(:Doctrine)` nodes
with `FLIES_DOCTRINE` edges. Now you can ask: "pilots flying doctrines
adopted primarily by Imperium alliances but who are in our bloc" —
direct defection signal.

**B4. Spatial bridge edges.**
Add `(:System)-[:ANSIBLEX_TO]->(:System)` (33 edges) and
`(:System)-[:TITAN_RANGE {distance_ly}]->(:System)` (372K). Sov
ownership: `(:System).sov_holder_id`. Enables: "shortest time from
seed pilot's home system to target" and "pilots who jump-bridge
through hostile sov weekly".

### Phase C — GDS ML pipelines (2-3 weeks)

**C1. Filtered KNN replaces Jaccard.**
Swap the current `counter_intel.similarity` (whole-graph Jaccard +
thresholding) for `gds.knn.filtered.mutate`:
```cypher
CALL gds.knn.filtered.mutate('ci_internal', {
  nodeProperties: ['score', 'ring_id', 'bridge_internal_pct'],
  topK: 20,
  sourceNodeFilter: 'Internal',
  targetNodeFilter: 'Internal',
  mutateRelationshipType: 'SIMILAR_TO_V2',
  mutateProperty: 'score'
});
```
Scales better, lets us filter the candidate pool, produces ranked
top-K instead of a thresholded firehose.

**C2. FastRP embeddings** (cheaper + faster than Node2Vec at 200K
nodes).
```cypher
CALL gds.fastRP.mutate('ci_internal', {
  embeddingDimension: 128,
  relationshipWeightProperty: 'event_count',
  iterationWeights: [0.0, 1.0, 1.0, 0.8],
  propertyRatio: 0.5,
  featureProperties: ['score', 'bridge_internal_pct', 'ring_size'],
  mutateProperty: 'embedding'
});
```
`propertyRatio: 0.5` is the HashGNN-style trick to fold node property
signal into the embedding — critical because pure-topology embeddings
miss the hostile-history signal.

**C3. HashGNN for mixed-feature embeddings.**
Alternative to FastRP when we want deeper feature mixing
(`gds.hashgnn.mutate`). Use whichever calibrates better on the
operator-confirmed ground-truth (~50 confirmed spy / clean pairs is
enough to compare).

**C4. Node-classification pipeline.**
Binary classifier: "internal pilot, is_confirmed_spy". Features:
embedding + anomaly scores + ring props. Train nightly, store
`Character.spy_probability` (0-1). Display alongside the rule-based
band — agreement = strong verdict, disagreement = interesting review.

**C5. Link-prediction pipeline.**
Predict future `OPPOSED` / `ALLIED_WITH` alliance-pair edges. Early-
warning bloc-realignment detector. Output: "alliances likely to
become hostile to us in the next 30 days" list on admin dashboard.

### Phase D — GraphRAG via APOC ML (2-3 weeks)

**D1. Cypher-from-prompt via `apoc.ml.cypher`.**
Operator types natural-language question, Bedrock generates Cypher
bound to our schema, we execute, Bedrock summarizes. Schema snippet
passed in context so the LLM can't hallucinate fields.

Example path:
```cypher
CALL apoc.ml.fromCypher.stream(
  {prompt: 'Show pilots in my bloc who recently co-flew with Fraternity members and have rising anomaly scores'},
  { schema: $schemaText, apiKey: $bedrockKey }
) YIELD query, explanation
// operator approves
CALL apoc.cypher.run(query, {}) YIELD value RETURN value;
```

**D2. Dossier explainer.**
One dossier button: "explain this in a paragraph". Backend runs
`apoc.ml.bedrock.chat` with the Character node's full property bag +
the top-5 neighbours from `SIMILAR_TO_V2` as context, gets back
"Character X has a 0.78 spy_probability. Key drivers: their alliance
flipped hostile 90d ago; they retained their corp rather than
following their former allies; they co-fly a Leshak doctrine that's
87% Imperium-adopted."

Cost: ~1 Bedrock call per dossier view, ~2 KB context each. Effectively
free at review-queue volumes.

**D3. Guardrails.**
Every generated Cypher runs through a procedure whitelist and a
node-count ceiling (abort if `rows_returned > 5000`). No LLM-
generated mutations — read queries only. Schema passed in system
prompt each call, no ad-hoc "invent a field" failures.

### Phase E — UX delivery (parallelisable from week 1)

Do these as soon as the underlying data is a node property (most
after Phase B1):

| # | Feature | Depends on |
|---|---|---|
| E1 | Triage card row (1-line verdict) | Phase B1 |
| E2 | AllianceLookup anomaly badges | Phase B1 |
| E3 | Watchlist + notes (new MariaDB table) | standalone |
| E4 | Side-by-side dossier `?compare=` | standalone |
| E5 | Cohort baseline p5/p50/p95 chart | standalone (MariaDB aggregate) |
| E6 | Ring-expand view | Phase 0.1 + B1 |
| E7 | Temporal join-overlay | Phase A2 (FOUGHT_IN) |
| E8 | Mini co-flight graph on dossier | standalone |
| E9 | "Coordinated-join with whom" list | Phase A2 |
| E10 | Watchlist CSV / PDF export | E3 |
| E11 | Dossier "explain in a paragraph" | Phase D2 |
| E12 | NL query bar "show me X" | Phase D1 |

### Phase F — observability + ops (ongoing)

- Neo4j metrics → Prometheus → Grafana board.
- `neo4j-admin database memory-recommendation` run whenever graph
  size doubles; tune heap + page cache.
- Named projections refreshed on schedule (1h CI, 6h alliance, daily
  FOUGHT_IN), tracked in a `graph_projection_runs` table with hash of
  underlying source counts so replays detect drift.
- `PROFILE` snapshots checked into `docs/neo4j-queries.md`.
- Alert: page-cache hit-ratio < 90%, bolt p95 > 1 s, GDS run > 5×
  baseline.

---

## Part 3 — sequencing (what to start Monday)

1. **Week 1** — Phase 0 (schema hygiene, can't skip), Phase A1–A4
   (APOC JDBC replacing projectors + trigger on anomaly changes +
   index audit), UX items E1, E2 (quick cards + badges).
2. **Week 2** — Phase B1–B3 (flatten anomalies + hull class + doctrine
   layer), UX E3 (watchlist), E4 (compare), E5 (cohort chart).
3. **Week 3** — Phase B4 (spatial bridges), E6 (ring expand), E7
   (temporal join overlay), E8–E10 (mini graph + coordinated-join +
   export).
4. **Week 4-6** — Phase C (Filtered KNN → FastRP → node-classification),
   calibration loop starts.
5. **Week 7-9** — Phase D (GraphRAG), E11–E12.
6. **Ongoing** — Phase F.

Total ~9 weeks to 80% of the vision. Phases B + C unlock the biggest
analytical step-change; Phase D unlocks the operator-facing delight.

---

## Part 4 — anti-patterns (updated)

- No unprofiled Cypher in hot paths. Every read-query on the review
  queue PROFILEd before merge.
- No LLM verdicts on the review queue. LLMs only in explainer + NL-
  query surfaces. Scoring stays explainable rule + GDS model.
- No Python projectors for tables APOC JDBC can sync directly — the
  worker overhead isn't worth the indirection.
- No "Character vs CICharacter" schema split post-Phase 0 — any code
  creating either should be rejected.
- No ad-hoc trigger logic — `apoc.trigger` uses one shared signal
  (outbox row exists) and writes via the schema.

---

## Part 5 — ground-truth plan (preq for Phase C)

GDS classification needs labels. Build a tiny labeling surface under
`/admin/counter-intel/{id}/label`:
- Buttons: "confirmed spy", "confirmed clean", "undecided".
- Backed by `ci_character_ground_truth` table: `character_id`,
  `label`, `labelled_by`, `labelled_at`, `reason`.
- Counter-intel directors use it while triaging; 100 labels is
  enough to start a model; 500 is enough to calibrate.
- Export labels as `Character.ground_truth_label` node property for
  the node-classification training graph.

Ship with Phase A so labels start accumulating before we need them.

---

## Anchor references

- [Neo4j GDS Leiden algorithm](https://neo4j.com/docs/graph-data-science/current/algorithms/leiden/)
- [Filtered K-Nearest Neighbors](https://neo4j.com/docs/graph-data-science/current/algorithms/filtered-knn/)
- [FastRP embeddings](https://neo4j.com/docs/graph-data-science/current/machine-learning/node-embeddings/fastrp/)
- [HashGNN embeddings](https://neo4j.com/docs/graph-data-science/current/machine-learning/node-embeddings/hashgnn/)
- [Node classification pipelines](https://neo4j.com/docs/graph-data-science/current/machine-learning/node-property-prediction/nodeclassification-pipelines/node-classification/)
- [Link prediction pipelines](https://neo4j.com/docs/graph-data-science/current/machine-learning/linkprediction-pipelines/link-prediction/)
- [APOC `apoc.load.jdbc`](https://neo4j.com/labs/apoc/current/database-integration/load-jdbc/)
- [APOC `apoc.periodic.iterate`](https://neo4j.com/labs/apoc/current/overview/apoc.periodic/apoc.periodic.iterate/)
- [APOC triggers](https://neo4j.com/labs/apoc/current/background-operations/triggers/)
- [APOC Extended `apoc.ml.bedrock.*`](https://neo4j.com/labs/apoc/current/ml/bedrock/)
- [APOC `apoc.ml.cypher`](https://neo4j.com/labs/apoc/current/ml/openai/)
- [Cypher query tuning](https://neo4j.com/docs/cypher-manual/current/planning-and-tuning/query-tuning/)
- [Temporal graph fraud modelling](https://neo4j.com/blog/developer/mastering-fraud-detection-temporal-graph/)
- [Cybersecurity threat hunting on Neo4j (arxiv 2301.12013)](https://arxiv.org/abs/2301.12013)
