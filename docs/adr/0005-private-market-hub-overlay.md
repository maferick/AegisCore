# ADR-0005 — Private Market Hub overlay on top of Jita reference

**Status:** Accepted
**Date:** 2026-04-14
**Related:** [ADR-0002](0002-eve-sso-and-esi-client.md) (EVE SSO + ESI),
[ADR-0003](0003-data-placement-freeze.md) (data placement freeze),
[ADR-0004](0004-market-data-ingest.md) (market data ingest + donor structure
auth)

## Context

ADR-0004 put Jita on the ingest path as the platform's permanent reference
hub and scaffolded donor-owned player-structure polling via
`market_watched_locations.owner_user_id` + `eve_market_tokens`. That model
works for the single-donor, single-structure case but breaks down as soon
as the feature becomes a product:

1. **Hub identity is tied to a specific donor.** If Donor A registers
   Keepstar X and then churns out of the game, the row is effectively
   orphaned — there's no clean "re-seat a new donor as collector"
   motion, and no shared canonical identity for "Keepstar X" that
   survives A's departure.
2. **Duplicate onboarding creates duplicate polling.** With
   `UNIQUE(owner_user_id, location_id)`, two donors with docking rights
   to the same Keepstar create two watched rows → two ESI lanes → two
   outbox streams → doubled InfluxDB cardinality for the same physical
   market. Not wrong, but wasteful.
3. **Visibility is implicit in ownership.** "Only the owner can see their
   hub" is a fine v0 but doesn't scale to donors wanting to share a
   staging hub with their corp / alliance, or to the operator granting
   a second donor read-only access.
4. **Ownership and visibility conflate.** A "premium" feature needs two
   independent dials: *who can collect* (technical: holds a token with
   docking rights) and *who can view* (commercial: is an entitled donor
   / admin).

Meanwhile, the InfluxDB projectors ADR-0004 landed tag observations by
`location_id`, which means a comparison query (Jita vs a private hub)
is already a trivial two-filter Flux query. The data side is ready;
the policy side needs a sharper model.

This ADR settles the policy model before we build out the self-service
donor UX, Filament audit tools, and cross-hub comparison pages.

## Decision

### Canonical hub model

One new table — **`market_hubs`** — is the stable, canonical identity
of a market source. Unique on `(location_type, location_id)`: one row
per physical market, globally. A hub is not "owned" by any donor; it
is a canonical identity that multiple collectors can serve and
multiple viewers can read.

Two flavours:

- **`is_public_reference = true`** — Jita (seeded) and any other NPC
  hubs the platform treats as publicly visible reference data. No
  collectors (the platform service token polls them), no
  entitlements (visible to everyone).
- **`is_public_reference = false`** — Donor- or admin-registered
  player structure. Gated by the intersection rule below.

### Two attached tables

- **`market_hub_collectors`** — tokens authorised to poll a given hub.
  Zero for public-reference hubs; one-or-more for private hubs.
  `is_primary` marks the preferred collector; failover cycles to
  other active collectors on ACL / 5xx failure. The hub freezes only
  when zero active collectors remain, not when any single collector
  fails.
- **`market_hub_entitlements`** — subjects (user / corp / alliance)
  granted the right to view a private hub. Pre-wired for phase-2
  group sharing; v1 ships `subject_type = 'user'` only. An
  entitlement row does NOT bypass the feature gate — it is only the
  "explicitly granted access to this specific hub" half of the
  intersection.

### Intersection rule (the security boundary)

```
can_view_private_hub(user, hub) = has_feature_access(user)
                              AND has_hub_access(user, hub)
```

- `has_feature_access(user) := user.isDonor() || user.isAdmin()`
- `has_hub_access(user, hub) := exists an entitlement row whose
  (subject_type, subject_id) matches the user, OR one of the user's
  corp / alliance IDs (phase 2).`

Public-reference hubs short-circuit: visible to everyone regardless
of donor / entitlement state. The single chokepoint is
`App\Domains\Markets\Services\MarketHubAccessPolicy` — every UI,
Livewire, Filament, and API path that exposes hub-scoped data is
expected to route through its `canView()` / `visibleHubsFor()`
helpers. Reinventing the check at the call site is a review-blocker.

### Registration flow

1. Donor authenticates via ESI with structure-market scopes.
2. Donor selects an accessible structure via `/characters/{id}/search/`.
3. System looks up `(player_structure, location_id)` in
   `market_hubs`.
4. **Match:** donor is attached as an additional collector and gets
   a `subject_type = 'user'` entitlement. No new polling lane, no
   duplicated ESI cost.
5. **No match:** a new canonical hub is created, the donor is the
   first collector (primary), and the donor is auto-granted a
   self-entitlement.

### Failover

