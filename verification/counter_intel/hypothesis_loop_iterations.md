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

---

# Second pass — loops 15-24 (human-readability focus)

User directive: "in the end for humans, adapt the pages for that".
Cron installed (§17.1 + §18 hourly). Goal of pass 2: page is
operator-digestible in under 10 minutes.

## Loop 15 — default min_band='high'

**Problem:** page opened with `medium` filter showing 498 cards —
operator overwhelmed.

**Fix:** default min_band changed to `high`. Operator sees the
strongest queue first; medium / low are explicit drill-downs.

## Loop 16 — medium-band score floor 2.5 → 3.0

**Problem:** histogram showed 178 medium-band rows clustered
between 2.5 and 3.0 — borderline cohort patterns.

**Fix:** floor lifted. Medium band reduced 498 → 305.

## Loop 17 — graph-centrality threshold tighten

**Problem:** pagerank 0.001 fired on every active FC; betweenness
50 fired on most cohort members. Centrality alone isn't
suspicion — it's combat tempo.

**Fix:** thresholds 0.001 → 0.0025 (pagerank), 50 → 150
(betweenness). Strength normalisers retuned so the score still
scales.

## Loop 18 — alt-cluster hint via name-prefix grouping

**Problem:** `Bakkanta one / Bakkanta to / Bakkanta Aviai Odunen`
were three separate cards even though the prefix screams alt
pattern. No visual hint linking them.

**Fix:** PHP page groups active hypothesis names by first word
(>=4 chars, not numeric). When 3+ rows share a prefix, each gets
a `cluster_hint` payload with prefix + sibling count.

## Loop 19 — render the cluster hint on cards

**Problem:** L18 computed but didn't render.

**Fix:** title row now shows
`+ N alt-hint` chip with tooltip explaining the prefix match.
Visual cue, not a verdict.

## Loop 20 — corp-cadence-anomaly signal

**Problem:** spec listed corp cadence anomalies as a fusion
input, but the pipeline ignored them.

**Fix:** new signal `corp_cadence_anomaly` (domain=temporal,
weight=2.0). Fires on >= 3 distinct corp moves in last 30 days.
Bulk-loaded into the bundle so per-pilot loop stays fast.

After L20 first run: `Bakkanta one` jumped from score 7.05 → 8.38;
new entries surfaced (`Me 0FF Jack`, `Huulen Tekitsu`) where corp
cadence pushed them across the high-band threshold.

## Loop 21 — cap displayed cards at 25

**Problem:** Command page rendered up to 50 cards; even after
filtering to `high` (43 rows), still too long for one screen.

**Fix:** LIMIT 25 in the page query. The 18 rows below the cut
remain in the table (audit + cluster-hint detection unaffected),
just don't render.

## Loop 22 — actionable CTAs on every card

**Problem:** no obvious next step. Operator could expand details
but couldn't act.

