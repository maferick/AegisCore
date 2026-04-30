# Counter-Intel AI hypothesis synthesis — verification

Verifies the NVIDIA NIM integration shipped 2026-04-30 (commit
`dd74051`) plus the CI-Command surface AI badges + heavy-tier
refine action shipped on top.

ADR basis: 0012 (single-operator AI assist) + 0013 (hypothesis-
confidence framing). Calibration paper trail:
`calibration_proposals` row `surface=ai_runtime`,
`field=nvidia_nim_dependency`, status=adopted.

## Scope of this verification run

- Viewer bloc: 1 (Winter Coalition)
- Cohort: top 20 active hypotheses at confidence ≥ high
- Tier: fast (`stepfun-ai/step-3.5-flash`, fallback `z-ai/glm4.7`)
- Plus 4 ad-hoc rows from interactive testing (mix of fast +
  one heavy + 3 pre-fix rows that landed without a tier label)
- Total synthesis events recorded in `intel_audit_log`
  (surface=`ai_hypothesis`, action=`synthesize`): **24**

## Success rate

| metric | count |
|---|---|
| total synthesis attempts | 24 |
| ok (persisted) | 24 |
| validation rejects | 0 |
| graceful skips | 0 |
| failed | 0 |

Top-20 batch: `ok=20 skipped=0 failed=0`. End-to-end success
rate on fast tier = **100% (20/20)** for the batch run.

Note: heavy-tier additionally registered **2 graceful skips**
during ad-hoc testing earlier the same day —
`mistralai/mistral-large-3-675b-instruct-2512` returned
transport-error / 502 on long counter-intel prompts (~1300
tokens). The synthesis pipeline degraded gracefully (no row
state corrupted, no audit gaps); the operator can re-trigger
heavy tier later when the provider stabilises.

## Latency (fast tier, full counter-intel prompt)

| percentile | latency |
|---|---|
| min | 4.1 s |
| median | 8.1 s |
| mean | 11.9 s |
| p95 | 32.9 s |
| max | 35.5 s |

Per-row `usage` averaged ~1180 prompt tokens, ~720 completion
tokens. Reasoning content lands on `reasoning_content` field
when the model thinks; the client extracts whichever of
`content` / `reasoning_content` carries the JSON.

## Evidence quality + no-hallucinate validator

| metric | value |
|---|---|
| evidence rows mean | 4.2 |
| evidence rows min | 3 |
| evidence rows max | 6 |
| total hallucinated source_table drops | 0 |
| fell-back-to-fallback-model count | 0 |

The AI cited only `source_table` values present in either the
prompt's `source_signal_refs_json` or the explicit allow-list
of derived counter-intel tables (`ci_character_*_rolling`,
`ci_combat_anomalies`, `killmail_*`, `character_corporation_history`,
etc.). No fabricated tables observed in this cohort.

## Confidence band behaviour

| band emitted | count |
|---|---|
| low | 0 |
| medium | 1 |
| high | 23 |
| confirmed | 0 |

The single `medium` row was a heavy-tier (mistral-large-3) call
on hypothesis #33655 — the model lowered the band high → medium
based on caveats it surfaced. This is allowed under ADR 0013
(band may hold or lower; never auto-raise). All fast-tier rows
held the existing fusion band, as expected.

## Provider availability snapshot (2026-04-30)

| Tier | Model | Probe latency | Long-prompt result |
|---|---|---|---|
| fast | `stepfun-ai/step-3.5-flash` | 0.7 s ping | 100% ok across 21 rows |
| fallback | `z-ai/glm4.7` | 110 s ping (reasoning model) | not exercised in batch |
| heavy | `mistralai/mistral-large-3-675b-instruct-2512` | 1.1 s ping | 1× ok, 2× 502/transport-error today |
| safety | `nvidia/nemotron-content-safety-reasoning` | reachable | not yet wired into synthesis path |

Heavy-tier instability is provider-side and intermittent. Pipeline
guarantees graceful degradation; no operator action required when
NIM is unavailable.

## Sample before / after (hypothesis #33655 — Bakkanta one)

### Before (deterministic fusion summary)

> Bakkanta one: now-blue subject; signals reflect prior affiliation.
> Review as compromise/leak risk, not as external hostile. Score 8.38.

### After fast-tier synthesis (`stepfun-ai/step-3.5-flash`)

**Title:**

> Bakkanta one: Suspicious Reactivation as Blue Subject

**Summary:**

> Bakkanta one (2124244672) is a now-blue subject whose signals
> reflect prior hostile affiliations. Evidence includes 100%
> hostile community share, asymmetric mutual presence with a top
> hostile pair, recent corp/alliance change touching a known
> hostile alliance, unusual corp cadence (4 moves in 30 days),
> and elevated review priority. Signals span a 90-day window
> predating the bloc move; treat as potential compromise/leak
> risk rather than external hostile. Score 8.38, confidence high.

