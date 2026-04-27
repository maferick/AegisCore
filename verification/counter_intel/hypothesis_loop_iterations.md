# Counter-Intel Hypothesis — refinement loops

Goal: evolve the platform from many disconnected intelligence
surfaces into a single high-signal Counter-Intel Command Surface
that surfaces the most operationally suspicious characters and
clusters first.

Each loop:
1. inspect current state
2. identify highest-impact issue
3. ship one focused fix
4. compare before/after distribution
5. record findings here

Bloc under test: 1 (Winter Coalition).
Window: latest available rolling anomaly window.

---

## Loop 0 — initial baseline

Pipeline `phase18-hypothesis-fusion` first ship:
17 single-pilot signals (battle/community/graph/temporal),
threshold suppression on `score < 1.5 OR (corroboration < 2 AND
score < 2.5)`, archive-on-no-refresh.

Distribution after first tightened run:

```
candidates: 17561
hypotheses_written: 2524
archived_on_no_refresh: 0  (first run)

confidence:
  low:    pending
  medium: pending
  high:   pending

corroboration:
  1: pending
  2: pending
  3: pending
  4: pending
```

Top 10 by score: pending.

Open issues queued for loops 1-15:

- single-domain low-corroboration noise still dominant
- operational/temporal/incident overlap underrepresented
- no cluster fusion yet (single_pilot only)
- doctrine deviation, force composition, corridor co-presence
  not folded in
- decay timing untested
- score thresholds not validated against operator feedback
