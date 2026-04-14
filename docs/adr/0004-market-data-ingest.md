# ADR-0004 — Market data: dump import, live polling, and per-donor structure auth

**Status:** Accepted
**Date:** 2026-04-14
**Related:** [ADR-0001](0001-static-reference-data.md) (SDE),
[ADR-0002](0002-eve-sso-and-esi-client.md) (ESI + SSO),
[ADR-0003](0003-data-placement-freeze.md) (data placement freeze),
[AGENTS.md § Plane boundary](../../AGENTS.md#plane-boundary-policy-not-best-effort),
[docs/CONTRACTS.md](../CONTRACTS.md)

## Context

ADR-0003 named raw market observations as canonical-in-MariaDB and InfluxDB
series/rollups as derived. It deferred three concrete questions:

1. **What physical tables hold the raw observations?** Orders and history are
   different shapes (live order book vs. per-day aggregates) with different
   cardinality, retention, and uniqueness contracts. ADR-0003 did not settle
   whether one table or two.
2. **How do we get historical data in?** The ESI market-history endpoint
   returns 450 days per `(region_id, type_id)` pair — a full backfill of
   `~100 regions × ~14 000 types = ~1.4M pairs` at one request each is
   hundreds of thousands of rounds through the ESI rate limiter. Not
   feasible from any plane.
3. **How do we get data from player structures (Upwell citadels) whose
   markets are ACL-gated?** `GET /markets/structures/{structure_id}/`
   requires `esi-markets.structure_markets.v1` *and* the authorising
   character must have docking rights. No shared admin token can read
   arbitrary donor-selected structures; structure ACLs are alliance/corp
   bound.

This ADR settles all three.

## Decision

### Two tables, not one

`market_orders` and `market_history` are separate MariaDB tables. Putting
them under one roof with nullable columns was considered and rejected — see
Alternatives. Both follow ADR-0003's "canonical in MariaDB" rule and are
rebuildable inputs for derived series in InfluxDB.

- **`market_orders`** — one row per order observation. Live ESI order-book
  snapshots + any future bulk order-book dumps land here. Uniqueness is
  `(source, location_id, order_id, observed_at)` so multi-source
  provenance is unambiguous; `source` is a human-readable provenance
  string (e.g. `esi_region_jita_4_4`, `esi_structure_1035466617946`,
  `everef_orders_2025_10_12`). `observation_kind` enum
  (`snapshot | incremental_poll | historical_dump`) classifies the
  ingestion path for downstream retention/aggregation rules.
- **`market_history`** — one row per `(trade_date, region_id, type_id)`.
  Mirrors EVE Ref's published schema so their daily CSV dumps import
  with minimal normalisation. Column `trade_date` instead of `date` to
  avoid the reserved-word foot-gun. Primary key
  `(trade_date, region_id, type_id)` — same uniqueness contract
  upstream uses.

Both tables are monthly `RANGE` partitioned on their time axis
(`observed_at` / `trade_date`). Partitioning is load-bearing, not a
casual optimisation: it's how retention happens (drop a partition, not
a `DELETE` scan), and it's how query pruning keeps read costs bounded
as the table grows. MariaDB requires the partition column to appear in
the PK — both schemas are designed around that constraint.

### Historical backfill: EVE Ref dumps, Python importer

