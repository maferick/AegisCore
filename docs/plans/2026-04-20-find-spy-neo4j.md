# Find-the-spy + Neo4j upgrade plan (2026-04-20)

Owner: director / counter-intel team
Focus: decision latency on the "is this pilot a spy?" call and make Neo4j
carry more of the weight instead of just being a graph store.

---

## Part 1 — UX audit summary (from portal walk-through)

### Current state

Counter-intel surfaces data across three places:

1. **/admin/counter-intel review queue** — 100-char priority list sorted by
   `review_priority_band` + 10 metric columns. Click through to dossier.
2. **/admin/counter-intel/{character} dossier** — 5 templated sentences + a
   score strip + a two-column "hostile history vs full timeline" view.
3. **Portal surfaces** — CharacterLookup, AllianceLookup, Dashboard
   character card. These mix role info, flight crew, arch-enemies,
   structural rank. Useful but scattered across three pages.

### Top 10 friction points (blockers to fast triage)

| # | Issue | Impact |
|---|---|---|
| 1 | No side-by-side pilot compare — clicks + mental math only | **high** |
| 2 | Dossier has prose, no 1-line verdict or next-action | **high** |
| 3 | Band buckets 100s of rows with no sub-rank within a band | med |
| 4 | No watchlist / persistent case file | **high** |
| 5 | `cohort_confidence` never explained (what's "medium"?) | med |
| 6 | AllianceLookup doesn't flag anomaly-band pilots in the command layer | **high** |
| 7 | Flight-crew / arch-enemies list has no anomaly-coloring of partners | med |
| 8 | Timelines flat — no coordinated-join / bloc-flip overlay | med |
| 9 | Typical suspicion → verdict path takes 3–4 clicks + reading | **high** |
| 10 | No "similar-to-flagged-set" view — `CI_SIMILAR_TO` isn't rendered | med |

### Top 10 missing "find-the-spy" features

| # | Feature | Data already exists? |
|---|---|---|
| 1 | 2-second triage card (band + reason 1-liner per row) | yes |
| 2 | Cohort baseline mini-chart on dossier (p5/p50/p95) | yes |
| 3 | Temporal anomaly flag — "joined red within N days" + co-join overlay | yes |
| 4 | Watchlist with persisted notes + cross-session reload | new table |
| 5 | Side-by-side dossier compare (?compare=A,B,C) | yes |
| 6 | Anomaly badges on AllianceLookup role layers | yes |
| 7 | Ring-expand — one click to see full co-flight community + bands | yes |
| 8 | Mini co-flight graph on dossier (top 3 neighbours, coloured by band) | yes (Neo4j) |
| 9 | Coordinated-join timeline (who else joined same alliance same week) | yes |
| 10 | Watchlist CSV / PDF export for hand-off to leadership | yes |

**All 10 are renderable from existing `ci_character_anomalies_rolling` +
Neo4j edges.** No new backends required for #1–10 except the watchlist
(item 4) which needs one `ci_review_watchlist` table.

---

## Part 2 — Neo4j upgrade plan

AegisCore uses Neo4j today for: CI character graph (`CICharacter` +
`CI_CO_OCCURS_WITH`, `CI_FOUGHT_AGAINST`, `CI_SIMILAR_TO`), the universe
graph (`System` / `Region` / `JUMPS_TO`), and the new alliance bloc-intel
graph (`Alliance` + `ALLIANCE_RELATES_TO`). Leiden fires once for counter-
intel ring detection. Not much else.

Neo4j 5.x + GDS 2026.03 give us far more. Research sources:
- [Neo4j GDS Leiden algorithm](https://neo4j.com/docs/graph-data-science/current/algorithms/leiden/)
- [Node Similarity](https://neo4j.com/docs/graph-data-science/current/algorithms/node-similarity/)
- [Filtered K-Nearest Neighbors](https://neo4j.com/docs/graph-data-science/current/algorithms/filtered-knn/)
- [Node2Vec embeddings](https://neo4j.com/docs/graph-data-science/current/machine-learning/node-embeddings/node2vec/)
- [Link prediction pipelines](https://neo4j.com/docs/graph-data-science/current/machine-learning/linkprediction-pipelines/link-prediction/)
- [Node classification pipelines](https://neo4j.com/docs/graph-data-science/current/machine-learning/node-property-prediction/nodeclassification-pipelines/node-classification/)
- [Cypher query tuning](https://neo4j.com/docs/cypher-manual/current/planning-and-tuning/query-tuning/)
- [Temporal graph fraud detection](https://neo4j.com/blog/developer/mastering-fraud-detection-temporal-graph/)

### Phase A — quick wins (days, not weeks)

**A1. Index audit + PROFILE pass on hot queries.**
Every counter-intel Cypher should hit an index. Add:
```cypher
CREATE INDEX cic_bloc_band IF NOT EXISTS
  FOR (c:CICharacter) ON (c.viewer_bloc_id, c.review_priority_band);
CREATE INDEX cic_score IF NOT EXISTS
  FOR (c:CICharacter) ON (c.review_priority_score);
```
Run `PROFILE` on the 5 most-used queries (review queue, dossier peers,
flight crew, arch-enemies, ring expand). Target: every query under 200 ms
p95 on 500k nodes.

**A2. Named projections.** Stop building an ad-hoc graph per call. Cache
projections: `ci_internal`, `ci_all`, `alliance_recent`. Refresh on data
reload, reuse across GDS calls. Cuts most GDS starts from seconds to ms.

**A3. Replace Jaccard with Filtered KNN.** The existing `CI_SIMILAR_TO`
seeding is a whole-graph Jaccard on co-flight sets. Filtered KNN scales
better, lets you constrain the peer pool (e.g. "only score against
internal pilots" or "only against clean-baseline pilots"). Direct swap in
`counter_intel.similarity`, same output schema.

**A4. Richer `ALLIANCE_RELATES_TO` queries.** With the edge live (shipped
today in d399879) add two Cypher views:
- "Who does this alliance *always* fight?"
  `MATCH (a:Alliance {id: …})-[r:ALLIANCE_RELATES_TO]-(b) WHERE r.hostility > 0.7 AND r.weighted_n_obs > 50 RETURN b.name, r.hostility, r.weighted_opposed ORDER BY r.weighted_opposed DESC`
- "Who ran parallel ops with us recently?"
  same pattern on `parallel_ops_strength > 0.3` + `last_seen_at`.
Ship as two blade panels on AllianceLookup.

### Phase B — embedding-driven similarity (1–2 weeks)

**B1. Train Node2Vec embeddings per viewer bloc.** 128-d vector per
`CICharacter`, projected against `CI_CO_OCCURS_WITH` edge weight. Run
once per day alongside `counter_intel graph-features`. Store as
`c.embedding` node property.

**B2. Nearest-neighbour lookup on dossier.** "Who flies most like this
pilot?" Top-20 cosine neighbours with band + viewing filter. Replaces the
current Jaccard similarity with a representation that survives schedule
gaps + captures multi-hop structure.

**B3. Similarity-to-flagged-set reason on dossier.** Compute `avg_cosine`
from a pilot's embedding to the seed set. If > threshold: render "matches
flagged-set pattern (k=N seeds, avg sim 0.74)". Addresses UX gap #10.

### Phase C — node classification (2–4 weeks)

**C1. Node classification pipeline.** Target label: "internal pilot
eventually flagged as spy (true/false)". Features:
`hostile_overlap_pct`, `bridge_anomaly_pct`, `recent_hostile_join`,
`seed_neighbors_count`, `ring_size`, `ring_density`, `embedding`.
GDS pipeline + Logistic Regression first, LightGBM later via GDS ML.

**C2. Ship a single `spy_probability` 0-1 on every dossier.** Alongside
the existing rule-based `review_priority_score`. Run the model nightly,
store as `c.spy_probability`. UI shows the rule-score AND the model
score side by side — agreement is a strong signal, disagreement is an
interesting case to review.

**C3. Calibration vs operator ground-truth.** Once ops flag dossiers as
"confirmed spy / confirmed clean", retrain the classifier every 30 days.
Each confirmation tightens the model.

### Phase D — temporal graph patterns (2–4 weeks)

**D1. `(:CICharacter)-[:JOINED]->(:CIAlliance {at: ts})` temporal edges.**
Persist the affiliation timeline as time-stamped edges rather than a
flat MariaDB table. Enables "coordinated-join" queries:
```cypher
MATCH (p:CICharacter)-[j:JOINED {alliance_id: $aid}]->()
 WHERE j.at > $red_flag_date - duration('P7D')
   AND j.at < $red_flag_date + duration('P7D')
RETURN p, j.at ORDER BY j.at
```

**D2. Ring stability over time.** Record Leiden community id + timestamp
snapshot nightly. A ring that's been stable for 30+ days with 10+
members + ≥ 3 seed pilots = very high-signal cell. Surface as a "pinned
ring" table on the counter-intel dashboard.

**D3. Alliance-pair trajectory.** `ALLIANCE_RELATES_TO` already has
`first_seen_at` / `last_seen_at`. Add `window_end` snapshots as
time-keyed edges so we can show "affinity trending up" / "hostility
accelerating" per-pair. Drives bloc-intel graphs.

### Phase E — query infrastructure + observability (ongoing)

**E1. Neo4j Metrics integration.** Enable `metrics.enabled=true`. Scrape
page-cache hit ratio, bolt query latency, GDS job duration. Alert if
p95 > 1 s. Addresses silent degradation when graph grows past 1M nodes.

**E2. Query library with PROFILE snapshots.** One place (docs/neo4j-queries.md)
with every operator-facing Cypher + expected plan. Reviewer diffs plans
over time to catch planner regressions.

**E3. Named-graph refresh schedule.** Every hour for the small CI
projection, every 6h for the alliance graph. Avoid re-projecting from
raw tables on every dashboard load.

### Phase F — new signals (4–8 weeks)

**F1. Path-based risk score.** "Shortest path length from this pilot to
any seed" — stretches the CI ring concept. Short path = more connected
to flagged set. Weighted by edge co-occurrence count.

**F2. Betweenness-through-hostile.** Pilot who bridges internal cluster
to hostile cluster. `c.betweenness_internal` exists; add
`c.betweenness_hostile` — top-5% here is nearly certainly a comms pipe.

**F3. Operator-in-the-loop GraphRAG chat.** Natural-language queries
over the CI graph ("show me pilots who recently joined red alliances AND
co-fly with seed pilots"). GDS-backed RAG layer. Deferred until the
rule-based surface settles — otherwise LLM drift competes with explicit
signals.

---

## Part 3 — suggested sequencing

1. **Week 1** — Phase A (indices, PROFILE, named projections). Quick-win
   UX items 1, 6 (dashboard triage card, AllianceLookup anomaly badges).
2. **Week 2** — UX items 4 (watchlist), 5 (compare), 7 (ring expand),
   plus Phase B1–B2 (node2vec + neighbour lookup).
3. **Week 3** — UX items 3, 8, 9 (temporal flag, cohort baseline chart,
   coordinated-join). Phase B3 (similarity-to-flagged-set).
4. **Week 4+** — Phase C (node classification pipeline) starts, gated
   on operator ground-truth accumulation.
5. **Weeks 5–8** — Phase D (temporal graph) + Phase E (observability).
6. **Month 3+** — Phase F (path risk, graphRAG chat).

---

## Part 4 — what not to do

- No new Cypher without `PROFILE` — the graph is big enough now that
  ad-hoc scan queries will brown out the UI.
- No LLM-generated insights on the review queue. Keep the scoring
  layer explainable. LLMs go in the "help me understand this dossier"
  chat surface, not in the verdict.
- No bespoke anomaly rules per viewer bloc. The hostility graph is
  viewer-relative; the scoring layer is viewer-agnostic. Keep that
  split. Each bloc gets its own sliced view via `viewer_bloc_id`
  filtering, but we maintain one model.

---

## Anchor references

- [Neo4j Trends 2025–2026](https://calmops.com/database/neo4j/neo4j-trends/)
- [Fraud Detection With Neo4j GDS (Neo4j dev blog)](https://neo4j.com/blog/developer/exploring-fraud-detection-neo4j-graph-data-science-part-3/)
- [Financial Fraud Detection With GDS Analytics](https://neo4j.com/blog/financial-fraud-detection-graph-data-science-analytics-feature-engineering)
- [Node Similarity algo reference](https://neo4j.com/docs/graph-data-science/current/algorithms/node-similarity/)
- [Filtered KNN](https://neo4j.com/docs/graph-data-science/current/algorithms/filtered-knn/)
- [Node Embeddings + Node2Vec](https://neo4j.com/docs/graph-data-science/current/machine-learning/node-embeddings/)
- [Link Prediction Pipelines](https://neo4j.com/docs/graph-data-science/current/machine-learning/linkprediction-pipelines/link-prediction/)
- [Node Classification Pipelines](https://neo4j.com/docs/graph-data-science/current/machine-learning/node-property-prediction/nodeclassification-pipelines/node-classification/)
- [Temporal Graph Fraud Modeling](https://neo4j.com/blog/developer/mastering-fraud-detection-temporal-graph/)
- [Cypher query tuning](https://neo4j.com/docs/cypher-manual/current/planning-and-tuning/query-tuning/)
- [Cybersecurity Threat Hunting With Neo4j (arxiv)](https://arxiv.org/abs/2301.12013)
