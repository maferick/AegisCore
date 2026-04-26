# 0008 — Counter-Intel k-NN cohort extension

Status: accepted (scaffolded)
Date: 2026-04-26

## Context

Phase 1 anomaly scoring uses `gds.knn` over a 13-dimensional feature
vector projected by `python/counter_intel/projection.py` and
normalised in `python/counter_intel/similarity.py`. The current
dimensions cover activity (battles, active_days, gang size, solo
ratio), role distribution, cooccurrence density, same-side ratio,
and affiliation churn.

Phase 2 of the Counter-Intel platform spec calls for the cohort to
be built on *(activity, role, doctrine, battle frequency, timezone,
graph density)*. Activity, role, and battle frequency are already
covered. Doctrine, timezone, and graph density are gaps.

## Decision

Extend the existing `gds.knn` pipeline rather than replace it. Keep
the current shape: per-character feature vector → z-normalize →
gds.knn writes `:CI_SIMILAR_TO` edges → anomaly pass aggregates
percentile baselines per cohort.

New dimensions in priority order:

1. **`tz_centroid_hour`** — circular mean of the existing
   `hour_histogram` over the rolling window. Encoded as `(sin, cos)`
   to preserve circularity in the L2 distance. Lets the cohort
   distinguish EU / US / AU / RU windows without bucketing into
   discrete TZ classes.

2. **`pagerank_z`, `betweenness_z`** — z-scored versions of the
   already-computed `pagerank` and `betweenness` properties. Captures
   "graph density" — central pilots cohort with central pilots.

3. **`doctrine_match_rate`** — fraction of the pilot's losses that
   match their alliance's dominant `auto_doctrines` head for the
   inferred role. Requires a join over `auto_doctrine_pilots` ×
   `battle_character_role_inference` × `killmails`. Heavy compute,
   suitable for a nightly Python pass that writes a new column on
   `ci_character_features_rolling`.

4. **`doctrine_diversity`** — count of distinct
   `(auto_doctrine_id, role)` clusters the pilot has flown in the
   window. Optional second dimension that catches "purpose-built
   alt only ever flying one fit" vs. "a real main rotates".

## Implementation phases

### Phase 2.5 (this commit)

- Schema only:
  - `ci_character_features_rolling.tz_centroid_sin DECIMAL(6,5)`
  - `ci_character_features_rolling.tz_centroid_cos DECIMAL(6,5)`
  - `ci_character_features_rolling.pagerank_z DECIMAL(8,4)` NULL
  - `ci_character_features_rolling.betweenness_z DECIMAL(8,4)` NULL
- Python module `counter_intel.phase2_cohort_features` that fills
  `tz_centroid_*` from the existing JSON `hour_histogram`. Single
  pass, idempotent.
- `FEATURE_DIMS` in `similarity.py` updated to **optionally** consume
  these new dims if non-NULL. Until the projection step re-projects
  with the extended property set, the existing 13-dim vector remains.
- Documented gap: `pagerank_z` and `betweenness_z` need a separate
  z-normalisation pass after the existing graph_features compute.
  Not blocking — read-side baseline still works.

### Phase 2.6 (follow-up)

- Doctrine adherence column + nightly job. Requires:
  - per-loss `inferred_role_id` resolution
  - `auto_doctrines` head fingerprint match (Jaccard ≥ 0.65 reuses
    the existing ComputeAutoDoctrines threshold)
  - per-character roll-up of match-rate + diversity
- Re-project the CI Neo4j subgraph with the full extended property
  set, then `gds.knn.write` again with the extended `feature_vector`.

### Out of scope

- Replacing the GDS pipeline. The user's spec wording "k-NN cohort
  system. Replace simplistic alliance×activity cohorts" can be
  interpreted as either (a) replace the algorithm or (b) replace the
  inputs. The current algorithm IS k-NN already — we replace the
  inputs.
- Switching to Faiss / pgvector. The dataset is ~200K nodes with a
  ~20-dim vector; GDS handles this well in-cluster.

## Consequences

- The cohort dimensions become richer over time without rewriting the
  anomaly compute layer — `cohort_size` / `cohort_clean_pct` /
  `activity_decile` semantics are unchanged.
- TZ is captured via circular encoding so the L2 distance does the
  right thing across midnight.
- Doctrine adherence is the highest-cost and most behaviorally
  meaningful dimension; it lives in Phase 2.6 because we don't want
  the cohort to drift while the doctrine compute is unstable.

## References

- `python/counter_intel/similarity.py` — current 13-dim FEATURE_DIMS
- `python/counter_intel/projection.py` — Neo4j node property
  projection
- `python/counter_intel/anomalies.py` — cohort percentile baseline
  consumer
