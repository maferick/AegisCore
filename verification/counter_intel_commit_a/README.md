# Counter-Intel Commit A — verification

Scope: projection rework (weighted edges, SAME/OPPOSING split,
theater dampener, session-based distinct_interactions) + anomaly
internal-only subject filter + `_purge_external_rows`.

Commit: `587b5e5`

## Run

```
make ci-projection                 # full-ingest weighted projection
make ci-similarity                 # GDS knn + pageRank + betweenness
make ci-anomalies VIEWER_BLOC=1    # WinterCo perspective
```

## Diagnostic pass — weight-share curve

From projection log (task `bd5nu42cg`):

| Relation | Edges kept | p50 max_single_event_share | p90 | p99 | Dominated ≥0.8 |
|---|---|---|---|---|---|
| CI_CO_OCCURS_WITH (same) | 304,515 | 0.084 | 0.239 | 0.500 | 269 (0.09%) |
| CI_FOUGHT_AGAINST (opp)  |  17,087 | 0.461 | 0.707 | 0.913 | 820 (4.8%) |

Pass criterion: p50 < 0.5 for both. Met. Curve smooths big-fight
contribution without erasing it — ~95% of same-side edges built from
multiple distinct sessions, ~50% of opposing edges anchored on a
single dominant fight (expected — opposing interactions are rarer).

`theater_dampener_floor = 0.15` stays. No tune.

## Ingest volume (90-day window, 2026-01-19 → 2026-04-19)

| Step | Count |
|---|---|
| Killmail metadata rows | 1,476,158 |
| Same-side attacker-pair events | 16,153,838 |
| Opposing attacker↔victim events | 396,261 |
| Same-side pairs pre-threshold | 815,947 |
| Same-side pairs kept (≥2 sessions, ≥0.5 weight) | 304,515 |
| Opposing pairs pre-threshold | 281,643 |
| Opposing pairs kept | 17,087 |

## Anomaly pass (viewer_bloc_id=1, WinterCo)

- Internal subject set (current affiliates of bloc-1 alliances): **27,056**
- Has-sufficient-history (≥5 battles in window): **909**
- Clean pilot set (≥365d tenure, zero hostile-linked history): **171,691**
- Stale pre-filter rows purged on first run: **3,476**

### Band distribution

| Band | Count | % of scored |
|---|---|---|
| critical | 1 | 0.1% |
| high | 34 | 3.7% |
| elevated | 131 | 14.4% |
| below_threshold | 743 | 81.7% |

Shape looks right for a triage surface (~18% elevated-or-above).

### Subject-set integrity check

All top 15 high/critical rows resolve to current alliances in bloc 1:

| alliance_id | bloc_id | in top-15 |
|---|---|---|
| 99003581 Fraternity. | 1 | ✓ |
| 99011223 | 1 | ✓ |
| 99005393 | 1 | ✓ |

No external hostiles (Goonswarm bloc 2, Brave bloc 3) in dashboard.

## Hand audits

See `sample_pilots.md`.