The Python poller picks the primary active collector first, falls
over to any other active collector on structure-ACL / 5xx failure,
and writes the outcome back to the collector's `last_success_at` /
`last_failure_at` / `consecutive_failure_count` (same discipline as
the existing `market_watched_locations` failure bookkeeping, moved
to the per-collector row where it belongs). The hub's
`active_collector_character_id` is the denormalised pointer the
poller maintains so subsequent poll ticks don't re-run the picker.
Only when zero active collectors remain does the hub freeze
(`disabled_reason = 'no_active_collector'`), without flipping
`market_hubs.is_active` — an admin may rescue it with a donor
re-auth.

### Transition

`market_watched_locations` stays as the Python poller's driver
table during the transition. A new nullable FK
`market_watched_locations.hub_id` points at the canonical hub;
migration `2026_04_14_000015_backfill_market_hubs_from_watched_locations`
populates it for every existing row:

- Jita / NPC (owner_user_id IS NULL) → public-reference hub, no
  collectors, no entitlements.
- Donor-owned → private hub, with the donor's existing
  `eve_market_tokens` row wired as the primary collector and a
  self-entitlement auto-granted.

A follow-up ADR will retire `market_watched_locations.owner_user_id`
once the poller has been updated to pick tokens via
`market_hub_collectors` and the column stops being read. Leaving
the legacy column in place for now keeps the poller working
unchanged through this ADR's rollout.

### User preference

`users.default_private_market_hub_id` is the donor's pinned
comparison target. Null-safe: an entitlement revocation silently
demotes the UI to "no default", never a hard error. The policy
still evaluates the intersection rule at read time — setting this
field does NOT grant visibility.

## Alternatives considered

### Keep `market_watched_locations.owner_user_id`, add only a viewer table

Minimal schema delta, but perpetuates the "hub belongs to donor A"
conceptual bug. The moment A churns, the row is an orphan; there is
no clean place for a replacement collector to attach. Rejected.

### Polymorphic `entitlable_type` + `entitlable_id`

The Laravel-idiomatic way to model user / corp / alliance grants.
Rejected in favour of an ENUM discriminator + scalar bigint for two
reasons: (a) `corp` and `alliance` aren't Eloquent models — they're
CCP identifiers, not AegisCore rows; the Laravel polymorphic helpers
would be dead weight. (b) The ENUM is smaller on disk, more
compact in indexes, and makes "give me every grant that targets a
corp" queryable without a LEFT JOIN.

### Share collectors across hubs

One token row could theoretically serve several hubs. Rejected
because the CCP structure ACL is per-structure — a token good for
Keepstar X is not good for Keepstar Y even if the same character
has docking rights to both. Modelling the join per-hub makes the
failover story honest: when the donor's character loses docking
rights to one structure, the other hubs' collectors stay live.

### Auto-grant corp / alliance at register time

Tempting for UX, rejected as a default. Auto-granting viewers
without deliberate consent is how donors accidentally expose hubs
to their whole alliance. Phase-2 will add explicit corp / alliance
grants with a dedicated UI; phase-1 ships user-only so no
misconfiguration can silently broadcast a private hub.

## Consequences

### Positive

- **Canonical hub identity** survives donor churn, collector loss,
  and admin turnover. A dead primary + a fresh donor re-auth = live
  hub, no viewer sees the seam.
- **One polling lane per physical market.** ESI cost and InfluxDB
  cardinality stay linear in structures, not donors.
- **Single chokepoint** for the intersection rule. Every page that
  shows hub data routes through `MarketHubAccessPolicy`; review
  rejects any that don't.
- **Phase-2 group sharing is a code change, not a migration.** The
  ENUM, the subject_id bigint, and the policy interface are already
  there.
- **Failover is cheap.** Primary + backup collectors on the same
  hub, poller switches on failure without losing data or tripping
  viewer visibility.

### Negative

- **Two schemas in flight** during the transition:
  `market_watched_locations` (poller driver) and `market_hubs`
  (policy + UX). Backfill handles existing rows; future inserts
  require both to stay in sync until the Python poller migrates.
- **Corp / alliance resolution is deferred.** The ENUM accepts
  `corp` and `alliance` values but the v1 policy service refuses
  to match them — it logs a warning if it sees any, to surface
  partial phase-2 rollouts.
- **Admins are not implicitly entitled** to every private hub.
  Intentional: the audit trail matters. Admins who need access for
  support / moderation must grant themselves an entitlement, which
  leaves a `granted_by_user_id` + `granted_at` paper trail.

### Neutral

- `users.default_private_market_hub_id` is an optional convenience.
  Users with exactly one entitlement get an implicit default in
  the UI without setting this field.
- Deletion of a hub cascades to collectors + entitlements but
  `RESTRICT`s on `market_watched_locations` — the poller's physical
  lane must be removed first, by design.

## Follow-ups

1. Livewire self-service page (`/account/market-hubs`): register,
   set default, revoke.
2. Filament admin resource for cross-hub audit + grant issuance.
3. Python poller update: pick collectors via
   `market_hub_collectors` with failover; retire
   `market_watched_locations.owner_user_id`.
4. Market page "context switch" UX (Jita only / Jita vs private /
   private only) + comparison panel backed by one Flux query.
5. Phase-2: character → corp / alliance resolver + group-sharing UX.
