# 0010 — Phase 6 typed-text stylometry / writing fingerprint

Status: scaffolded
Date: 2026-04-26

## Context

Once Phase 3 ingest is producing `eve_log_events` with chat / fleet
/ intel messages, we can extract weak behavioural writing-style
features per author. The aim is to support clustering of *likely*
same-operator alts or to flag *unusually similar* intel reporters.

## Hard rules — never relax

- This is **not** proof of identity. Stylometry is a *supporting*
  signal only. The dossier renderer must always:
  - Show **confidence** and **sample size** alongside any stylometry
    note.
  - Render at most a `note` severity. **Never** a flag based on
    stylometry alone.
  - Require minimum sample sizes — small N = `confidence='insufficient'`
    and the entry is suppressed from the rendered list.
- Raw private messages are **not** exposed broadly. The dossier shows
  summarised feature vectors, not chat content.
- `eve_log_author_style_profiles.common_terms_json` redacts proper
  nouns (system names, character names, corp names) before extraction
  to avoid leaking operational intel through stylometry surfacing.

## Schema

`eve_log_author_style_profiles` — per (actor_name, window_end):

- `message_count` — total messages this actor sent in the window
- `avg_message_length`
- `punctuation_vector_json` — counts per punctuation class
- `casing_vector_json` — uppercase ratio, all-caps run frequency,
  sentence-start capitalisation rate
- `spacing_vector_json` — single vs double space, leading/trailing
  whitespace tendencies
- `abbreviation_vector_json` — usage rate of common EVE shortcuts:
  `x`, `xup`, `w`, `l`, `nv`, `gtfo`, `+N`, `align`, `mwd`, etc.
- `language_hint_json` — proportional split between detected
  languages (heuristic, not authoritative)
- `common_terms_json` — top-N non-PII tokens with redaction of
  character/corp/alliance names
- `short_command_usage_json` — `x`, `w`, `l`, `nv`, `+N` usage rates
- `cadence_hour_histogram_json` — circular hour-of-day histogram of
  messages
- `stylometry_hash` — sha256 over a canonicalised feature vector for
  fast equality clustering
- `confidence` — `insufficient` / `low` / `medium` / `high`

`eve_log_author_style_edges` — pairwise similarity:

- `(actor_a, actor_b, window_end)` unique
- `similarity_score` — cosine over the normalised feature vector
- `shared_features_json` — which dimensions drove the similarity
  (so the dossier can explain *why*)
- `sample_size_a` / `sample_size_b` — min becomes the edge's
  effective sample size
- `confidence` — joint, capped by min(sample_size_a, sample_size_b)

## Compute (deferred — scaffold only)

`python/counter_intel/phase6_stylometry.py` (not landed in this
commit) will:

1. Stream `eve_log_events` where `event_type IN ('chat_message',
   'local_message', 'fleet_message', 'intel_report')`, group by
   `actor_name` + window.
2. Apply PII redaction on each message (replace character / corp /
   alliance / system names with `<NAME>` / `<SYS>` placeholders before
   token extraction).
3. Extract feature vectors per actor.
4. For each actor pair sharing common channels in the window, compute
   cosine similarity. Persist edges above a calibration threshold.

Compute is gated on Phase 3 having flowed enough data. Until then,
the dossier service will silently skip stylometry (no rows = no
signal).

## Use cases (per spec)

- Likely alt-family clustering — multiple chars with the same
  stylometry hash within a corp / alliance.
- Unusually similar intel reporters — stylometry edge between two
  reporters supplying the same intel.
- Same-style accounts in different alliances — supplementary signal
  for the existing community_mismatch flag.
- Suspicious account behavior after character rename / corp transfer
  — stylometry persists when the name doesn't.

## Refusal cases

The renderer must NOT produce any of the following text shapes:

- "X and Y are the same operator."
- "Confirmed alt of Z."
- "This pilot is a spy because their writing matches Q."

Approved phrasing examples:

- "Stylometry profile is highly similar (cosine 0.82, sample 380 / 412)
  to <peer_name>. Treat as supporting evidence only."
- "Common short-command usage matches a cluster of 4 other pilots in
  this alliance — possible alt family. Sample size: 50–80 messages
  each."

## Open questions for calibration

- Minimum sample size before any edge renders. Probable floor:
  100 messages per actor.
- Cosine threshold. Calibration spec sets this from clean baseline.
- Time decay — should stylometry rebuild every 30 days or persist
  longer? Current schema supports rolling overwrite via
  unique(actor, window_end).