**Confidence reasoning:**

> High confidence based on multiple converging signals: extreme
> community hostility (100%), asymmetric graph ties (75% outbound
> vs 14% inbound to hostile), recent alliance change to a hostile
> entity, anomalous corporate movement cadence, and elevated
> review priority. The subject is currently in the viewer's bloc,
> so signals likely reflect past behavior; however, the pattern
> strongly suggests a reactivation or recruitment risk. No
> reduction below current band.

### After heavy-tier synthesis (`mistralai/mistral-large-3-675b-instruct-2512`)

**Title:**

> Bakkanta one: Possible hostile reactivation with asymmetric hostile ties

**Summary:**

> Operational suspicion surrounds Bakkanta one due to recent
> reactivation patterns, including multiple corp moves within
> 30 days and a recent alliance change touching a known hostile
> entity. Graph analysis reveals asymmetric mutual presence with
> top hostile pairs (75% outbound vs. 14% inbound) and 100%
> hostile share among graph neighbors. Signals suggest possible
> defector or recruit review rather than direct hostile suspicion,
> given the character's current bloc affiliation.

**Band decision:** heavy lowered high → medium with explicit
reasoning citing the 10-day staleness of signals and the absence
of a 30-day prior baseline. Both rationales were already in the
fusion `caveats` payload — the heavy model surfaced them as
band-affecting rather than informational.

## ADR 0013 binding-fields compliance

Every persisted synthesis carries the six binding fields:

1. `confidence_band` (`low|medium|high|confirmed`) ✓
2. `key_evidence[]` with `source_table` + `source_ids` + `source_link` ✓
3. caveats list ✓
4. `freshness` `{oldest_signal_at, newest_signal_at, stale}` ✓
5. `why_strengthened` (free text or "initial") ✓
6. `next_investigation_steps[]` (query-shaped, never actions) ✓

Plus mandatory audit row: `intel_audit_log.actor_kind=ai`,
`surface=ai_hypothesis`, `action=synthesize`, full
`prior_state_json` / `new_state_json` snapshots.

## Surface integration

CI Command page (`/portal/counter-intel/command`) renders a
status strip per card:

```
AI fast  stepfun-ai/step-3.5-flash · 4 evidence · 7268 ms · generated 2m ago
```

Plus action button **"Refine with heavy model"**:

- click → `RefineHypothesisJob` dispatched (`$timeout=240`,
  `ShouldBeUnique` on `hypothesis_id`)
- toast: "Heavy refinement queued — refresh in a minute"
- one row per click; heavy tier never auto-batches (avoids
  the 502 risk observed today)

## Plane-boundary compliance

- NIM client invoked from artisan + queued operator-action only;
  not from Livewire/Filament/queue jobs that gate analyst-visible
  state on a sub-2s budget
- `RefineHypothesisJob` documents its plane-boundary exception
  inline (operator-triggered, single row, external API); raises
  `$timeout` to 240 s
- API key never echoed in logs (client redacts `Bearer` tokens
  in error messages)
- `.env` gitignored (verified 2026-04-30)

## Deferred follow-ups

- Embeddings (`nv-embed` or `nemo-retriever`) — defer until a
  retrieve step lands in fusion. Current pipeline pre-selects
  signals; no retrieval gap to fill.
- Reranker (`rerank-qa-mistral-4b`) — same deferral.
- Safety pass (`nvidia/nemotron-content-safety-reasoning`) —
  model wired in config but not invoked. Schedule with the
  retrieve-rerank work.
- Heavy-tier provider stability — re-probe weekly until 7-day
  rolling success rate ≥ 95%; only then enable scheduled
  heavy-tier batches.

## Auto-refresh (hourly cron, fast tier only)

Shipped 2026-04-30 as `counter-intel:ai-refresh-stale`. Hourly via
`Schedule::command(...)->hourly()` in `routes/console.php`.

### Eligibility (any of)

- top 20 active by score (active, not archived)
- `last_strengthened_at > ai_summary_generated_at` (signals
  strengthened since last AI run)
- `ai_summary_freshness_state` ∈ {stale, expired}
- `ai_summary_generated_at IS NULL` (never synthesised)

### Skip rules (defence-in-depth)

- NIM not configured / provider unavailable → skip + record
  `failure_reason='nim_not_configured'`
- evidence_hash unchanged AND ai_summary_generated_at < 24h →
  skip silently (service-layer gate)
- circuit breaker open (≥3 `synthesize_failed` audit rows in
  trailing 30 min) → abort run
- daily cap reached (60 successes today) → abort run