Full history imports come from [EVE Ref's market history dataset](https://data.everef.net/market-history/).
File layout: `https://data.everef.net/market-history/{YYYY}/market-history-{YYYY-MM-DD}.csv.bz2`,
one file per day, all regions and all types in that day's file,
bzip2-compressed CSV (~600 KiB / day). Completeness metadata at
`https://data.everef.net/market-history/totals.json`.

A new Python worker lives at `python/market_importer/` as a sibling to
`sde_importer` (ADR-0001) and `graph_universe_sync`. Logic ports from
EVE Ref's Java/PostgreSQL [`import-market-history`](https://docs.everef.net/commands/import-market-history.html)
command, adapted to MariaDB (no Flyway — we already have Laravel
migrations owning schema; no PostgreSQL `ON CONFLICT` — we use
`INSERT ... ON DUPLICATE KEY UPDATE`). Loop:

1. Query `SELECT trade_date, region_id, COUNT(*) FROM market_history
   GROUP BY trade_date, region_id;` to inventory what's already loaded.
2. Compare against `totals.json` from EVE Ref. Any day whose local
   row count is less than the published total — or zero — is a
   download target.
3. For each target day: stream the `.csv.bz2` file, decode inline,
   bulk insert via `pymysql.executemany` in batches of 5 000 rows
   (env-tunable via `MARKET_IMPORT_BATCH_SIZE`). Per-day transaction
   so a partial-day failure rolls back cleanly.
4. Emit an `outbox` event `market.history_snapshot_loaded` with
   `{from, to, days, rows}`.

Why Python and not Laravel: same reason as SDE import (ADR-0001 § Plane
boundary). A full `2025 → today` backfill is ~470 days × ~3 000 rows
each ≈ 1.4M rows in one run. Violates the <100 rows / <2 s Laravel
queue budget by orders of magnitude.

Why our own importer and not EVE Ref's: EVE Ref's ships Java + Flyway
+ PostgreSQL/H2 only; MySQL/MariaDB is "planned" but not available. We
have a Python execution plane already, we can port the logic in a few
hundred lines, and that keeps our toolchain homogeneous.

### Live polling: Python plane from day one

Jita's live order-book snapshot and any structure polling both run in
the Python execution plane per ADR-0002 § ESI client. No Laravel-side
market poller is built as a stepping stone; that would invite the
exact rule-creep ADR-0002 was written to prevent.

- **Jita (platform baseline, always-on, unauthenticated):**
  `GET /markets/{region_id}/orders/` with `region_id = 10000002`
  (The Forge), then filter `location_id = 60003760` (Jita 4-4) before
  insert. A seeded row in `market_watched_locations` with
  `owner_user_id = null`, `location_type = npc_station`,
  `region_id = 10000002`, `location_id = 60003760`, `enabled = true`
  is the scheduler's driver. The row is undeletable by design
  (enforced at the model layer; Jita is not optional).

- **NPC stations beyond Jita (admin-managed platform defaults):**
  same pattern as Jita — region endpoint + location filter, no auth.
  Stored in `market_watched_locations` with `owner_user_id = null`,
  `location_type = npc_station`. Managed by admins from
  `/admin/market-watched-locations`.

- **Player structures (admin-selected, via service character):**
  `GET /markets/structures/{structure_id}/` using the admin's
  `eve_service_tokens` row. Only works for structures the admin's
  service character has docking rights at. Stored in
  `market_watched_locations` with `owner_user_id = null`,
  `location_type = player_structure`.

- **Player structures (donor-selected, via donor's own token):**
  same endpoint, different token — each donor authorises their own
  character via a fourth SSO flow and the token lands in a new
  `eve_market_tokens` table. Stored in `market_watched_locations`
  with `owner_user_id = <donor's user_id>`,
  `location_type = player_structure`.

### Structure access is alliance/corp-gated — invariant

Upwell structure market reads require that the authorising character has
docking rights at the structure, which is an ACL granted by the
structure owner to individual characters / corps / alliances. There is
no technical path by which a single admin-held token can poll arbitrary
donor-selected structures. This is an invariant, not a preference:

> Each donor authorises their own character. The donor's
> `eve_market_tokens` row is keyed on their character ID, bound to
> their AegisCore user, and every poll against a donor-owned
> `market_watched_locations` row uses that donor's token. The
> admin service-character token (`eve_service_tokens`) is the
> fallback only for platform-default structures the admin's character
> genuinely has access to.

Structure *discovery* is also ACL-gated. The `/account/settings`
picker that lets a donor add a structure is backed by
`GET /characters/{donor_character_id}/search/?categories=structure`
using the donor's own token. ESI only returns structure IDs the
character can see. The system never accepts free-form structure IDs
— the only insert path into `market_watched_locations` is via an ID
that the donor's own token just resolved inside the same request.
This closes "what if someone POSTs an arbitrary ID" by construction.

### Token ownership enforced at read/use, not just at callback

`eve_market_tokens` carries `character_id` (the EVE character whose
ACL the token embodies) and `user_id` (the AegisCore user who
authorised it). The poller reads a `market_watched_locations` row and,
before every ESI call, asserts:

> `token.user_id == watched_location.owner_user_id` AND
> `token.character_id` is one of the linked characters of that user.

Mismatch is a security violation — logged at `warning` with token +
location + user IDs, row immediately disabled, not routine error
handling. Callback-time validation alone is insufficient because a
user could later be unlinked from their character, leaving the token
row floating unattached to an active account.

### Failure handling: grace before disable, hard-close on security

- **Routine ESI failures** (403 "no access", 5xx, timeouts): increment
  `consecutive_failure_count` on `market_watched_locations`, record
  `last_error` + `last_error_at`. Auto-disable after
  **3 consecutive 403s** or **5 consecutive 5xx/timeouts**. A single
  success resets the counter. On auto-disable, set `enabled = false`
  and populate `disabled_reason`; the donor gets one email + a
  `/account/settings` banner, not a 403 flood.

- **Security-boundary failures** (token ↔ location ownership mismatch,
  token scope set missing a required scope): immediate disable, no
  grace counter. These are not transient conditions.

### Filament / frontend split

- `/admin/market-watched-locations` (Filament, admin-only) — manages
  platform-default rows (`owner_user_id = null`). Jita is visible but
  read-only.
- `/account/settings` (Livewire, auth required) — user settings surface,
  grows over time. Market-data section is donor-gated (`User::isDonor()`):
  "Authorise market data" button kicks off the fourth SSO flow,
  structure picker is a thin wrapper around the donor's own
  `/characters/{id}/search/?categories=structure`, add/remove manages
  their own rows in `market_watched_locations`.

A separate donor-facing Filament panel was rejected — heavier to build,
blurs the admin/donor surface boundary. Livewire at `/account/settings`
is enough and leaves admin tooling where admins expect it.

## Alternatives considered

- **One `market_observations` table for both orders and history.**
  Rejected. Orders are point-in-time snapshots with `order_id` and
  buy/sell split; history is daily aggregates with
  `average`/`highest`/`lowest`/`volume`/`order_count` and no per-order
  key. The shapes share so few columns that a unified table would be
  mostly-nullable and every query would start with a filter on
  "which shape am I looking at". False abstraction.

- **Shared admin token reads all donor structures.** Rejected by the
  alliance/corp-gating invariant above. Not a preference, a
  technical impossibility for arbitrary donor outposts.

- **Free-form structure ID entry on `/account/settings`.** Rejected.
  Trivially bypasses the ACL gate that's doing real work for us
  everywhere else. Picker-only, IDs must come back from the donor's
  own search response within the same request.

- **Laravel-side Jita poller as a phase-1 stepping stone.** Rejected.
  ADR-0002 explicitly puts "markets at scale" on the Python plane;
  "scale" starts the moment a second structure is added beyond Jita,
  and there's no clean stopping point for migrating the one-region
  case back out of Laravel once callers depend on it.

- **Use EVE Ref's `import-market-history` command directly.**
  Rejected. It's Java + Flyway + PostgreSQL/H2-only; MariaDB support
  is "planned" but not shipped. Porting the logic to Python is cheap
  and keeps our execution plane homogeneous with `sde_importer` and
  `graph_universe_sync`.

- **Unified `eve_tokens` table with a `kind` column instead of a new
  `eve_market_tokens`.** Rejected for the same reason ADR-0002 gave
  when splitting donations tokens from service tokens: schema-level
  boundaries catch SQL-typo-class bugs that a `WHERE kind = ...`
  clause cannot.

## Consequences

**Positive.**

- History and order data have clean, independent retention and
  aggregation policies. Dropping an old month of `market_orders`
  doesn't touch `market_history`.
- Historical backfill is a one-off `make market-import` that reuses
  the already-proven Python-plane pattern from `sde_importer`.
- Structure market access has no shared-trust gap: donor tokens
  can only read what the donor can already see, and the poller
  fails closed on any ownership mismatch.
- Jita's live prices are available from day one with zero auth
  complexity — the lowest-risk validation path for the whole data
  model.
- `/account/settings` lands as a durable user surface; market access
  is the first section and the surface can accrete notifications,
  alt linking, API keys later without a route shuffle.

**Negative.**

- Four new tables land together (`market_orders`, `market_history`,
  `market_watched_locations`, `eve_market_tokens`). Bigger schema
  delta than ADR-0001 or ADR-0002; the separation is paid for later
  when each table's retention/uniqueness policy diverges.
- Donor token refresh joins the list of distributed-lock candidates
  ADR-0002 § phase-2 #12 flagged. Single-scheduler assumption holds
  for phase 2; the row-level advisory-lock pattern noted there
  applies here verbatim when we scale past one scheduler instance.
- EVE Ref becomes a fourth external dependency (alongside CCP's SDE,
  ESI, and the SSO endpoints). Their dataset is mirror-stable and
  the importer runs against a local copy after first fetch, but the
  initial download path is a single-source-of-truth risk worth
  calling out.

**Neutral.**

- No ESI scope changes for admin service character; the
  `esi-markets.structure_markets.v1` + `esi-search.search_structures.v1`
  + `esi-universe.read_structures.v1` set is already in
  `config/eve.php` as the service-scope default.
- The new donor SSO flow is the fourth reuse of the existing
  `/auth/eve/callback` endpoint and follows the donations-flow
  shape ADR-0002 § phase-2 amendment established.

## Follow-ups (not part of this ADR)

- `python/market_importer/` (historical dump importer, port of EVE
  Ref's Java command).
- `python/market_poller/` (Python execution-plane live poller:
  Jita first, then admin structures, then donor structures).
- `App\Domains\Markets\Models\*` + `App\Filament\Resources\MarketWatchedLocationResource`
  (admin surface).
- Fourth SSO flow: `/auth/eve/market-redirect` + `finishMarketFlow()`
  on `EveSsoController` + `eve_market_tokens` model with encrypted
  cast.
- `/account/settings` Livewire page + structure picker endpoint.
- Donor email template for auto-disable + banner on `/account/settings`.
- Distributed lock for token refresh when/if a second scheduler
  instance lands.
- Retention job: drop `market_orders` partitions older than N months
  (default 6), `market_history` partitions older than M months
  (default 24). Actual values deferred to the retention-job PR.
