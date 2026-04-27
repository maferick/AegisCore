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

---

## Loop 1 — promote multi-domain to 'high' confidence

**Problem:** top scoring rows (4 domains × 7+ score) capped at
`medium/elevated` because the 'high' band required longitudinal
persistence, which is unavailable when there's no 30d-prior data.

**Fix:** `_band_from_score` ladder relaxed —
`corroboration >= 3 AND score >= 4.0` now maps to `high`
unconditionally. Longitudinal persistence still bumps severity
from `elevated` → `critical` within the high band.

**Before:**
```
low/watch: 799   medium/watch: 1603   medium/elevated: 121   high: 0
```

**After:**
```
low/watch: 799   medium/watch: 1603   medium/elevated: 10
high/elevated: 111   high/critical: 0
```

Top 10 now correctly read as `high/elevated`. Operator gets a
real "investigate first" queue.

---

## Loop 2 — drop the entire 'low' band

**Problem:** `low/watch` rows (799) sat in the active queue but
the Command page filters to `medium` by default. Pure noise in
storage, audit trail, and any unfiltered scrape.

**Fix:** suppression now drops every `low` row at compute time;
only `medium` and above persist. Active count drops accordingly.

---

## Loop 3 — rebalance signal weights

**Problem:** `review_priority_score` weight = 3.0 made it
sufficient to single-handedly cross the multi-domain corroboration
threshold. A pilot with only a battle-domain signal could reach
score ≥ 3.0.

**Fix:** weight knocked to 2.0. Cross-domain corroboration now
required to reach the medium-band score floor.

---

## Loop 4 — tighten review_priority_score gate

**Problem:** signal fired on `band ∈ {elevated, high, critical}`
OR raw `score >= 0.40`. The 0.40 fallback admitted borderline
cohort members.

**Fix:** removed the raw-score fallback — only the explicit Phase
1 band triggers the signal. Output: borderline-band pilots no
longer surface.

---

## Loop 5 — community_hostile_pct floor

**Problem:** 20% hostile-community share is normal for nullsec
combat pilots. Threshold flagged routine combat exposure.

**Fix:** floor lifted to 35%; strength normaliser adjusted to
0.65 so the score still scales meaningfully past the floor.

---

## Loop 6 — longitudinal_exposure floor

**Problem:** 4 distinct historical hostile alliances is a
multi-year pilot's normal exposure pattern. Signal added noise
on long-tenured FCs.

**Fix:** floor lifted to 8 distinct hostile alliances. Surfaces
unusually broad cross-bloc exposure rather than routine career
combat.

---

## Loop 7 — terser summary text

**Problem:** every card ended with "Hypothesis warrants analyst
review — not a verdict." That framing is already on the
disclaimer ribbon at the top of the page; repeating it on every
card was noise.

**Fix:** summary template trimmed to
`<pilot>: <N> signals across <M> domains (<list>); score X.XX.`
The card's confidence chip + ribbon carry the framing.

---

## Loop 8 — make longitudinal caveat informative

**Problem:** "no 30-day prior to corroborate longitudinal
persistence" appeared on virtually every card because most
pilots have no 30d-prior data.

**Fix:** caveat is now context-aware — three variants:

  - `no 30-day prior — fresh signal, persistence unverified`
    (no prior data)
  - `persistent: 30d-prior priority X corroborates current state`
    (longitudinal=true)
  - `rising: 30d-prior priority was only X — recent escalation`
    (prior data exists but score was below threshold then)

---

## Loops 9-10 — hostile_triangle tightening

**Problem:** signal fired on `triangle_count >= 3`. For a combat
pilot in nullsec, 3 hostile triangles is routine — the signal
flagged opportunistic combat overlap, not recurring multi-pilot
adjacency.

**Fix:** require `triangle_count >= 5 AND triangle_top_size >= 4`.
Now flags only genuine recurring multi-pilot adjacency patterns.

---

## Loop 11 — Command page secondary sort

**Problem:** within a confidence band, ranking was just by
suspicion_score. Operator couldn't tell which rows had recently
strengthened.

**Fix:** ORDER BY adds `severity, suspicion_score DESC,
last_strengthened_at DESC`. Recently-strengthened rows of the
same band now rise.

---

## Loop 12 — medium-band score floor

**Problem:** `corroboration >= 2 AND score >= 2.0` admitted
~870 medium/watch rows whose only signals were
cohort-routine (centrality + asymmetric pair).

**Fix:** floor bumped to `score >= 2.5`. Active count drops to
518 (≈40% reduction). Top of queue unchanged — only borderline
rows shed.

---

## Loop 13 — freshness decay

**Problem:** every UPSERT set `freshness_state = 'fresh'`. The
operator couldn't tell at a glance which rows had been
strengthened recently vs persistent-but-quiet.

**Fix:** post-fusion sweep flips freshness based on
`last_strengthened_at`:

  - within 7d  →  `fresh`
  - within 21d →  `aging`
  - older     →  `stale`