### Schema (added 2026-04-30, migration
`2026_04_30_180000_add_ai_summary_state_to_counter_intel_hypotheses.php`)

| column | role |
|---|---|
| `ai_summary_generated_at` | timestamp of last successful synthesis |
| `ai_summary_freshness_state` | fresh/aging/stale/expired band |
| `ai_summary_evidence_hash` | sha256 over evidence + signals + score + bands |
| `ai_summary_model` | model id that produced the summary |
| `ai_summary_tier` | fast / heavy |
| `ai_summary_latency_ms` | wall time of the call |
| `ai_summary_attempt_count` | total attempts (incl. failed) |
| `ai_summary_last_attempt_at` | last attempt regardless of outcome |
| `ai_summary_failure_reason` | nullable; populated on failed attempts |

Indexes:
- `idx_ai_summary_freshness (viewer_bloc_id, ai_summary_freshness_state)`
- `idx_ai_summary_eligibility (viewer_bloc_id, ai_summary_generated_at)`

### Verification runs (2026-04-30)

```
$ counter-intel:ai-refresh-stale --viewer-bloc=1 --limit=3 --dry-run
auto-refresh viewer_bloc=1 eligible=3 limit=3 daily_used=44/60 circuit_failures_30m=0 (dry-run)
plan #33655 band=high sev=elevated score=8.38 reason=never_synthesised
plan #33656 band=high sev=elevated score=7.95 reason=never_synthesised
plan #33644 band=high sev=elevated score=6.14 reason=never_synthesised
done ok=0 skipped=3 failed=0 total_latency_ms=0

$ counter-intel:ai-refresh-stale --viewer-bloc=1 --limit=3
auto-refresh viewer_bloc=1 eligible=3 limit=3 daily_used=44/60 circuit_failures_30m=0
ok  #33655 :: model=stepfun-ai/step-3.5-flash latency_ms=10384 evidence=6
ok  #33656 :: model=stepfun-ai/step-3.5-flash latency_ms=7287  evidence=6
ok  #33644 :: model=stepfun-ai/step-3.5-flash latency_ms=10554 evidence=5
done ok=3 skipped=0 failed=0 total_latency_ms=28225

$ counter-intel:ai-refresh-stale --viewer-bloc=1 --limit=3   # immediately after
auto-refresh viewer_bloc=1 eligible=3 limit=3 daily_used=47/60 circuit_failures_30m=0
ok  #33648 :: latency_ms=8084 evidence=4
ok  #33649 :: latency_ms=4949 evidence=4
ok  #33643 :: latency_ms=8103 evidence=4
done ok=3 skipped=0 failed=0 total_latency_ms=21136
```

The third run picks **different** rows — the just-synthesised
33655/33656/33644 are correctly excluded by the SQL eligibility
filter (NULL-or-stale-or-strengthened predicate). Skip-if-fresh
gate verified.

After all three runs, sample column state for hypothesis #33655:

```
gen=2026-04-30 19:35:32 state=fresh hash=cb13977cf96d…
model=stepfun-ai/step-3.5-flash tier=fast ms=10384
attempts=1 failure_reason=null
```

## Visibility — where to read AI output

The CI Command page (`/portal/counter-intel/command`) now renders
an inline **"AI synthesis"** section per card (open by default)
showing:

- the AI's summary body (`summary` field)
- confidence reasoning
- key evidence list with claim text + canonical source link
- caveats list
- next investigation steps (query + rationale per row)

Plus the existing status strip (tier · model · evidence count ·
hallucinate drops · fellback · latency · generated time).

The full AI JSON output (including the field-level structure)
is also persisted on every synthesis in
`intel_audit_log.new_state_json.ai_output` — queryable with:

```sql
SELECT JSON_EXTRACT(new_state_json, '$.ai_output')
FROM intel_audit_log
WHERE surface = 'ai_hypothesis' AND surface_ref_id = ?
ORDER BY id DESC LIMIT 1;
```

## Re-run procedure

```
make artisan CMD="counter-intel:ai-summarize-hypotheses --viewer-bloc=1 --limit=20 --tier=fast"
make artisan CMD="ai:nim-test --prompt='ping'"
```

Re-collect metrics:

```sql
SELECT
  JSON_EXTRACT(metadata_json, '$.tier')         AS tier,
  JSON_EXTRACT(metadata_json, '$.model_used')   AS model,
  COUNT(*)                                      AS n,
  AVG(JSON_EXTRACT(metadata_json, '$.latency_ms')) AS mean_latency_ms,
  SUM(JSON_EXTRACT(metadata_json, '$.evidence_dropped_for_hallucinated_source')) AS total_drops
FROM intel_audit_log
WHERE surface = 'ai_hypothesis' AND action = 'synthesize'
GROUP BY tier, model;
```