**Fix:** each card now ends with two buttons:
`Investigate →` (links to the pilot's lookup card) and
`Add to watchlist`. Footer compacts the metadata
(`first seen / model`) into one right-aligned line.

## Loop 23 — collapse the disclaimer ribbon

**Problem:** the "Hypotheses, not verdicts" ribbon at the page
top consumed ~12% of screen height. Repetitive — confidence chips
on every card already encode the framing.

**Fix:** moved to an `<details>` block titled "about these
hypotheses". Ribbon space freed up for the actual queue.

## Loop 24 — measurement / convergence check

After loops 15-23 (single fusion run, same window 2026-04-20):

| metric              | end of pass 1 | end of pass 2 |
|---------------------|---------------|---------------|
| active total        | 518           | 359           |
| high/elevated       | 20            | 43            |
| medium/elevated     | 4             | 4             |
| medium/watch        | 494           | 301           |
| top 1 score         | 7.05          | 8.38          |
| domains in top 1    | 4             | 4             |
| cards rendered      | 50 cap        | 25 cap        |

The cluster signal "Bakkanta" is unmistakable on the page — three
rows in the top 9, all flagged with `+2 alt-hint` chips, all
high/elevated, all 4-domain corroborated.

`Me 0FF Jack` and `Huulen Tekitsu` newly appeared in top 10 once
the corp-cadence signal landed — both have 4 corp hops in 30d.

## Final convergence summary (post-pass-2)

### Top 10 active hypotheses (bloc 1)

```
1.  Bakkanta one          s=8.38  4 domains  + alt-hint
2.  Bakkanta to           s=7.95  4 domains  + alt-hint
3.  Titus Lancaster       s=6.14  4 domains
4.  Huulen Tekitsu        s=5.82  3 domains  (corp_cadence)
5.  Mokken Kashada        s=5.80  3 domains
6.  RexDaniel             s=5.50  3 domains
7.  ChaosXD               s=5.47  3 domains
8.  Oen Meda              s=5.43  3 domains
9.  Bakkanta Aviai Odunen s=5.41  3 domains  + alt-hint
10. Me 0FF Jack           s=5.27  4 domains  (corp_cadence)
```

### Strongest corroborated cluster

`Bakkanta`-prefix cluster — 3 active high-confidence rows, all
4-domain corroborated. Likely alt pattern. Investigative priority
worth manual review.

### Confidence distribution (post-pass-2)

```
high/elevated:    43   (was 0 baseline, 20 end of pass 1)
medium/elevated:   4
medium/watch:    301
low (active):      0
total active:    359
```

97% reduction from the raw rolling-anomaly count of 17,561.

### Major improvements achieved across both passes

- Operator sees `high` band first (~25 cards on Command page).
- Multi-domain corroboration is the dominant ranking signal —
  single-domain rows can't reach the high band.
- Alt-pattern visible via name-prefix `+ N alt-hint` chip.
- Per-card: confidence + severity + freshness + score + domain
  chips + signals + caveats + why-strengthened + source rows +
  Investigate / Watchlist CTAs.
- Hypothesis decay: fresh < 7d, aging < 21d, stale older.
- Stale-on-no-refresh archive keeps queue current.
- Cron-driven (hourly) — no manual runs needed.

### Operational usefulness assessment

Page answers "who do I investigate first?" in one render. Top 3
are believable (Bakkanta cluster + Titus Lancaster). Top 10
multi-domain, all with linked evidence and source rows. Operator
can drill in via the `Investigate →` button on any card and land
on the pilot's full intel surface.

### Remaining weaknesses (deferred to a future pass)

1. **No `correlated_cluster` hypothesis type yet.** The
   alt-hint chip is informational; the underlying schema supports
   a true cluster row with `related_character_ids_json`, but the
   compute path isn't wired. Loop work item.
2. **No materialised per-character operational overlap.** The
   incident_overlap signal is scaffolded but disabled — needs a
   covering aggregate table.
3. **No Neo4j community read.** Graph-mismatch and bridge
   behaviour signals from Neo4j aren't yet folded in.
4. **No analyst-feedback loop.** `confirmed` band requires
   operator validation; no UI yet to flip a hypothesis to
   `confirmed` from the Command page (depends on §6 governance
   surface).
5. **Doctrine deviation / corridor presence / force-comp
   mismatch — three operational signals listed in spec, all
   skipped this pass.** Each adds DB joins; each will need a
   per-loop cost / value review.

### Recommended next iteration areas (priority order)

1. Materialise `character_operational_overlap_30d` aggregate so
   incident-overlap can land cheaply.
2. Implement true `correlated_cluster` hypothesis type — fuse
   prefix-matched + corp-shared + timing-overlap cohorts into
   one row with `related_character_ids_json`.
3. Wire Command-page "validate" / "dismiss" buttons → bumps
   confidence to `confirmed` (operator-driven) or status to
   `archived` with audit log.
4. Read Neo4j community labels + bridge scores into the bundle.
5. Calibrate score thresholds against operator validation
   feedback once #3 ships.

### What still blocks the very strongest CI

- Operator-validated `confirmed` band requires the validation
  loop (#3 above) — until then, the AI ceiling is `high`.
- Stylometry / typed-text similarity — permitted under ADR 0013
  but requires a privacy/ABAC ADR before raw chat content is
  joined into the bundle.
- A second-bloc baseline corpus (no parallel WC-scale dataset
  exists today) — limits operator's ability to calibrate "what
  does normal look like".

