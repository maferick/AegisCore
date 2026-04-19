# Sample pilot hand audits

Three pilots across the band spectrum. Confirms signal separation.

## 1. Reneil Askiras (2112414434) — CRITICAL, score 0.7816

**Current affiliation**: Fraternity. (99003581, bloc 1) — joined **2026-03-31**, 19 days ago.

**90-day features**:
- 31 battles, 3252 attacker-side killmails, 35 losses
- 53 active days, avg gang size 55.5, damage share 4.6%
- dominant_role mainline_dps, same_side_ratio 0.989
- distinct_cofliers 10,690

**Anomaly signals**:
- activity_decile 10 (top of cohort)
- affiliation_anomaly_pct 0.84 (top 16% — 2 hostile-linked alliances in history)
- hostile_overlap_pct 0.8852 (top 12% — 336 CI_FOUGHT_AGAINST-linked counterparts)
- bridge_anomaly_pct 1.00 (top, betweenness 36,204 — major connector)
- pagerank 3.87
- recent_hostile_join 0
- cohort_confidence medium (clean_pct 12% — cohort itself is noisy)

**Alliance history (last 8 years)**:
- Pandemic Horde (2019–2020, 2021–2024, 2024–2025) — hostile-linked
- Test Alliance Please Ignore (2020–2021) — hostile-linked
- Initiative Mercenaries (2018–2019), The Initiative. (2025–2026)
- Fraternity. (2026-03-31 → present, 19 days)

**Audit verdict**: legitimate signal. Recently-joined internal with
5 alliance stints across known historic-opposing blocs AND top-percentile
bridge score AND 336 overlap-with-hostile-tagged characters. Not a
false positive — this is exactly the kind of profile a human review
surface should raise. Whether Reneil is a spy or a defector-in-good-
faith is a call for the analyst, not the pipeline.

Explanation sentences (from CounterIntelDossierService) will read:

```
Activity level: decile 10 among 100 most similar pilots in the last 90 days.
Hostile-linked affiliation history: 2 alliances, top 15% among similar pilots.
Repeated fleet overlap with hostile-tagged characters: 336 distinct counterparts, top 15% vs cohort.
Bridge exposure (betweenness) is top 5% — acts as a connector between distinct fleet groups.
Cohort confidence: medium (100 peers, 12% clean baseline).
```

## 2. Xel'Tharuc (707940746) — ELEVATED, score 0.548

- 2 hostile-linked alliances in history, 73 hostile-cooccurrence count
- activity_decile 5 (average), bridge_anomaly_pct 0.68 (top 32%)
- Signal crosses elevated threshold on affiliation + overlap, does not
  combine with high activity/bridge enough to escalate. Correct band.

## 3. Omega Crucis (2113866246) — BELOW_THRESHOLD, score 0.153

- 24 battles in window, 0 hostile alliances in history
- Only 1 distinct alliance all-time (deep-tenure single-alliance pilot)
- 20 low-signal cooccurrences (expected incidental overlap)
- Correctly sits in cohort baseline. Not surfaced.

## Separation check

Top critical (0.78) and representative clean pilot (0.15) separated
by ~0.63 score units. Mid-band pilots (0.30–0.55) fill the gap
smoothly. Distribution has no dead zone and no cliff — scoring
function is behaving as designed.