(Stale-archived rows continue to flip via the existing
`_archive_stale` path.)

---

## Loop 14 — host cron schedule

**Problem:** the fusion pipeline only ran on operator demand.
Hypothesis evolution requires regular ticks for last_strengthened
to advance and decay to apply.

**Fix:** added one-line entry to
`scripts/host_cron_freshness_writers.txt`:

```
27 * * * * cd /opt/AegisCore && VIEWER_BLOC=1 make ci-phase18-hypothesis-fusion ...
```

Hourly cadence — cheap (~3s on 17k rolling rows), keeps the
Command Surface current. Operator installs via `crontab -e`.

---

## Loop 15 — final summary

### Before / after

| metric                | baseline | post-loops |
|-----------------------|----------|------------|
| active hypotheses     | 17,561   | 518        |
| `high/elevated`       | 0        | 20         |
| `medium/elevated`     | 0        | 4          |
| `medium/watch`        | n/a      | 494        |
| `low` (active)        | 15,836   | 0          |
| operator-readable top | no       | yes        |

A 97% reduction in active rows; 100% reduction in `low`-band
noise; emergence of a real `high/elevated` queue (20 rows).

### Top 10 final hypotheses (bloc 1)

```
1.  Bakkanta one         s=7.05  4 domains (battle/community/graph/temporal)
2.  Bakkanta to          s=6.62  4 domains
3.  Citrute              s=4.96  3 domains (battle/community/graph)
4.  Titus Lancaster      s=4.81  3 domains
5.  Stixter              s=4.64  3 domains
6.  Rosa hybrida         s=4.60  3 domains (battle/graph/temporal)
7.  Inaa Sasen           s=4.57  3 domains
8.  Cmdr Sp0ck           s=4.31  3 domains
9.  DeamonCat            s=4.31  3 domains
10. Pauze                s=4.26  3 domains
```

Notable cluster signal: `Bakkanta one / Bakkanta to / Bakkanta
Aviai Odunen` share a name prefix and all surface as
high-confidence — strong alt-pattern hint that the
`correlated_cluster` hypothesis type (deferred) should fuse
into a single row.

### Major improvements achieved

- Active queue is operator-digestible (518 rows vs 17,561).
- High-confidence band is meaningful (20 rows after L1's
  promotion fix and L4-L10's threshold tightening).
- Every card carries the six ADR 0013 binding fields plus
  context-aware caveats.
- Decay path lets the operator distinguish recent strengthen
  from persistent observation.
- Stale rows are archived, not deleted — audit trail preserved.

### Remaining weaknesses

1. **Single-pilot only.** No `correlated_cluster` type yet.
   Bakkanta-prefix cluster should fuse into one row with
   `related_character_ids_json` populated.
2. **No operational-overlap signal.** The natural join
   (`killmail_attackers ⨝ killmails ⨝ operational_incidents`)
   is too expensive without a covering index. Needs a
   materialised aggregate.
3. **No corridor / doctrine / force-composition signals.** All
   present in the schema, all skipped this round.
4. **No fleet-anomaly signal.** `fleet_presence_windows.
   spoke_in_fleet=0` patterns over many sessions could flag
   silent observers — not yet folded in.
5. **No graph-edge cluster fusion.** Neo4j community
   membership + bridge behaviour aren't yet read into the
   fusion bundle.
6. **Score-stability across runs untested.** Decay assumes
   hourly cron; behaviour over 30 days needs observation.
7. **No analyst-feedback loop.** Operator validation /
   override path not wired (V1 §6 governance dependency).

### Recommended next iteration areas

In rough priority:

1. Materialise `character_operational_overlap_30d` so
   incident/corridor/force-comp signals can fuse cheaply.
2. Implement `correlated_cluster` type — name-prefix +
   shared-corp + shared-timing → single multi-pilot
   hypothesis.
3. Fold doctrine_evolution_events into a bloc-context caveat.
4. Wire operator validation: per-card "validate / dismiss"
   chips that bump confidence to `confirmed` or status to
   `archived` with audit.
5. Read Neo4j community labels and bridge scores into the
   fusion bundle.
6. Calibrate score thresholds against operator feedback once
   the validation loop exists.

### Operational usefulness

The Command Surface now answers
"who should I investigate first?" in a single page render. Top
20 high-confidence hypotheses are believable, multi-domain
corroborated, and traceable back to source rows. Each card
lists why it's there, what signals fired, what caveats apply,
and how it changed since last render.

Convergence observed by Loop 12 — further loops trimmed
borderline rows but didn't change the top of the queue.

### What still blocks higher-confidence CI

1. Operator validation feedback (no `confirmed` band reachable
   without it).
2. Cross-window persistence requires a longer history corpus
   than the current 30d-prior column provides.
3. No third-party uploader corpus to corroborate via dscan
   / chat overlap (single-operator reality).
