# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **Retired `market_watched_locations.owner_user_id` (closes ADR-0005
  Follow-up #3).** The poller stopped reading the column two commits
  ago; this pass refactors every remaining reader/writer and drops
  the column from the schema. Classification ("platform default" vs
  "donor-registered") now lives exclusively on
  `market_hubs.is_public_reference`; donor ownership is derived from
  `market_hub_collectors.user_id`. Changes:
  - **`MarketWatchedLocation` model.** Dropped `owner_user_id` from
    `$fillable` + casts + `owner()` relation. Added `hub()` belongsTo
    + new scopes `publicReference()` / `private()` / `forCollector($userId)`
    that replace `scopePlatformOwned` / `scopeDonorOwned`. Jita
    delete-guard + `isJita()` now key on `(region_id, location_id)`
    alone — the owner-null check was a belt-and-braces line that
    couldn't actually catch anything ADR-0005's hub-level guard
    doesn't already prevent.
  - **`AccountSettings` Livewire component.** `watchedStructures()`
    uses the new `forCollector` scope (hub → collector join).
    `addStructure()` upserts the watched row by `hub_id` (one
    polling lane per hub, per ADR-0005 § Registration flow) and
    clears any prior `disabled_reason` / `is_active = false` on the
    hub so a donor re-auth naturally un-freezes a
    `'no_active_collector'` hub. `removeStructure()` changes
    semantics: it deletes the donor's collector + self-entitlement
    on the target hub rather than deleting the watched row — if
    other donors still collect, polling continues for them; if this
    was the last collector, the poller naturally freezes the hub on
    its next tick.
  - **Filament admin surface.** `MarketWatchedLocationResource`
    swaps the owner column + owner filter for a classification
    badge / filter sourced from `hub.is_public_reference`. The
    `CreateMarketWatchedLocation` page now `firstOrCreate`s a
    public-reference hub for the entered `(location_type,
    location_id)` and points the new watched row at it — closes
    the same "admin create leaves hub_id NULL" gap the donor flow
    had before this PR.
  - **Migration `2026_04_16_600000`.** Deduplicates any hub with
    multiple watched rows (no-op in current prod; safety net for
    other envs), drops the legacy `uniq_watched_owner_location`
    + owner FK + column, promotes `hub_id` to NOT NULL, adds
    `UNIQUE(hub_id)` so the "one polling lane per physical
    market" invariant is DB-enforced. Fully idempotent — each
    step checks current state via `information_schema` and
    skips if already applied. Incidentally fixed a latent bug in
    migration `2026_04_14_000013`'s `down()` that tried to drop
    `idx_watched_hub` before `dropConstrainedForeignId('hub_id')`
    (would have failed with SQLSTATE HY000/1553 on any rollback
    exercise).
  - **Unit tests.** `MarketWatchedLocationTest` updated to match
    the new model — removed the "donor-owned row sharing Jita's
    location_id" test (physically impossible post-migration: one
    hub per `(location_type, location_id)`; Jita's hub is
    public-reference by seed).
  - **Docstring drift.** `EveMarketToken` + `python/market_poller/config.py`
    docstrings updated to point at `market_hub_collectors.user_id`
    as the ownership invariant.

  Verified live: migration ran cleanly against the production DB
  (`owner_user_id` column dropped, `UNIQUE(hub_id)` in place);
  scheduler continues polling both Jita and the WinterCo private
  hub without a restart needed (the poller already stopped reading
  the column in the previous commit).

- **Market poller picks tokens via `market_hub_collectors` instead of
  `market_watched_locations.owner_user_id` (ADR-0005 § Follow-up 3).**
  Closes the shared-trust gap ADR-0005 opened the canonical-hub model
  to solve: private hubs are now polled by walking the hub's collector
  list, not by "whoever seeded the row". Functional wins visible at
  day one:
  - **Donor churn is survivable.** If Donor A registered Keepstar X
    and later leaves, the hub stays live as long as any other
    collector is active — the viewers never see the seam. A fresh
    donor re-auth attaches a new collector; no viewer entitlement
    changes.
  - **Within-tick failover.** On transient/permanent ESI failure or
    scope/refresh/ownership errors, the poller records the failure
    against that specific collector and falls over to the next
    active collector in the same pass. Collectors are ordered
    `is_primary DESC, last_failure_at IS NULL DESC, last_failure_at
    ASC` so a rested backup beats a just-burned one. Previously a
    single-token failure meant a five-minute wait for the next
    scheduler tick.
  - **Per-collector failure bookkeeping.** `consecutive_failure_count`,
    `last_failure_at`, `failure_reason`, and `is_active` move from
    `market_watched_locations` onto each `market_hub_collectors`
    row. Same 3×403 / 5×5xx auto-deactivate thresholds. A hub-wide
    counter would lie when the poller alternated between a dead
    primary and a live backup; per-collector state is the honest
    representation.
  - **Hub freeze semantics per ADR-0005.** When every active
    collector for a hub has been exhausted and none remain active,
    `market_hubs.disabled_reason` is set to `'no_active_collector'`
    WITHOUT flipping `is_active` — an admin or donor re-auth can
    rescue the hub. The SELECT filters out frozen hubs at the driver
    level so a stuck hub doesn't burn round-trips per tick. A
    successful poll clears the freeze, denormalises the serving
    collector's `character_id` onto
    `market_hubs.active_collector_character_id`, and bumps
    `last_sync_at`.
  - **Trust-boundary check at use, not just at SELECT.** The old
    user-keyed token loader filtered `eve_market_tokens WHERE
    user_id = ? ORDER BY updated_at DESC LIMIT 1`. The new loader
    (`load_and_refresh_market_token_by_id`) keys directly on the
    collector's `token_id`, and the runner asserts
    `token.user_id == collector.user_id` before every ESI call.
    Mismatch → immediate `disable_collector_immediately` with
    reason `ownership_mismatch` (security violation, no grace).
  - **`_MarketTokenCache` rekeyed from user_id to token_id.** A
    donor collecting on multiple hubs with one token still pays the
    refresh round-trip once per pass; errors memoise per token so
    a broken token doesn't re-attempt across collector rows.

  Untouched by this change:
  - NPC station path (region endpoint, no auth, Jita baseline).
  - Admin-managed public-reference structure path (service-token,
    still in `market_watched_locations` bookkeeping — these hubs
    have no collectors by design).
  - `market_watched_locations.owner_user_id` column itself. The
    poller no longer reads it, but the column stays until a
    follow-up ADR retires it. A CHANGELOG entry for that drop will
    land alongside the migration.

  Verified end-to-end: live scheduler pass after rebuild polls Jita
  (public-reference NPC hub, 415 ESI pages → 335k orders inserted)
  AND WinterCo 4-HWWF Central Station (private hub, donor collector,
  36,499 orders inserted via `source=esi_structure_...`). Both paths
  emit `market.orders_snapshot_ingested` outbox events. Unit-test
  surface unchanged (19 tests green).

- **Related: `AccountSettings::addStructure()` now creates the
  canonical hub + attaches the donor as a collector + grants a
  self-entitlement, atomically alongside the `market_watched_locations`
  upsert.** Pre-existing gap surfaced by the poller migration: the
  original Livewire picker only inserted into
  `market_watched_locations`, so every post-ADR-0005 donor add
  landed with `hub_id = NULL`. The old poller (reading
  `owner_user_id`) coped; the new poller's `JOIN market_hubs`
  would silently drop those rows. `addStructure()` now mirrors the
  backfill migration's logic — `firstOrCreate` the hub by
  `(location_type, location_id)`, `updateOrCreate` the watched row
  with `hub_id`, `updateOrCreate` the collector (promoted to
  primary if the hub has no primary yet), and `updateOrCreate` the
  self-entitlement. All four writes wrapped in one transaction so
  a half-landed add doesn't leak a hub without a watched row or
  vice versa. Idempotent on re-add, so a donor toggling Watch /
  Remove / Watch on the same structure doesn't duplicate anything.

- **Post-deploy fix: `market_hubs.last_sync_at` now populates for
  public-reference hubs too.** The initial cut of
  `record_hub_sync_success` only ran for private hubs (the code
  path owned `active_collector_character_id` denormalisation and
  wasn't called for service-token / NPC polls). The migration
  header is explicit that `last_sync_at` tracks "the most recent
  successful poll against this hub, regardless of which collector
  did it" — so Jita and other public-reference hubs need it bumped
  on every pass. Function now branches: `character_id = None` →
  bump `last_sync_at` + clear `disabled_reason`;
  `character_id = int` → additionally denormalise
  `active_collector_character_id`. Verified against live DB after
  rebuild.

- **Post-deploy diagnostic: poller warns on `hub_id IS NULL`
  watched rows at the start of every pass.** After the
  `addStructure()` fix above + the existing row backfill, every
  watched row points at a hub. Any future regression that breaks
  the invariant would previously have been silent (the JOIN'd
  SELECT drops the row); it's now loud (one WARN per pass with
  count + remediation hint). Cheap — one `SELECT COUNT(*)` per
  tick, indexed on `enabled`.

### Fixed
- **Killmail backfill no longer cascades `(0, '')` warnings when the
  MariaDB connection dies mid-day.** Root cause: the backfill held
  ONE transaction per day and looped through every killmail in the
  archive inside it. When the socket died (MariaDB restart, idle
  timeout, transient network blip), every subsequent INSERT on that
  dead connection returned `OperationalError(0, '')`, the
  `except Exception` in the per-killmail loop logged "skipping
  killmail", and the cascade flooded the log until the day's loop
  ended. The live stream (`stream.py`) already had the right shape
  — per-killmail commit + reconnect on `(0, '')` / 2006 / 2013 /
  2014 / 2055; backfill now does the same. Changes:
  - **`killmail_ingest/db.py`**: extracted `is_connection_lost()` +
    added `is_retryable_server_abort()` (errnos 1213 deadlock / 1205
    lock-wait-timeout) as shared helpers. `open_connection()` is the
    single source of truth for the pymysql param set so stream +
    backfill can't drift on timeouts / cursor class.
  - **`killmail_ingest/backfill.py`**: rewrote `_process_day` to
    commit per killmail rather than per day. New `_ConnHolder`
    pattern lets the runner transparently reassign the live
    connection after a reconnect. On deadlock / lock-wait: rollback
    and retry up to 3× with jittered backoff (`_RETRY_BASE_DELAY` +
    random jitter up to `_RETRY_JITTER` seconds). On connection
    loss: close + reopen + skip the current killmail (idempotent
    re-ingest on the next pass via the existing `INSERT ... ON
    DUPLICATE KEY UPDATE` on `killmails` + DELETE/INSERT on
    `killmail_attackers` / `killmail_items`). On parse or schema
    errors: log + skip, as before.
  - **Consistent lock order with the live stream.** Backfill now
    sorts each day's killmails by `killmail_id ASC` before
    iterating. R2Z2 delivers in sequence order, which maps roughly
    to killmail_id order — aligning the two processes' lock
    acquisition order keeps deadlock probability low even when both
    are writing the same recent killmails during the recheck window.
  - **Partial days deliberately don't persist state.** If a day has
    any failed killmails, `everef` state for that day is NOT
    written, so the next pass re-downloads + re-ingests. Re-ingest
    is a no-op for rows already present (idempotent); the only
    cost is bandwidth + time.
  - **Stream coroutine side-effect**: `stream.py` had its own
    `_is_connection_lost` + `_connect` helpers; those are now
    imports from `db.py`. No behavioural change, just DRY.

  Verified post-rebuild: `killmail_backfill_scheduler` processed
  day 2025-02-18 cleanly — 13,653 killmails, per-row commit, no
  cascade, no reconnect triggered (connection stayed healthy for
  the whole day). 422 days queued in the current pass from
  accumulated partial state; each one now fully transactional at
  the killmail level.

- **Standings sync 404s on both corp and alliance contacts.** The
  original corp endpoint path (`/latest/corporations/{id}/contacts/`)
  was wrong — CCP never exposed that under the legacy versioned ESI.
  The alliance endpoint is present at `/latest/` but being phased
  out, and 404s for some donors. Rewired the fetcher to hit the new
  unversioned ESI (`esi.evetech.net`, no `/latest/`) with the required
  `X-Compatibility-Date` header; both endpoints resolve there.
- **Existing market tokens lacked the new scopes.** Re-authorising on
  `/account/settings` now re-requests the correct scope set; the UI
  surfaces `esi-characters.read_contacts.v1` in the missing-scopes
  nudge so the character-fallback path also gets consented.

### Added
- **`X-Compatibility-Date` support in the shared ESI client.**
  `EsiClientInterface::get()` now takes an optional `$headers` param,
  threaded through `EsiClient` (with reserved-header protection) and
  `CachedEsiClient` (headers folded into the payload cache key so a
  compat-date bump can't replay a stale body from the old shape).
  Unlocks per-URL migration to the new unversioned ESI without
  flipping `ESI_BASE_URL` for every caller at once.
- **Character-contacts fallback for standings sync.** Solo NPC-corp
  donors and characters without an alliance used to land with an
  empty standings table. The fetcher now falls back to
  `GET /characters/{id}/contacts` (scope
  `esi-characters.read_contacts.v1`) when both corp and alliance
  lists come back empty or unreachable. The display filter on
  `/account/settings` still hides `contact_type = 'character'` rows
  regardless of owner bucket — personal grudges never render.
- **Contact labels per standings row.** Sibling `*/contacts/labels`
  endpoint is now pulled for each owner into a new
  `character_standing_labels` table (keyed by
  `owner_type, owner_id, label_id`). The settings UI renders label
  chips next to each contact — useful visual grouping when an
  alliance tags certain blues with labels like "Staging" or "SRP-ok",
  and ready to feed the battle-report friendly/enemy fine-tune later.

- **Corp / alliance standings display on `/account/settings`.** A new
  read-only section under the market-data card lists the standings
  the donor's corporation and alliance hold toward other corps,
  alliances, and factions — the authoritative "who's friendly / who's
  hostile" signal for the upcoming automatic battle-report feature.
  - Per donor character but keyed to corp/alliance (same data-sharing
    pattern as `market_hub_entitlements` § ADR-0005): any donor in
    the corp can sync, all donors in the same corp/alliance see the
    same list.
  - Individual-character contacts are deliberately **not** shown —
    personal grudges stay off the shared surface. Only corporation,
    alliance, and faction contact types reach the UI.
  - Fetched via `/corporations/{id}/contacts/` and
    `/alliances/{id}/contacts/` using the donor's existing market
    token (two new ESI scopes added to `market_scopes`:
    `esi-corporations.read_contacts.v1`,
    `esi-alliances.read_contacts.v1`). Corp contacts require
    Personnel_Manager / Contact_Manager — a line-member donor sees a
    clear "role missing" skip message; their alliance half still
    succeeds.
  - A "Sync standings now" button runs the fetch synchronously for
    immediate feedback; a daily `eve:sync-standings` scheduled job
    (04:00 UTC, `routes/console.php`) walks every donor token to
    keep the table fresh. Existing donor tokens predate the new
    scopes — the UI prompts a re-authorise before the button appears.
  - Battle-report contract: donor / admin reports cross-reference
    participants against `character_standings` to tag friendly
    (standing ≥ +5) / enemy (≤ −5) / neutral. Non-donor manual
    reports ignore this table and use Team A / Team B. Automatic
    report generation is donor-gated — no donor token, no auto
    reports.
  - Backfill side-effect: sync also mirrors the donor character's
    current `corporation_id` / `alliance_id` into the `characters`
    table, acting as the donor-self affiliation poller the ADR-0002
    phase-2 roadmap flagged.
- **Landing-page nav strip for authenticated users.** Logged-in users
  now see a thin horizontal nav between the header and the hero, with
  links to the surfaces that currently exist for them:
  - `Account` — the donor / market-data authorisation surface.
  - `Admin` — visible only to users who pass `User::isAdmin()`
    (gold-accented to mirror the existing `Admin →` CTA).
  The nav deliberately ships with only live destinations — ghost /
  coming-soon entries are a review-blocker. Add more as the
  corresponding pages land.
- **Searchable player-structure picker in the admin create form.**
  The `/admin/market-watched-locations` create flow no longer requires
  pasting a 13-digit Upwell ID and a separate region ID. Operators
  type a system name (e.g. `4-HWWF`) or structure-name fragment and
  pick from a dropdown of structures the service character has
  docking rights at, backed by ESI
  `/characters/{id}/search/?categories=structure`. Region is auto-
  resolved from the selected structure's `solar_system_id` →
  `ref_solar_systems` join; display name is pre-filled from the
  `/universe/structures/{id}/` payload.
  - Discovery is ACL-gated by design: a structure that doesn't
    appear in the search is one the platform genuinely can't poll.
    Surfacing that at picker time is better UX than accepting any
    ID and auto-disabling after a 403 sweep.
- **`App\Services\Eve\ServiceTokenAuthorizer`** — Laravel-side fresh-
  access-token helper for `EveServiceToken`. Mirrors
  `MarketTokenAuthorizer`: row-locked refresh under
  `SELECT ... FOR UPDATE` so Python and Laravel can't both refresh
  the same row in parallel and lose the loser's refresh_token to a
  CCP-side single-use invalidation. Enables Laravel-plane interactive
  ESI calls through the admin service character (starting with the
  structure picker above).

### Changed
- **`StructurePickerService` is now token-agnostic.** The `search()`
  and `resolve()` methods take `(int $characterId, string $accessToken,
  ...)` instead of an `EveMarketToken`. Callers run the token through
  `MarketTokenAuthorizer` / `ServiceTokenAuthorizer` themselves. This
  lets the donor Livewire picker (donor's `eve_market_tokens` row) and
  the admin Filament picker (platform's `eve_service_tokens` row)
  share one code path.
- **MariaDB override mount moved to `mariadb/conf.d/aegiscore.cnf` (tracked).**
  The previous mount source was `$AEGISCORE_ROOT/docker/mariadb/config`,
  a gitignored runtime-state directory that nobody ever populated — so
  operators trying to read or edit MariaDB settings from the host found
  an empty dir and assumed the bind-mount was broken. The override source
  now follows the same convention as `php/conf.d/aegiscore.ini` and
  `nginx/conf.d/aegiscore.conf`: a tracked file at the repo root, mounted
  read-only into the container at `/etc/mysql/conf.d/`. The packaged
  MariaDB defaults (`50-server.cnf` etc.) still live inside the image at
  `/etc/mysql/mariadb.conf.d/` and are deliberately not bind-mounted.
  `make bootstrap` no longer creates `docker/mariadb/config/`. Operators
  upgrading an existing deployment: move any custom `*.cnf` files from
  `$AEGISCORE_ROOT/docker/mariadb/config/` into the tracked
  `mariadb/conf.d/` (and commit them) before `make restart mariadb`.
- **MariaDB tuned for a 16 GiB memory budget (`mariadb/conf.d/aegiscore.cnf`).**
  First real content in the new override file. `innodb_buffer_pool_size=12G`
  takes ~75 % of the budget; the remaining ~4 GiB covers per-connection
  buffers, temp tables (`tmp_table_size=64M`, `max_heap_table_size=64M`),
  and OS / library overhead. InnoDB I/O knobs tuned for SSD
  (`innodb_io_capacity=2000`, `_max=4000`, `innodb_flush_method=O_DIRECT`);
  durability left at the ACID default (`innodb_flush_log_at_trx_commit=1`)
  per ADR-0003's "MariaDB is canonical" posture. `max_connections=300`
  covers php-fpm + Horizon + scheduler + Python pollers with headroom,
  and `skip-name-resolve` drops the reverse-DNS round trip on auth since
  all callers live on the internal `aegiscore` Docker network with no
  PTR records. Slow-query log turned on at `long_query_time=1.0` into
  `/var/log/mysql/slow.log` (bind-mounted to
  `$AEGISCORE_ROOT/docker/mariadb/logs` for host-side logrotate).
  Operators on smaller hosts should shrink `innodb_buffer_pool_size`
  first, then `max_connections`; operators on ZFS should switch
  `innodb_flush_method` to `fsync` so the buffer pool doesn't fight
  the ARC; operators on spinning disk should drop `innodb_io_capacity`
  and `_max` to ~200 / 400 so the flusher doesn't saturate the device.

### Added
- **Private Market Hub overlay — policy foundation (ADR-0005).**
  Donor / admin-only feature laying canonical-hub identity on top
  of the existing `market_watched_locations` driver table. Three
  new tables:
  - **`market_hubs`** — one row per unique
    `(location_type, location_id)`. Jita gets backfilled as
    `is_public_reference = true`; future donor-registered
    structures land here as private hubs. Deletable only when not
    the Jita baseline (mirrors `MarketWatchedLocation`'s guard).
  - **`market_hub_collectors`** — zero-or-many tokens authorised
    to poll a hub. Carries `is_primary`, `is_active`, and failure
    bookkeeping (`last_failure_at`, `consecutive_failure_count`,
    `failure_reason`) per ADR-0005 § Failover: the poller cycles
    to a backup collector on ACL / 5xx failure and only freezes
    the hub when zero active collectors remain.
  - **`market_hub_entitlements`** — viewer grants. ENUM
    `subject_type` = `user | corp | alliance` pre-wired for
    phase-2 group sharing; v1 matches `user` only and logs a
    warning if it sees group grants without a resolver.
- **`MarketHubAccessPolicy`** — single chokepoint for the
  intersection rule `can_view = (isDonor || isAdmin) AND
  has_hub_access`. Public-reference hubs short-circuit. Exposes
  `canView(User, MarketHub)`, `visibleHubsFor(User): Builder`,
  and a lockstep test asserting both agree on the same fixture
  set per ADR-0005.
- **`User::isAdmin()`** — factored out of `canAccessPanel()` so
  non-Filament feature policies (starting with the market hub
  overlay) can check admin status without a Panel object. Preserves
  the phase-1 escape hatch (no-linked-characters → admin) so
  operator-seeded bootstrap accounts keep working.
- **`users.default_private_market_hub_id`** — optional per-user
  preference for the pinned comparison target. Entitlement loss
  silently demotes the UI to "no default", never a hard error.
- **`market_watched_locations.hub_id`** — nullable FK into the new
  canonical table, backfilled for every existing row. Poller
  migration to pick tokens via `market_hub_collectors` is a
  follow-up.
- See docs/adr/0005-private-market-hub-overlay.md.
- **`python/outbox_relay/` — first concrete consumer of the
  MariaDB outbox; projects market events into InfluxDB.** Per
  ADR-0003 § InfluxDB and ADR-0004 § Live polling, InfluxDB is a
  derived store written by Python. Until now the
  `market.history_snapshot_loaded` and
  `market.orders_snapshot_ingested` outbox events were piling up
  with no consumer. The new long-lived `outbox_relay` container
  drains them on a 5-second poll cadence and writes typed
  measurements to InfluxDB.
  - **Two projectors registered in
    `projectors/dispatch.py::PROJECTOR_REGISTRY`:**
    - `market.history_snapshot_loaded` →
      measurement **`market_history`** (one point per
      `(region_id, type_id)` per `trade_date`, 1:1 from MariaDB
      rows). Cardinality: ~1.4M unique series total.
    - `market.orders_snapshot_ingested` →
      measurement **`market_orderbook`** (one point per
      `(type_id, side)` per snapshot — *aggregates*, not per-order
      points). Fields: `best_price`, `weighted_avg_price`,
      `total_volume_remain`, `order_count`. Aggregation chosen
      over raw-order points because per-`order_id` series would
      blow up cardinality and never get queried.
  - **Claim pattern: `SELECT ... FOR UPDATE SKIP LOCKED LIMIT N`**
    per CONTRACTS.md. Filtered by `event_type IN (known projectors)`
    so unknown event types stay in the queue for future projector
    deployments — adding a Neo4j or OpenSearch consumer later
    doesn't require re-emitting events.
  - **Dead-letter discipline.** Projector failures bump
    `attempts` + record `last_error` on the row but don't poison
    the batch. After `OUTBOX_RELAY_MAX_ATTEMPTS` (default 5) the
    row stops being claimed; operator resets `attempts=0` to
    retry after fixing the root cause.
  - **Two-connection design.** Outbox-claim connection
    (`autocommit=False`, holds the row lock); projector-read
    connection (`autocommit=True`, runs the
    `market_history` / `market_orders` SELECTs). Separates the
    claim transaction from the projector's potentially-long
    DB read so the row lock isn't held for the whole projection.
  - **CLI mirrors the established pattern:** `--interval N`
    (loop forever, sleep N seconds when idle) for the long-lived
    container; `--interval 0` (default) drains the queue once and
    exits — what `make outbox-relay` invokes via the `tools`
    profile sibling service `outbox_relay_oneshot`. Both share
    the same image (`aegiscore/outbox-relay:0.1.0`).
  - Ships with `python/outbox_relay.Dockerfile`,
    `python/requirements-outbox-relay.txt` (httpx-free; uses
    `influxdb-client~=1.45` + `pymysql~=1.1`), two compose
    services (long-lived + tools-profile one-shot), and a
    `make outbox-relay` target.
  - **Adding a new projector** is a 2-line change: drop a module
    under `projectors/`, add one entry to `PROJECTOR_REGISTRY`.
    The relay framework itself never knows about specific event
    types.

### Fixed
- **Donors occasionally lost ad-free status for no visible reason;
  admin page showed "No active donors yet" despite a fresh
  `player_donation` row in the ledger.** `PollDonationsWallet`
  recomputes per-donor benefits in-line after each upsert, but only
  for donors whose `journal_ref_id` is brand-new on the current tick.
  If anything between `upsertDonations()` and the recompute loop threw
  — most easily `resolveDonorNames()`'s `DB::transaction` on a
  transient connection hiccup, which has no catch at the `handle()`
  level — the donation row persisted but the matching
  `eve_donor_benefits` row was never written. On the next tick the
  `journal_ref_id` was no longer fresh, so the recompute was
  permanently skipped and only a manual `php artisan
  eve:donations:recompute` repaired it. Scheduled the existing
  artisan command hourly as a safety net (`eve-donations-recompute`
  task in `routes/console.php`) — donor base is dozens of characters
  and each recompute is microseconds, so a full rebuild every hour is
  free and self-heals any orphaned benefits within one tick of an
  observer noticing.
- **`market_poll_scheduler` crashed on every restart with
  `unrecognized arguments: --interval 300`.** Compose's default
  pull/build policy was reusing the locally-cached
  `aegiscore/market-poller:0.1.0` image — which predated the
  `--interval` CLI flag — even though the on-disk Python source had
  the new flag. Two-part fix: (1) bumped the image tags to
  `0.1.1` so `compose up` rebuilds them on the next run; (2) added
  `--build` to `make update`'s `compose up` step so future Python
  source changes don't get cached-out the same way (cheap thanks to
  Docker layer caching when nothing changed).
- **`market_import_scheduler` crashed every tick with
  `config error error="invalid date value: ''"`.** The
  `python/market_*/config.py` `env()` helper returned the empty
  string when an env var was set-but-empty (which is what
  compose's `${VAR:-}` expansion produces when the host env is
  unset), bypassing the documented default. `_parse_date("")` then
  blew up. Same latent class would have hit `int("")` for any
  empty `MARKET_POLL_BATCH_SIZE` etc. Fixed `env()` in both
  market configs to treat unset AND empty-string identically →
  fall back to the default in both cases. Verified end-to-end with
  a smoke test that exports empty values for both
  `MARKET_IMPORT_MIN_DATE` / `MAX_DATE` and
  `MARKET_POLL_BATCH_SIZE`; both Configs now load with their
  documented defaults (`2025-01-01`, yesterday-UTC, `5000`).

### Added
- **Container monitoring dashboard at `/admin/container-status`.** A
  Filament page plus dashboard widget that surfaces the live state of
  every container in the compose stack (running / unhealthy /
  restarting / exited) — the docker-level peer of System Status (which
  covers backend reachability) and Laravel Horizon (which covers queue
  workers).

  Data comes from a new `docker_socket_proxy` sidecar
  (`tecnativa/docker-socket-proxy`) that sits in front of
  `/var/run/docker.sock` and allows only `GET /containers*`, `/info`,
  `/version` — POST and EXEC are hard-denied at the proxy, so PHP
  never gets host-root-equivalent access from the bare socket. The
  socket is never mounted into `php-fpm` directly.

  Empty `DOCKER_API_HOST` disables the page entirely (renders a "not
  configured" notice) so operators who'd rather not expose even a
  read-only slice of the socket can opt out by leaving the env var
  unset or dropping the proxy from `infra/docker-compose.yml`.
  - `App\System\DockerStatusService` — probes the proxy, caches the
    snapshot for 5 s (10 s on failure) so widget polling doesn't
    hammer it.
  - `App\Filament\Pages\ContainerStatus` + blade view rendering a
    Filament-styled table with per-row state badges and uptime.
  - `App\Filament\Widgets\ContainerStatusWidget` — four-stat summary
    (total / running / unhealthy / stopped) on both the dashboard and
    the page header.
- **Long-lived schedulers for market-poll + market-import (ADR-0004
  § Follow-ups).** Up until now, `make market-poll` and
  `make market-import` were operator-triggered one-shots. The stack
  now runs two sustained-cadence loop containers that start with
  `docker compose up`:
  - **`market_poll_scheduler`** — polls every 5 minutes
    (`MARKET_POLL_INTERVAL_SECONDS`, default 300) matching CCP's
    region-orders cache window.
  - **`market_import_scheduler`** — reconciles every 6 hours
    (`MARKET_IMPORT_INTERVAL_SECONDS`, default 21600) to catch
    EVE Ref's same-day dump updates without hammering their
    server.

  Both services share the same Docker image as their one-shot
  twins (`aegiscore/market-poller:0.1.0` / `aegiscore/market-importer:0.1.0`)
  and pass `--interval <seconds>` at startup to flip the CLI into
  loop mode. Under `restart: unless-stopped` so crashes auto-recover.
  Operators keep `make market-poll` / `make market-import` for
  ad-hoc one-shots — they spawn separate transient containers under
  the `tools` profile, so there's no double-run conflict with the
  long-lived schedulers.

  The CLIs gain a `--interval N` flag (0 = single-pass + exit,
  original behaviour; N > 0 = loop forever). A pass that crashes
  inside the loop is logged via `log.exception` and the loop
  continues into its next sleep — the per-location (poller) and
  per-day (importer) transaction boundaries make partial-pass
  crashes safe to retry on the next tick.
- **Interactive donor structure picker on `/account/settings`
  (step 5c of ADR-0004's rollout).** Replaces the step-5a static
  stub with a Livewire-powered interactive surface: donors search
  for Upwell structures by name, click Watch to add, click Remove
  to stop polling. Search is backed by the donor's own
  `/characters/{id}/search/?categories=structure` response, so ESI
  enforces the ACL — the picker only surfaces structures the
  donor's character has docking access at. Completes the
  donor-facing UX that step 5b made functional on the poller
  side.
  - **`App\Services\Eve\MarketTokenAuthorizer`** — Laravel-plane
    twin of the Python `auth.py` lock-based refresh. Wraps a
    `SELECT ... FOR UPDATE` on the donor's `eve_market_tokens`
    row, refreshes via `EveSsoClient::refreshAccessToken()` when
    `expires_at <= now + 60s`, persists the rotated
    `access_token` + `refresh_token` + new `expires_at` inside the
    same transaction. Laravel and Python now coordinate on the row
    lock — whichever grabs it first refreshes; the other picks up
    the new token. No double-rotation (which would invalidate the
    stored refresh token and lock the donor out until they manually
    re-authorise).
  - **`App\Domains\Markets\Services\StructurePickerService`** —
    two-phase picker: (1) `search(token, query)` hits
    `/characters/{id}/search/` with `categories=structure` and
    returns structure IDs the donor's character has ACLs at;
    (2) per-ID `/universe/structures/{id}/` resolves name +
    `solar_system_id`, joined with `ref_solar_systems` for
    `region_id` (the structure endpoint returns system, not
    region). Tolerates individual 403/404s on resolve by dropping
    the candidate — handles the rare case of a character losing
    access between search and resolve in the same request.
  - **`App\Livewire\AccountSettings`** — the interactive component.
    Properties: `query`, `results`, `status`, `error`,
    `resultStructureIds`. Actions: `search()`, `addStructure(int)`,
    `removeStructure(int)`. Three server-side invariants:
    - **`addStructure` validates the ID was in the current
      session's search results**, not just the client's POST body.
      Enforces the ADR's "never accept free-form structure IDs"
      invariant at the server — a forged POST with a guessed
      structure ID is refused.
    - **`removeStructure` scopes by `owner_user_id = auth()->id()`**,
      so a forged row-id can't delete another donor's watched
      structures.
    - **Donor-gate re-checked on every action** — if donation
      expires between page load and action, the flow refuses
      cleanly.
  - **View chrome split:**
    - `resources/views/account/settings.blade.php` → page chrome
      (header, EVE HUD palette, `@livewireStyles` / `@livewireScripts`).
    - `resources/views/livewire/account/settings.blade.php` → the
      three sections the Livewire component drives (identity,
      market-data CTA + token status, structure picker + watched
      list).
    - `AccountSettingsController` slims to "render the outer shell";
      interactivity moves into the Livewire component.
  - **Updates UX on add:** successful add clears `$query` +
    `$results` so the page returns to "search" state rather than
    leaving the just-added result on screen. `wire:confirm` on
    Remove so a donor doesn't accidentally un-watch a structure
    with existing poll history (order history stays regardless —
    only polling stops).
  - 4 PHPUnit tests on `MarketTokenAuthorizer` cover: fresh token
    returns without SSO call, stale token refreshes + persists
    rotated values, SSO failure throws user-facing `RuntimeException`
    without updating the row, row-vanished race raises the
    documented error message.
- **Donor-owned structure polling in the Python plane (step 5b of
  ADR-0004's rollout).** The market poller now reads donor-owned
  structures using the `eve_market_tokens` rows the new fourth SSO
  flow authors. Completes the end-to-end donor self-service data
  path: donor authorises character via `/auth/eve/market-redirect`
  → token encrypted into `eve_market_tokens` → poller reads
  `/markets/structures/{id}/` with the donor's own bearer →
  orders land in `market_orders` with source
  `esi_structure_<structure_id>`.
  - **`market_poller/auth.py`** gains `load_and_refresh_market_token(conn, cfg, user_id)`
    — twin of the existing `load_and_refresh_service_token()` keyed
    on `user_id` rather than the singleton service row. Same
    `SELECT ... FOR UPDATE` lock pattern so parallel pollers can't
    double-rotate a donor's refresh token. Same 60-second future-
    bias for refresh triggering. Same scope-gate enforcement
    (`esi-markets.structure_markets.v1` required → otherwise raises
    `ServiceTokenScopeMissing`, which the runner maps to immediate
    disable).
  - **New `MarketToken` dataclass** alongside `ServiceToken`. Same
    shape plus `user_id` — the binding the poller enforces on every
    fetch.
  - **`_persist_market_refreshed()`** twin of the service-token
    persister. Kept as a separate function so a SQL typo on the
    table name becomes a compile-time error rather than a
    cross-table corruption.
  - **`market_poller/runner.py`** replaces the previous
    "donor-owned structure skipped" log-line with a real branch:
      - `owner_user_id IS NULL` → service token (admin path).
      - `owner_user_id IS NOT NULL` → donor market token via the
        new cache.
    A new `_MarketTokenCache` mirrors `_ServiceTokenCache` but
    keys on `user_id` so a donor with N watched structures pays
    the load+refresh round-trip once per pass, not N times.
  - **Defensive ownership-mismatch check** at the use-site in
    `_acquire_structure_token()`: if the loaded market token's
    `user_id` doesn't match the watched-location's `owner_user_id`,
    we immediately disable the row with
    `disabled_reason = 'ownership_mismatch'`. The SELECT-side
    filter in `load_and_refresh_market_token()` already enforces
    this, but the use-site check catches any future refactor that
    loosens the SELECT.
  - **Phase-5 multi-alt limitation** documented in the auth.py
    docstring: donors with multiple authorised characters get
    `ORDER BY updated_at DESC LIMIT 1`. Proper multi-alt support
    is a future migration that adds `owner_character_id` to
    `market_watched_locations`.
- **Fourth SSO flow: donor self-service market authorisation (step
  5a of ADR-0004's rollout).** Donors can now authorise one of their
  linked EVE characters for market-structure reads. Completes the
  four-flow set ADR-0002 anticipated (login / service / donations /
  market).
  - **New route `GET /auth/eve/market-redirect`** — auth-gated, not
    admin-gated (donors aren't admins). Donor-gated at the redirect
    AND the callback (re-checked in case donation expires mid-flow).
    Session marker `eve_sso.flow = 'market'` routes the shared
    `/auth/eve/callback` to the new finisher.
  - **`redirectAsMarket()` + `finishMarketFlow()` on
    `EveSsoController`** — mirrors the donations flow's shape with
    three policy gates the other flows don't need:
    - **Donor gate:** non-donors get a "become a donor" bounce
      before the round-trip. Redirect + callback both re-check.
    - **Character-linkage gate:** the callback character MUST
      already be linked to the authorising user (via
      `characters.user_id`). Without this, a session-hijack attacker
      could authorise any EVE character they control and have its
      ACLs used to pull market data under the victim's AegisCore
      account — an authorisation-confusion attack. The controller
      refuses and surfaces a clear "log in with this character
      first" error.
    - **Scope gate:** token must include
      `esi-markets.structure_markets.v1` or we refuse to store it
      (storing a functionally-useless token would mislead the
      poller).
  - **`App\Domains\UsersCharacters\Models\EveMarketToken`** — fourth
    flavour of EVE token model, same `'encrypted'` cast pattern as
    `eve_service_tokens` / `eve_donations_tokens`. Binds a
    `character_id` (UNIQUE) to a `user_id` (FK with ON DELETE
    CASCADE — "every market token traces to a live user" enforced
    at the DB level). Hidden columns so a stray `->toArray()` in a
    controller can't dump tokens into a response. 7 PHPUnit smoke
    tests cover encrypted-cast DB round-trip, ciphertext-at-rest
    verification, hidden-attribute exclusion from arrays/JSON,
    FK cascade-delete, `isAccessTokenFresh()` + `hasScope()`
    predicates, and UNIQUE-character-id enforcement.
  - **`config/eve.php` adds `market_scopes`** (env
    `EVE_SSO_MARKET_SCOPES`), defaulting to the minimum viable set
    per ADR-0004 § Live polling: `publicData
    esi-search.search_structures.v1
    esi-universe.read_structures.v1
    esi-markets.structure_markets.v1`.
  - **`/account/settings` route + `AccountSettingsController` +
    stub Blade view.** Phase 5a is the minimum viable donor-facing
    surface: identity, donor status, linked characters, market-data
    CTA (donor-gated), current market token status, read-only list
    of watched structures. Uses the same EVE HUD palette as
    `landing.blade.php` so it feels native. The Livewire
    structure-picker + add/remove management lands in the next
    rollout step; this stub is here so the SSO flow's redirects
    all land on a real route from day one.
- **Filament admin surface for `market_watched_locations` (step 4b
  of ADR-0004's rollout).** New `/admin/market-watched-locations`
  resource lets admins browse every row the Python poller works
  through, add platform-default entries (NPC stations + player
  structures), and enable/disable individual rows. Donor-owned rows
  appear read-only in the list (so operators can spot-check
  activity) but their create/edit flow stays at `/account/settings`
  per ADR-0004's Filament/frontend split.
  - **`App\Domains\Markets\Models\MarketWatchedLocation`** — new
    Eloquent model under `app/Domains/Markets/`. `Region` +
    `owner(User)` relations; `scopePlatformOwned` +
    `scopeDonorOwned`; `isJita()` / `isNpcStation()` /
    `isPlayerStructure()` predicates. **Belt-and-braces Jita
    guard**: a `booted()` `deleting` hook throws
    `DomainException('Jita 4-4 is the platform baseline ...')` if
    anyone tries to delete the platform Jita row, regardless of the
    code path (tinker, Artisan, a future service call, etc.). The
    Filament resource also hides the delete button for Jita, but
    the model-level guard is the durable protection.
  - **`MarketWatchedLocationResource`** — one Resource class, three
    pages (List / Create / Edit). Form branches on `location_type`:
    NPC rows use a searchable picker over `ref_npc_stations`
    (matches by system name — "jita", "amarr", "dodixie" — or by
    exact station ID) and auto-fills `region_id` from the chosen
    station's system; player-structure rows take a raw structure ID
    + region ID with a "admin knows what they're doing" helper
    note. First poll validates structure access; a 403 after the
    configured consecutive-failure threshold auto-disables the row
    with `disabled_reason = 'no_access'`, so pasting an
    inaccessible ID is self-correcting.
  - **Table columns** surface the operator-relevant telemetry
    without a second screen: kind (NPC/Structure badge), name,
    region, owner (platform vs donor), enabled toggle, last-polled
    age, consecutive-failure count (grey/amber/red badge),
    disabled_reason. Three filters: location_type, enabled/disabled,
    platform vs donor-owned.
  - **Reset-failure-counter action** on the Edit page — common
    operator workflow of "an upstream ESI hiccup ticked the counter
    to 2/3 but the row shouldn't auto-disable on the next blip".
    Zeroes `consecutive_failure_count` + clears `last_error` /
    `last_error_at` inside one transaction; does NOT touch the
    `enabled` flag (flipping disabled rows back on is a separate,
    deliberate action).
  - **No structure ESI-search picker yet** — that's the natural
    companion for the donor self-service flow and lands in step 5
    alongside the `/account/settings` picker. Until then admins
    paste structure IDs directly.
  - Navigation slot: **"Markets" group** in the sidebar (new). Sort
    weight 10, so if future market pages land (price chart page,
    valuation replay viewer, …) they slot in around this row.
  - 4 PHPUnit smoke tests under
    `tests/Unit/Domains/Markets/MarketWatchedLocationTest.php`:
    Jita seeder sanity, Jita-delete refusal, non-Jita delete
    succeeds, defensive "donor-owned row with Jita IDs is not
    protected" case, and boolean/integer cast round-trips.
- **Admin-owned player-structure polling (step 4a of ADR-0004's
  rollout).** The Python market poller can now read
  `/markets/structures/{id}/` for `market_watched_locations` rows
  with `location_type = 'player_structure'` and `owner_user_id IS
  NULL`, using the `eve_service_tokens` singleton the Laravel
  `/admin/eve-service-character` flow authored. Donor-owned
  structure polling (`owner_user_id = <user>`, backed by
  `eve_market_tokens`) stays deferred to step 5.
  - **`market_poller/laravel_encrypter.py`** — Laravel-compatible
    AES-256-CBC + HMAC-SHA256 `'encrypted'` cast interop. Reads and
    writes the same envelope format Laravel 12's
    `Illuminate\Encryption\Encrypter` uses, so tokens the PHP plane
    wrote can be decrypted by Python and rotated refresh tokens can
    be re-encrypted back into the shared row. Only AES-256-CBC
    (Laravel's default cipher) is supported; AES-256-GCM envelopes
    raise an explicit "not supported" error rather than producing
    wrong plaintext. APP_KEY parsing handles both the
    `base64:xxx` form Laravel writes and a bare base64 string.
    19 stdlib-unittest cases cover round-trip, APP_KEY parsing,
    MAC tamper detection (flipped iv/value/mac all reject), wrong-key
    rejection, envelope structure validation (bad base64 / bad JSON
    / missing fields / non-object), and the AES-GCM rejection path.
  - **`market_poller/sso.py`** — `POST /v2/oauth/token` refresh via
    `httpx`, mirroring the Laravel `EveSsoClient::refreshAccessToken()`
    shape (Basic auth with client_id/secret, `Host:
    login.eveonline.com` belt-and-braces header, unverified JWT
    payload decode for character_id + scopes). Typed error classes:
    `SsoTransientError` (5xx/network/timeout; retry next tick),
    `SsoPermanentError` (400/401; stale refresh_token or user
    revoked app — no auto-retry), `SsoMalformedResponseError`
    (missing fields or wrong JWT shape).
  - **`market_poller/auth.py`** — loads the singleton
    `eve_service_tokens` row under `SELECT ... FOR UPDATE` so a
    hypothetical second poller instance serialises on the row lock
    rather than double-rotating the refresh token (which CCP
    invalidates on every refresh — that's how tokens die). Refresh
    triggers at `expires_at <= now() + 60s`. Persists the rotated
    refresh token BEFORE using the new access token for anything, so
    a crash between refresh and first use doesn't orphan the
    credential. Scope-gates the token: missing
    `esi-markets.structure_markets.v1` is a security-boundary
    violation (`ServiceTokenScopeMissing`) that maps to immediate
    row disable with `disabled_reason = 'scope_missing'`, no grace
    counter.
  - **`market_poller/esi.py`** — new `structure_orders(structure_id,
    access_token)` generator mirrors `region_orders()` shape,
    paginates via `X-Pages`, sends `Authorization: Bearer <token>`.
    The `RawOrder` dataclass's `system_id` field now documents that
    it's 0 for structure-endpoint orders (field not returned by
    CCP; also not persisted into `market_orders`).
  - **`market_poller/runner.py`** — branches on `location_type`:
    NPC stations take the existing region-orders path; admin-owned
    structures take the new service-token path. Service token is
    loaded LAZILY + CACHED: stacks with no structure rows never
    touch the token, and the first structure row in a pass pays the
    load + refresh round-trip that every subsequent structure row
    reuses (including the cached error — one failed load doesn't
    retry for every row). Donor-owned rows are log-skipped pending
    step 5. New failure classification: `ServiceTokenNotConfigured`
    and `ServiceTokenMissing` are routine skips (no failure-counter
    tick — APP_KEY unset or admin hasn't authorised yet, both
    legitimate); `ServiceTokenScopeMissing` is immediate disable;
    everything else buckets with transient 5xx.
  - **Compose + requirements wired:** `market_poller` service
    receives `APP_KEY`, `EVE_SSO_CLIENT_ID`,
    `EVE_SSO_CLIENT_SECRET`, `EVE_SSO_TOKEN_URL` via existing env
    passthrough. New dep `cryptography~=43.0` added to
    `requirements-market.txt` for the AES implementation.
- **`python/market_importer/` — EVE Ref historical market-history
  importer (step 3 of ADR-0004's rollout).** Reconciles local
  `market_history` rows against EVE Ref's published per-day totals
  at `data.everef.net/market-history/totals.json`, downloads the
  bz2-compressed CSV for every day that's missing or partial, and
  bulk-upserts into MariaDB. One-shot container (`make market-import`)
  — one invocation = one reconcile pass.
  - **Ported from EVE Ref's Java `import-market-history` command**
    rather than reusing it directly: their importer supports
    PostgreSQL + H2 only, MariaDB is "planned" but not shipped.
    Porting the logic (reconcile against totals.json, per-day
    transactions, idempotent upserts) is ~500 lines of Python and
    keeps our execution plane homogeneous with `sde_importer` +
    `graph_universe_sync` + `market_poller`.
  - **Idempotent by construction.** `INSERT ... ON DUPLICATE KEY UPDATE`
    on the PK `(trade_date, region_id, type_id)` matches ADR-0004's
    uniqueness contract. Re-running a day after a partial load, an
    upstream count update, or a corrupt CSV converges on the
    latest-EVEref-known values. Reconciliation skips already-complete
    days cheaply — a repeat run after a successful import is
    essentially a totals.json fetch + a local `COUNT(*) GROUP BY`
    + exit.
  - **Per-day transaction boundary.** `autocommit=False`; every day's
    rows + outbox event commit together or roll back together. A
    corrupt CSV mid-stream or an unexpected parser error rolls the
    whole day back and the next run re-attempts from scratch.
  - **Column-order-agnostic parser.** `csv.DictReader` keys by header
    row, so EVE Ref could reshuffle columns upstream without breaking
    us. Required columns asserted on first read — a missing column
    fails fast as `CsvFormatError` rather than silently skipping
    rows.
  - **Source + observation_kind stamps.** Every row stamped with
    `source = 'everef_market_history'` + `observation_kind =
    'historical_dump'`, both defined in ADR-0004's enum set.
    Provenance is a query away, not a grep-of-logs away.
  - **Default import window 2025-01-01 → yesterday UTC** per
    ADR-0004 ("from 2025 forward"). Operators can rewind to any date
    EVE Ref has published (their dataset goes back to 2003-05-10);
    pre-2025 loads pile into the `p2025_01` partition since the
    migration only pre-creates 2025-01 through 2026-12 + MAXVALUE,
    so earlier partitions want to be added first for proper pruning
    on queries against those months.
  - **Emits `market.history_snapshot_loaded`** into the outbox per
    imported day (producer `market_importer`, version 1). Payload:
    `{trade_date, rows_received, rows_affected, source,
    observation_kind, loaded_at}`.
  - Ships with its own `python/market_importer.Dockerfile`,
    `python/requirements-market-import.txt`, a `market_importer`
    service in `infra/docker-compose.yml` under the `tools` profile,
    and a `make market-import` target (overrides via
    `MARKET_IMPORT_ARGS="--from=... --to=..."`,
    `"--only-date=..."`, `"--dry-run"`, `"--force-redownload"`).
    Sibling to `sde_importer` / `graph_universe_sync` /
    `market_poller`; `log.py` / `db.py` scaffolding is duplicated
    for the fourth time. That's the "rule of three" tripwire — next
    caller promotes the scaffolding to `python/_common/` and flips
    all four to import from there.
- **`python/market_poller/` — live market-data poller (step 2 of
  ADR-0004's rollout).** First concrete caller on the Python-plane
  ESI track flagged by ADR-0002 § phase-2 #12. One-shot container
  (`make market-poll`) that walks enabled rows in
  `market_watched_locations`, pulls the current order book from ESI
  per row, bulk-inserts into `market_orders`, and emits one
  `market.orders_snapshot_ingested` outbox event per successful
  location poll.
  - **Phase 1 handles NPC stations only** — region endpoint
    (`GET /markets/{region_id}/orders/`) + client-side location
    filter, no auth required. Paginates via `X-Pages`. Admin-owned
    and donor-owned structure polling (auth'd via `eve_service_tokens`
    / `eve_market_tokens`) layer on top in later rollout steps
    without changing the package shape — the runner branches on
    `location_type`.
  - **Jita 4-4 seeded as the permanent baseline** via a new
    `2026_04_14_000009_seed_jita_market_watched_location` migration:
    `region_id = 10000002`, `location_id = 60003760`,
    `owner_user_id = NULL`, `enabled = true`. Re-runnable: the insert
    is guarded by an existence check so migration rollback/forward
    doesn't double-seed, and the rollback only deletes rows with no
    poll history.
  - **Reactive rate-limit posture** — reads
    `X-Ratelimit-Remaining`/`Reset` and `X-ESI-Error-Limit-Remain`/`Reset`
    on every response; sleeps (capped at 30/60s) when at or below the
    configured safety margin. On 429/420 honours `Retry-After` and
    raises `TransientEsiError`; no in-process retry (the cadence is
    the retry).
  - **Failure discipline per ADR-0004 § Failure handling** —
    consecutive-failure counter on `market_watched_locations`,
    auto-disable after 3 × 403 (`disabled_reason = no_access`) or
    5 × 5xx/timeout (`disabled_reason = upstream_failing` or
    `upstream_unreachable`). Single success resets the counter.
    A `disable_immediately(reason, message)` path exists for the
    security-boundary violations the later donor-token steps will
    need (ownership mismatch, missing scope); unused in phase 1.
  - **Atomic per-location transactions** — `pymysql` connection runs
    with `autocommit=False`, each location's rows + bookkeeping +
    outbox event commit together or roll back together. A failure
    in one location doesn't taint the next.
  - **Idempotent inserts** — `INSERT IGNORE` on
    `(observed_at, source, location_id, order_id)`, relying on the PK
    from ADR-0004. Replaying a tick within the same `observed_at`
    is a no-op; `rows_inserted` (affected-rows) naturally drops to
    zero on retries.
  - **Source-string convention** for provenance:
    `esi_region_<region_id>_<location_id>` for NPC rows,
    `esi_structure_<structure_id>` reserved for the later structure
    path. Human-readable on purpose — shows up in logs + audit queries
    often enough that the IDs inline beat an opaque hash.
  - Ships with its own `python/market_poller.Dockerfile`,
    `python/requirements-market.txt`, a `market_poller` service in
    `infra/docker-compose.yml` under the `tools` profile, and a
    `make market-poll` target. Sibling to `sde_importer` and
    `graph_universe_sync`; the `log.py` / `db.py` scaffolding is
    duplicated rather than promoted to `python/_common/` — three
    copies is still under the "rule of three" tripwire and the
    implementations haven't diverged.
- **ADR-0004 + schema foundation for market-data ingest.** Architecture
  decision record and the four MariaDB migrations the market pillar
  needs before any poller / importer / UI code lands.
  - **`docs/adr/0004-market-data-ingest.md`** freezes: (1) two raw
    canonical tables (`market_orders` vs `market_history`), not one —
    genuinely different shapes, uniqueness contracts, and retention
    curves; (2) historical backfill via EVE Ref's daily CSV dumps at
    `data.everef.net/market-history/`, imported by a new Python worker
    (`python/market_importer/`) porting EVE Ref's Java logic to MariaDB
    since their PostgreSQL-only importer doesn't support our stack;
    (3) live polling on the Python execution plane from day one — no
    Laravel-side market poller stepping stone, per the ADR-0002 plane
    boundary; (4) Jita always-on as a platform baseline, seeded in
    `market_watched_locations`; (5) per-donor structure authorisation
    as an architectural invariant — Upwell structure market reads
    are alliance/corp ACL-gated, so no shared admin token can poll
    arbitrary donor outposts. Each donor authorises their own
    character via a fourth SSO flow; the token lands in a dedicated
    `eve_market_tokens` table bound to their user; the poller
    enforces `token.user_id == watched_location.owner_user_id` on
    every call. Structure *discovery* is also ACL-gated: the
    `/account/settings` picker is a thin wrapper around the donor's
    own `/characters/{id}/search/?categories=structure`, never
    free-form ID entry.
  - **`market_history`** (PK `(trade_date, region_id, type_id)`,
    monthly `RANGE` partitioned on `trade_date`) — mirrors EVE Ref's
    shape so dumps import with minimal normalisation. Columns
    `trade_date` (not `date`, reserved-word hygiene), `average`,
    `highest`, `lowest`, `volume`, `order_count`,
    `http_last_modified`, plus `source` + `observation_kind` ENUM
    (`historical_dump | incremental_poll`) for provenance.
  - **`market_orders`** (composite PK
    `(observed_at, source, location_id, order_id)`, monthly `RANGE`
    partitioned on `observed_at`) — one row per order observation,
    live ESI snapshots or future order-book dumps. `DATETIME(6)`
    microsecond precision on `observed_at` so poller batches cluster
    contiguously per snapshot; `location_id` is `BIGINT UNSIGNED`
    because Upwell structure IDs crossed `INT` max long ago.
    `observation_kind` ENUM (`snapshot | incremental_poll |
    historical_dump`).
  - **`market_watched_locations`** — the poller's driver table.
    `location_type` (`npc_station | player_structure`) + explicit
    `region_id` keeps the record model clean; poller derives "region
    endpoint + filter" vs "structure endpoint" from `location_type`.
    `owner_user_id` nullable: `NULL` = admin-managed platform default
    (Jita and siblings), non-null = donor-owned. Failure discipline
    built in: `consecutive_failure_count`, `last_error`,
    `last_error_at`, `disabled_reason`; auto-disable after 3
    consecutive 403s or 5 consecutive 5xx/timeouts, single success
    resets the counter. Security-boundary failures (ownership
    mismatch, missing scope) disable immediately with no grace.
  - **`eve_market_tokens`** — fourth flavour of EVE token storage
    (login / service / donations / market). `user_id` FK with
    `CASCADE ON DELETE` so the "every token traces to a live user"
    invariant is enforced at the DB level. `character_id` UNIQUE so
    re-auth upserts. Access/refresh tokens ride Laravel's
    `'encrypted'` cast, consistent with `eve_service_tokens` and
    `eve_donations_tokens`.
  - ADR-0003 § Follow-ups updated to point at ADR-0004; ADR index
    extended to list 0002 + 0004. No poller, no importer, no UI
    code in this drop — schema + architectural record only, so the
    data contract gets reviewed in isolation before runtime code
    lands on top of it.
- **EVE map renderer module — drop-in `<x-map.renderer>` Blade
  component + Filament demo page at `/admin/universe-map`.** Reusable
  SVG/D3 renderer for systems, stargates and roads, fed by a public
  JSON endpoint (`GET /internal/map/{scope}` for `universe`, `region`,
  `constellation`, `subgraph`). Data flows from MariaDB `ref_*` →
  Neo4j projection (`python -m graph_universe_sync`) → laudis
  Bolt client → spatie/laravel-data DTO (`MapPayload`) → fetched by
  the vendored D3 module under `app/public/js/map-renderer/`. Notable
  details: PHP-side 2D projection (`TOP_DOWN_XZ` uses CCP's
  `(x, -z)` convention, `POSITION_2D` honours the new `position2d_x/y`
  columns when present, `AUTO` picks per-row), aggregated/dense
  universe modes (default aggregated for fast first paint), per-SDE-build
  cache key in `MapCache`, scope-aware FormRequest validation, public
  endpoint with `throttle:60,1`. New columns: `position2d_x` /
  `position2d_y` on `ref_solar_systems` (migration +
  `python/sde_importer/schema.py` extension). New Python tool:
  `python/graph_universe_sync` (own Dockerfile, requirements split,
  Compose `tools` profile, `make neo4j-sync-universe`). Reference
  Eloquent models added under `app/app/Reference/Models/` for the
  Filament pickers (`Region`, `Constellation`, `SolarSystem`,
  `Stargate`, `NpcStation`). Frontend palette mirrors the EVE HUD
  colours from `landing.blade.php` so the widget feels native in any
  Aegis page; D3 v7 is vendored under `app/public/vendor/d3/` with a
  README documenting upstream URL + SHA-256 (no CDN, no Vite).
- **Donations → ad-free time, with expiry.** Each donation now
  grants the donor `amount / EVE_DONATIONS_ISK_PER_DAY` days of
  ad-free access. Default rate 100_000 ISK = 1 day, operator-tunable
  per deployment. Accumulation is streaming, not a bulk sum:
  donations that arrive *inside* an active window extend it from
  the current expiry; donations that arrive *after* the window
  elapsed start a fresh one from the new arrival time (past unused
  time is not credited backward — operators get the predictable
  "donating while covered extends you, donating while lapsed resets
  you" behaviour).
  - New materialised table `eve_donor_benefits` stores one row per
    distinct donor with the accumulated `ad_free_until`, total
    ISK, donation count, and the rate the row was computed at.
    The poll job recomputes touched donors after each wallet upsert
    via `App\Domains\UsersCharacters\Services\DonorBenefitCalculator`.
  - `User::isDonor()` now checks "is `ad_free_until` still in the
    future?" instead of "did this user ever donate?". Once a donor's
    accumulated window passes, they silently lose ad-free status
    with no cron or cleanup job — it's a query-time comparison
    against `now()`.
  - `php artisan eve:donations:recompute` rebuilds every
    `eve_donor_benefits` row from the raw `eve_donations` ledger
    at the current rate. Run after this feature lands to backfill
    rows for pre-existing donations, and after any
    `EVE_DONATIONS_ISK_PER_DAY` change (the new rate is retroactive
    by design — past donors shouldn't be punished by a rate cut).
  - Filament `/admin/eve-donations` redesigned around donor cards
    with CCP-CDN portraits: "Active donors" grid showing name,
    character ID, donated ISK, transfer count, and days/hours of
    ad-free time remaining per card; "Expired donors" section that
    fades past-window donors; raw donations ledger below with
    portraits on each row and proper column alignment
    (fixed-width cells, tabular-nums for amounts). Portraits load
    from `images.evetech.net` (public CDN, CORS-friendly, no auth).

### Fixed
- **`PollDonationsWallet` crashed on every Horizon tick with
  `BindingResolutionException: Unresolvable dependency resolving
  [Parameter #0 <required> string $baseUrl] in class
  App\Services\Eve\Esi\EsiClient`.** Root cause: `EsiClient` was
  never bound in the container. Its constructor takes four
  primitives (base URL, user agent, timeouts, cache TTL) pulled
  from `config('eve.esi')` — unresolvable by Laravel's autowiring —
  so the moment anything type-hinted `EsiClient`, Laravel tried to
  build one from reflection and threw. Light-synchronous callers
  (page controllers hitting `EsiClient::fromConfig()` directly)
  never tripped this; the donations poller is the first Horizon
  job that DI's the client through `handle()`, which is where the
  failure surfaced. Fix: register `EsiClient` + `EsiRateLimiter`
  as singletons in `AppServiceProvider::register()` using each
  class's existing `fromConfig()` factory. Side benefit: future
  Filament / controller callers get clean container resolution
  without having to remember `::fromConfig()`.
- **Horizon spawned zero worker processes; scheduled + dispatched
  jobs piled up in Redis with no consumer.** Root cause: no
  `config/horizon.php` was published, so Horizon fell back to the
  package's vendored default, which only declares supervisors for
  `APP_ENV ∈ {production, local}`. AegisCore runs with
  `APP_ENV=dev` (from `AEGISCORE_ENV=dev` — see .env.example +
  compose) and stages with `staging` / `prod`; none of those strings
  match the vendored environments, so the master started, found no
  supervisor spec for the current environment, spawned zero workers,
  and every `ShouldQueue` dispatch (daily SDE version check, 5-minute
  donations wallet poll, …) accumulated in Redis unconsumed.

  Symptom on `/horizon`: "Status: Active" in the header but an empty
  supervisor row, `Total Processes: 0`, and "Jobs Past Hour" ticking
  up (dispatches) without corresponding completions. Donations
  dashboard stayed empty even after a real in-game ISK transfer
  because the poll job never actually ran.

  Fix: publish `config/horizon.php` with an explicit supervisor spec
  for every `APP_ENV` we deploy under (`dev`, `staging`, `prod`) plus
  fallbacks for the stock Laravel values (`production`, `local`) so
  an operator running `APP_ENV=production php artisan horizon` still
  spawns workers. Supervisor itself is vanilla: auto-balanced pool on
  the `default` queue against the `redis` connection (matches
  `QUEUE_CONNECTION=redis` + `REDIS_QUEUE=default`). `maxProcesses`
  scales per-env (dev: 1, staging: 3, prod: 5). After deploy, an
  operator `make restart` recycles the horizon container; the jobs
  already queued from the previous (broken) dispatches drain
  immediately — idempotent upserts mean nothing has to be rerun by
  hand.

  Compose comment on the `horizon` service updated to explicitly warn
  against relying on the vendor defaults. Healthcheck comment
  clarified: `horizon:status` is a liveness probe only, not a
  correctness oracle — it reports the master as "running" even when
  zero workers are defined. That failure mode is prevented at config
  time by this ADR-0002-shaped supervisor spec, not caught at runtime.

### Added
- **AegisCore brand mark — SVG logo + favicon.** Pointy-top hex shield
  in cyan (the EVE HUD "go / friendly / selected" colour from the
  landing palette), with an inset gold hex frame, four cardinal HUD
  reticle ticks pointing inward, and a cyan "intel signature" core dot.
  Reads as "Aegis (defensive shield) + Core (sensing centre)" — a
  defensive intel platform for New Eden, which is exactly what the
  product is. Same SVG ships as the browser-tab favicon and as an
  inline lockup in the landing page header. No raster assets — pure
  SVG scales cleanly from 16px tab icons to large UI marks. Removed
  the previous zero-byte placeholder `favicon.ico`; modern browsers
  honour `<link rel="icon" type="image/svg+xml">` directly.
- **EVE service character SSO flow** (admin-only, elevated scopes,
  encrypted token storage). Phase-2 work landing early — see
  ADR-0002 § Token kinds + the implementation-checklist amendment.
  - New `eve_service_tokens` table (one row per stack, keyed on
    `character_id`, upserted on re-auth). `access_token` and
    `refresh_token` ride Laravel's `'encrypted'` cast (APP_KEY =
    encryption key) so a `SELECT *` leak is ciphertext, not bearer
    tokens. Model also marks them `$hidden` so a stray `->toArray()`
    can't dump them into a response.
  - New admin-only route `/auth/eve/service-redirect` requests the
    scope set from `EVE_SSO_SERVICE_SCOPES` (default covers
    `publicData`, location, search, structure-markets,
    corporation-membership/structures, and alliance-contacts —
    enough for the planned polling). Both the login flow and the
    service flow share `/auth/eve/callback`; a session-stashed
    `flow` marker (`'login'` vs `'service'`) routes to the right
    finisher so the registered CCP app only needs one redirect URI.
  - New Filament page `/admin/eve-service-character` shows the
    current stored token (character + scopes + freshness +
    audit-trail) and a one-button (re)authorise CTA. Diff-highlights
    scopes the env asks for that the stored token doesn't grant.
  - Admin user-menu gets an "Authorise EVE service character" entry
    pointing at the page; replaces the old "Log in with EVE Online"
    user-menu item, which (in the admin context) was confusing
    because the operator is already logged in.
- **EVE donations character SSO flow + 5-minute wallet poller.** Third
  SSO flow alongside login + service. Built around a single in-game
  character that *receives* ISK donations from supporters; future
  ad-removal logic gates on whether a logged-in user has donated —
  donor → user linkage materialises automatically without any manual
  step. ADR-0002 § phase-2 amendment carries the design rationale.
  - **Hard character lock at the SSO callback.**
    `EVE_SSO_DONATIONS_CHARACTER_ID` env var pins the flow to one
    EVE character ID. The callback rejects mismatched authorisations
    with a clear "log out of EVE SSO and re-authorise as the correct
    character" error rather than upserting the wrong-character token.
    Scope set: `publicData esi-wallet.read_character_wallet.v1` —
    wallet-read is the functional scope, `publicData` rides alongside
    as the base-identity scope CCP's SSO consent surface expects on
    every authorised session. Donor name resolution itself uses the
    unauth'd `/universe/names/` endpoint regardless of what the token
    grants. New admin-only route
    `/auth/eve/donations-redirect` kicks off the round-trip; same
    `/auth/eve/callback` dispatches by session-stashed `flow` marker
    (`'login'` / `'service'` / `'donations'`).
  - **New `eve_donations_tokens` table.** Sibling of
    `eve_service_tokens` (not shared with a `kind` column) so the
    boundary is enforced at the schema level — a buggy donations
    poller can't SQL-typo its way to a service token (or vice
    versa). Same `'encrypted'` cast + `$hidden` pattern. Singleton:
    one row per stack, upserted on `character_id`.
  - **Reactive Laravel-side refresh.**
    `EveSsoClient::refreshAccessToken()` + new `EveSsoRefreshedToken`
    DTO. Single-character + single-scheduler-instance means no
    distributed lock yet (per ADR-0002's original "needs locks
    eventually" caveat). The poller refreshes when
    `isAccessTokenFresh()` is false, persists the rotated refresh
    token before doing anything else, and warns if the refresh
    response drops the wallet scope (donor revoked the app on
    `community.eveonline.com/support/third-party-applications`).
  - **5-minute wallet poller.**
    `App\Domains\UsersCharacters\Jobs\PollDonationsWallet` dispatched
    via `php artisan eve:poll-donations` (also runs `--sync` for
    operator debugging). Scheduled from `routes/console.php` via
    `EVE_DONATIONS_POLL_CRON` (default `*/5 * * * *`); wallet journal
    is cached server-side ~1h by CCP so most ticks are conditional-GET
    304-cheap. Filters `ref_type === 'player_donation'`, upserts by
    `journal_ref_id` (CCP's primary key for the journal entry → idempotent
    re-runs), then resolves new donor names via batched
    `POST /universe/names/`. `withoutOverlapping(10)` belt-and-braces
    against a stalled tick double-rotating the refresh token.
  - **`eve_donations` table.** `journal_ref_id` UNIQUE for the
    upsert. `donor_character_id` indexed for the
    `User::isDonor()` join; deliberately NO foreign key to
    `characters.character_id` — donors don't need an AegisCore
    account to donate. When they later log in via SSO, the existing
    `upsertCharacterAndUser()` flow creates the character row keyed
    on the same ID and the predicate starts returning true with
    zero migration. ISK amount stored as `DECIMAL(20, 2)` for exact
    precision (a float cast loses ISK on large donations).
  - **`User::isDonor()` predicate.** One-line gate for future
    ad-removal logic (`if (! $user->isDonor()) { renderAds(); }`).
    No visible "donor" UI surface — the linkage exists only in
    code so it's ready when ads land, without requiring any
    cross-cutting refactor at that point.
  - **Filament page `/admin/eve-donations`.** Token status panel
    (character + scopes + freshness + audit-trail), authorise CTA,
    and a 50-row donations ledger with donor name + ISK + reason
    + timestamp. Aggregate footer shows total ISK and unique donor
    count, both summed in SQL to keep DECIMAL precision exact.
    Hides itself from navigation when SSO or the donations
    character ID isn't configured. Stored token character vs.
    configured character mismatch surfaces a rose-coloured warning
    panel (the SSO callback should never let it in, but defensive).
  - **Admin user-menu entry "Authorise donations character"**, only
    when both SSO is configured and `EVE_SSO_DONATIONS_CHARACTER_ID`
    is set — same gate the navigation entry uses, so neither shows
    a dead-end click.

### Changed
- **Landing page hero gets a large brand-mark SVG.** The same hex
  shield from the header / favicon, sized
  `clamp(140px, 18vw, 220px)` so it scales with viewport width but
  never dwarfs the text. Sits left of the existing hero copy
  (`AegisCore — defensive intel platform for New Eden`) on a
  flex two-column layout, with a subtle 5s cyan drop-shadow pulse
  via CSS `@keyframes`. Honours `prefers-reduced-motion` (animation
  disabled). Stacks vertically below 720px viewport width, hidden
  entirely below 480px so phone screens don't waste vertical
  real-estate before the CTAs. Pure CSS — no Tailwind utility
  classes (the landing page deliberately stays self-contained
  inline styles).
- **Landing page rebuild.** Inline brand-mark SVG + wordmark in the
  header (links back to home), with a hover-glow filter on the mark.
  Right side of the header now hosts both the new authenticated user
  badge (portrait pulled from `images.evetech.net` + character name
  + inline sign-out form) and the existing env badge. Hero CTAs are
  gated three ways:
  - Guest with SSO configured → "Log in with EVE Online" (gold)
  - Logged-in admin (per `User::canAccessPanel`) → "Admin →" (cyan)
  - Logged-in non-admin → no primary CTA — landing page becomes a
    content surface for them, not a doorway to a 403
- **EVE SSO post-login redirect splits by admin status.** Admins
  (per `EVE_SSO_ADMIN_CHARACTER_IDS` allow-list, or the bootstrap
  no-character escape hatch) land on `/admin`; everyone else lands
  on `/` (the landing page, which now welcomes them with the
  identity badge). Previously every successful login dumped users
  on `/admin`, where non-admins hit a 403.
- **Filament admin user-menu gets a "Back to landing page" entry.**
  The Filament brand link in the topbar goes to `/admin`, so there
  was no obvious way out of the admin area back to the marketing
  surface without typing a URL. New menu item closes the gap.
- **POST `/logout` route** for the landing-page sign-out form.
  Invalidates the session, regenerates the CSRF token, redirects to
  `/`. Filament's panel-scoped logout keeps working for users in
  `/admin`; this one exists for the marketing surface.

### Fixed
- **EVE SSO + ESI env vars never reached the PHP container.** The
  `php-fpm` service in `infra/docker-compose.yml` carries an explicit
  `environment:` allow-list ("keep this list intentional") — the
  `EVE_SSO_*` and `ESI_*` keys added by the SSO and rate-limiter PRs
  weren't in it, so even with the values set in the host `.env`
  Laravel saw `null` for every one. End result: `EveSsoClient::isConfigured()`
  returned false on every check, hiding the "Log in with EVE Online"
  button on the landing page, the Filament admin login form, and the
  admin user menu — symptom: "I don't see the EVE login buttons …
  nowhere". Added `EVE_SSO_CLIENT_ID`, `EVE_SSO_CLIENT_SECRET`,
  `EVE_SSO_CALLBACK_URL`, `EVE_SSO_LOGIN_SCOPES` (default
  `publicData`), `EVE_SSO_ADMIN_CHARACTER_IDS`, plus `ESI_USER_AGENT`,
  `ESI_TIMEOUT_SECONDS`, `ESI_RATE_LIMIT_SAFETY_MARGIN`,
  `ESI_RATE_LIMIT_MAX_WAIT_SECONDS`. All inherit through the
  `<<: *php-common` anchor so `scheduler` and `horizon` see the same
  values automatically. Empty defaults are preserved on the EVE SSO
  trio so the button correctly stays hidden on deployments that
  haven't registered an EVE app yet.

### Added
- **ESI rate-limit module** — `App\Services\Eve\Esi\EsiRateLimiter`,
  Redis-backed reactive throttle that the `EsiClient` now consults
  before every request. Three pieces of state per group, all with
  TTLs so they self-evict:
  - `state` (`remaining`, `reset_at`) reseeded from `X-Ratelimit-*`
    headers on every response (including 304 + 4xx — those still
    cost tokens).
  - `backoff` epoch set when CCP returns 429/420; per-group when the
    response says which group, plus a global belt-and-braces hold so
    a subsequent first-time URL also waits.
  - `url_group` map populated from response headers so pre-flight can
    look up the group from the URL alone for repeat calls.
  Pre-flight returns seconds-to-wait. EsiClient sleeps in-process for
  short waits (`ESI_RATE_LIMIT_MAX_WAIT_SECONDS`, default 5s); longer
  holds throw `EsiRateLimitException` so Horizon callers can
  `release($seconds)`. Reactive (CCP's `Remaining` is the source of
  truth, no local token counting) so we don't compound drift across
  parallel workers; not a distributed lock — `safety_margin` (default
  5 tokens) absorbs the small overshoot from races and 429-backoff is
  the safety net. ADR-0002 § ESI client revised; OpenAPI-spec ingestion
  for pre-flight limit map stays out of scope (the reactive learner
  re-discovers windows on its own when CCP rotates routes).
- **EVE login button on the landing page + admin user menu.** Two new
  surfaces for the existing `/auth/eve` route, both gated on
  `EveSsoClient::isConfigured()` (so they vanish on deployments that
  haven't wired SSO):
  - Landing page (`resources/views/landing.blade.php`) gets a
    gold-accented "Log in with EVE Online" button next to the Admin
    CTA — distinct colour from the cyan Admin button so the two
    primary actions don't read as duplicates.
  - Filament's user-menu (top-right dropdown inside `/admin`) gets a
    "Log in with EVE Online" item via `userMenuItems()`. Useful for
    operator-seeded accounts that want to (re-)auth as an EVE
    character. Note: this re-runs the SSO flow and `Auth::login`s
    into whichever user that character is linked to — true
    alt-attaching (mutating the *current* session's `user_id`)
    belongs in a later PR.

### Fixed
- **"Log in with EVE Online" button no-ops when SSO env vars aren't set.**
  Without `EVE_SSO_CLIENT_ID` / `EVE_SSO_CLIENT_SECRET` /
  `EVE_SSO_CALLBACK_URL` populated (or after a `.env` edit before
  `php artisan config:clear`), the button rendered but `/auth/eve`
  caught the misconfig and bounced straight back to `/admin/login`
  with a `withErrors()` message under the email field — easy to miss,
  reads as "click did nothing." The Filament render hook now asks
  `EveSsoClient::isConfigured()` before emitting the button HTML, so
  the button only appears on deployments where SSO is actually wired
  up. Email+password login stays available regardless. The new static
  `EveSsoClient::isConfigured()` is a pure config predicate; no
  network, no exceptions.
- **SDE widget — wrong status + ETag overflowing the card.** Two issues
  in `App\Filament\Widgets\SdeVersionStatusWidget`:
  - The state `match` fell through to `Up to date` whenever
    `is_bump_available` was false, which is true any time
    `pinned_version` is null (the drift job won't flag drift without
    both sides present). Result: a freshly deployed system rendered
    green "Up to date" despite having loaded zero bytes of SDE. Adds two
    new pre-import states with higher precedence than the drift
    branches: `No SDE loaded · upstream unreachable` (red, both signals
    broken) and `No SDE loaded` (gray, calm but unambiguous). "Up to
    date" now only fires when pinned is set AND equals upstream.
  - CCP's ETag is a 32+ char hex string (e.g.
    `5743b7cb89928645788c46defd7c6535-10`); rendering it raw — even
    truncated to 32 chars — overflowed the stat card. Headlines now
    show a 12-char `git log --oneline`-style prefix; the full value
    moves into the Stat description so ops can still copy it without
    leaving the dashboard. Untruncated history still lives at
    `/admin/sde-status`.

### Added
- **EVE SSO login + admin gate** — OAuth2 PKCE against
  `login.eveonline.com/v2/oauth/*`. New routes `GET /auth/eve` (redirect)
  and `GET /auth/eve/callback` (exchange + login). Phase 1 requests only
  the `publicData` scope and discards the access token after decoding
  the JWT identity claim (`sub` = `CHARACTER:EVE:<id>`, `name`) — login
  is stateless, no refresh tokens stored. On callback we upsert a
  `characters` row (new migration), link it to a `users` row (creating
  one with a synthetic email if the character is logging in for the
  first time), and start a Laravel session. Filament's login form gets
  a "Log in with EVE Online" button via the
  `panels::auth.login.form.after` render hook. Admin access is now
  gated on `EVE_SSO_ADMIN_CHARACTER_IDS` in `.env` (comma-separated EVE
  character IDs, not names — names are mutable) via
  `User::canAccessPanel()`; operator-seeded email+password accounts
  (`make filament-user`) still work as the bootstrap escape hatch. ADR-0002
  locks the plane split: SSO + light synchronous ESI in Laravel, heavy
  polling (killmails, corp rosters, wallets) stays in Python per
  ADR-0001. New `App\Services\Eve\Sso\EveSsoClient` (authorize URL +
  PKCE, token exchange, unverified JWT decode — trust boundary is the
  TLS chain to CCP, see ADR-0002 § JWT verification) and
  `App\Services\Eve\Esi\EsiClient` (thin wrapper over Laravel's HTTP
  facade: logs `X-Ratelimit-*` headers per response, Redis-cached
  per-URL ETag + Last-Modified for conditional GETs, throws
  `EsiRateLimitException` carrying `Retry-After` on 429/420). Phase-2
  (service-character SSO with elevated scopes, per-group pre-flight
  throttling, token refresh, JWT verification) deferred to when the
  Python poller lands — ADR-0002 leaves the config + class shape ready.

### Changed
- **Landing page drops the Horizon CTA.** Horizon already lives in the
  Filament admin sidebar under "Monitoring" (PR #17), so the public
  landing button was a second entry point to the same gated surface. One
  path to Horizon (via `/admin`) is enough; the landing page stays
  focused on the four pillars and the Admin CTA.

### Added
- **Admin System Status overview** — traffic-light health card for every
  backend AegisCore depends on (MariaDB, Redis, Horizon, OpenSearch,
  InfluxDB, Neo4j). New `App\System\SystemStatusService` runs one cheap
  probe per backend (DB `SELECT 1`, Redis `PING`, Horizon master
  supervisor count, OpenSearch `/_cluster/health`, InfluxDB `/ping`,
  Neo4j Bolt TCP reach + `RETURN 1` Cypher ping via laudis/neo4j-php-client)
  with a 1s timeout and try/catch so one
  dead service never breaks the page. Results cache for 15s in Redis so
  Filament's widget polling is cheap. Each probe maps to a
  `SystemStatusLevel` — `OK` (green) / `DEGRADED` (orange, e.g.
  OpenSearch yellow cluster, Horizon not running) / `DOWN` (red) /
  `UNKNOWN` (grey, e.g. host not configured). New
  `SystemStatusWidget` renders the snapshot as a 3-up grid of coloured
  stat cards on the `/admin` dashboard, and a dedicated
  `/admin/system-status` page under "Monitoring" gives operators a
  deep-linkable incident-response view alongside Horizon.
- **SDE importer — `python/sde_importer/`.** Python 3.12 one-shot
  container that downloads CCP's EVE Static Data Export JSONL zip (~83MB
  compressed, ~500MB extracted, 56 JSONL files, ~664k rows total) and
  loads every `ref_*` table in one MariaDB transaction. Declarative
  `schema.py` maps each JSONL file to a `TableSpec` listing hot scalar
  columns (typed: int/float/str/bool/name with i18n `.en` extraction)
  plus a `data` LONGTEXT JSON catch-all so future PRs can promote
  overflow fields into typed columns without a reload. Generic
  `loader.py` streams each file, bulk-INSERTs in batches of 2000 via
  pymysql `executemany`, degrades malformed rows to logged skips rather
  than aborting the transaction. On COMMIT the importer emits a
  `reference.sde_snapshot_loaded` event into `outbox` (producer
  `sde_importer`, payload includes build number, release date, ETag,
  per-table row counts) and writes the pin to `infra/sde/version.txt`
  for the drift-check widget. Three new Laravel migrations land 44
  `ref_*` tables + `ref_snapshot`: universe topology (regions,
  constellations, solar systems, stargates, stars, planets, moons,
  asteroid belts, secondary suns, landmarks), items (categories,
  groups, market groups, meta groups, types, compressible, contraband,
  dynamic attributes, type materials/dogma/bonus, dogma
  attributes/effects/categories/units, dbuff collections, blueprints),
  entities (factions, races, bloodlines, ancestries, NPC
  corps/divisions/stations/characters, station ops/services, agents,
  certs, masteries, character attributes, clone grades, icons,
  graphics, skins, skin materials/licenses, planet
  resources/schematics, control tower resources, translation
  languages, corp activities, sov upgrades, mercenary ops, freelance
  job schemas). No FK constraints — truncate-reload doesn't want the
  cascade bill and phase-1 reload happens in a maintenance window
  (ADR-0001 §4). New `sde_importer` compose service lives in the
  `tools` profile so `docker compose up` doesn't launch it; run on
  demand via `make sde-import`.
- **Daily SDE version-drift check** (first concrete piece of the ADR-0001
  reference-data plumbing). A new `scheduler` compose service runs
  `php artisan schedule:work` as a long-running process — no host cron.
  `routes/console.php` registers `reference:check-sde-version` at 08:00
  UTC daily, which dispatches the `CheckSdeVersion` Horizon job. The job
  HEADs CCP's pinned SDE tarball URL, reads the repo-pinned marker at
  `/var/www/sde/version.txt` (bind-mounted from `infra/sde/`), and inserts
  one row into the new `sde_version_checks` table (id, checked_at,
  pinned/upstream/etag/last_modified, is_bump_available, http_status,
  notes). One HTTP HEAD + one insert — well inside the plane-boundary
  budget. New Filament dashboard widget (`SdeVersionStatusWidget`)
  surfaces four states with the EVE HUD palette: never-checked (gray) /
  up-to-date (cyan) / bump-available (amber) / stalled (red). New
  Filament page at `/admin/sde-status` embeds the widget and paginates
  the full check history. `make sde-check` triggers an inline run that
  prints the result — useful on deploy / for smoke-testing the pipe.
  The scaffold for the cross-cutting `app/app/Reference/` module lands
  with this PR (Jobs / Models / Console), documented as "not a pillar"
  in parallel with `app/Outbox/`. The actual SDE importer (Python,
  `make sde-import`) is scoped to a later PR — this PR only reports
  drift, never loads it.
- **YAML anchor refactor of `php-fpm` in `infra/docker-compose.yml`**.
  The php-fpm service now carries `&php-common`; the new `scheduler`
  service merges it with `<<: *php-common`. Any future PHP-side worker
  (dedicated Horizon container, queue isolation) folds in the same
  anchor so env + volumes can't drift between services by accident.

### Fixed
- **Queued jobs never ran** — `/horizon` showed `Status: Inactive,
  Total Processes: 0`. Root cause: there was no Horizon worker
  container. The `scheduler` service dispatches `ShouldQueue` jobs (e.g.
  `CheckSdeVersion`) onto Redis, but nothing consumed them. Any row in
  `sde_version_checks` only appeared because `make sde-check` uses
  `--sync` (`Bus::dispatchSync`) and bypasses the queue. New `horizon`
  compose service merges the `&php-common` YAML anchor and runs
  `php artisan horizon` — the actual worker. Healthcheck pings
  `horizon:status`. Misleading comment on the `scheduler` service
  ("work runs on php-fpm's queue workers") corrected — php-fpm is
  FastCGI, never a worker.

- **`/admin/sde-status` rendered unstyled** — the widget summary text
  ran together and the history "table" was concatenated plaintext. Root
  cause: the hand-rolled Blade used Tailwind utility classes
  (`space-y-3`, `grid-cols-2`, `flex`, `gap-3`, `px-3`) that aren't in
  Filament's bundled CSS, and we deliberately don't ship a Vite/Tailwind
  build step in phase 1. Fix: swap the custom views for Filament's
  native primitives — `SdeVersionStatusWidget` now extends
  `StatsOverviewWidget` (three stats: status / pinned / upstream, with
  EVE-palette colours and Heroicon status icons), and the `SdeStatus`
  page implements `HasTable` and uses Filament's table builder with
  typed columns (icon column for the bump flag, badge column for HTTP
  status, sortable `checked_at`, ternary filter for bumps-only). Both
  render through Filament's own CSS bundle with zero build step.

- **ADR series** started under `docs/adr/`. First entry,
  [ADR-0001](docs/adr/0001-static-reference-data.md), locks the store
  placement for EVE static reference data (SDE): MariaDB `ref_*` tables are
  canonical; Neo4j is a derived graph projection (systems + gates + regions);
  OpenSearch is a deferred derived search projection (phase 2). Load path is
  a Python `sde_importer` invoked by `make sde-import`, emitting a single
  `reference.sde_snapshot_loaded` outbox event that two Python consumers
  project onto the derived stores. Port from SupplyCore, not reimplement.
  Phase-1 table scope enumerated; dogma / blueprint / industry tables
  deferred to phase 2 alongside OpenSearch. Cross-referenced from AGENTS.md
  (§ Data ownership, § Where to go next), docs/ARCHITECTURE.md (§ Data
  ownership), and docs/CONTRACTS.md (§ Event naming). `docs/adr/README.md`
  establishes the ADR convention (format, numbering, when to write one).

- **Horizon link in the Filament admin sidebar** under a "Monitoring" group
  (`AdminPanelProvider::navigationItems()`). Registered as a plain
  `NavigationItem` (not a Page) because Horizon ships its own Vue SPA that
  replaces the page layout — embedding it inside a Filament page would fight
  its router. Clicking full-navigates to `/horizon`, which is gated on the
  same `canAccessPanel()` check as the rest of the panel (see PR #16).

### Fixed
- **Filament admin login had no CSS and wouldn't authenticate behind nginx
  TLS termination.** Three symptoms, one root cause: Laravel wasn't trusting
  the nginx proxy, so `X-Forwarded-Proto: https` was ignored, `isSecure()`
  returned false, asset URLs got generated as `http://` on an HTTPS page,
  the browser blocked them as mixed content, Livewire JS never loaded, and
  the session cookie's `Secure` flag prevented login submission from
  persisting. Fix: `app/bootstrap/app.php` now calls `trustProxies(at: '*')`
  with the full forwarded-header set. `at: '*'` is safe inside the compose
  bridge network — php-fpm:9000 is only reachable from the nginx container.
  `infra/notes.md` gained a TLS-termination section covering `APP_URL` +
  trust-proxies as paired requirements, plus a troubleshooting entry for
  the symptom.
- **Filament assets weren't published to `public/` on fresh deploys.**
  Composer's `post-autoload-dump` script now runs `artisan filament:upgrade`
  (Filament's recommended hook — publishes `filament-assets`, caches icons,
  caches views) alongside `package:discover`. Belt-and-braces:
  `make laravel-install` explicitly re-runs `filament:assets` and
  `storage:link` so a manual install after a Filament version bump is
  self-sufficient.

### Changed
- **Horizon dashboard auth is now gated on Filament admin access**, not on
  env knobs. `/horizon` piggybacks on `User::canAccessPanel()` — same login
  surface as `/admin`, same ACL, no parallel policy to keep in sync. Unauth
  hits redirect to `/admin/login` (via `redirectGuestsTo()` in
  `bootstrap/app.php`) instead of 403'ing. Horizon's middleware stack is
  now `['web', 'auth']` so sessions + redirects work; the previous
  `[Authorize::class]`-only stack gave no login bounce. When
  `UsersCharacters` tightens `canAccessPanel()` to a role check, Horizon
  tightens with it automatically.
  - **Removed:** `HORIZON_UNPROTECTED` + `HORIZON_ALLOWED_EMAILS` from
    `.env.example`, `app/.env.example`, and `infra/docker-compose.yml`.
    These were phase-0 stand-ins for "we don't have auth yet"; the Filament
    panel is the auth surface now.

### Changed
- **EVE HUD palette** replaces the generic orange accent across the landing
  page and the Filament admin. Primary accent is now cyan `#4fd0d0` (EVE's
  iconic "go / selected / friendly" colour); amber `#e5a900` takes the
  env-badge and is reserved for "yours / status" semantics; red `#ff3838`
  is added as `--danger` for "hostile signal / alert" (unused in phase 1,
  there for the spy-detection / killmail UIs). Filament's `primary` is
  flipped to `Color::Cyan` so the admin and the marketing page speak the
  same language. Radial background glows re-weighted from orange to a cyan
  top-left / amber bottom-right pair, matching the EVE website's
  background composition.

### Added
- **Filament admin panel at `/admin`** (Filament 5). Phase-1 shell: stock
  dashboard behind a login screen, orange primary accent matching the
  landing page, empty auto-discovery roots for `Resources/`, `Pages/`, and
  `Widgets/` (filled as pillars mature). Registered via
  `app/Providers/Filament/AdminPanelProvider.php`.
  - `App\Models\User` now implements `FilamentUser` with a phase-1
    `canAccessPanel(): true` policy — the only seed path is
    `make filament-user`, which is operator-run on the host. Tightens to a
    role check when `UsersCharacters` wires `spatie/laravel-permission`.
  - `make filament-user` wraps `php artisan make:filament-user` (interactive).
  - Landing page CTAs reshuffled: **Admin** is the primary action, Horizon
    and GitHub stay as secondaries.

- **Job placement rule** codified in `AGENTS.md` § Plane boundary. Concrete
  "keep in PHP" / "move to Python" criteria (runtime, row count, derived-store
  writes, concurrency) plus a three-question PR-review heuristic. Mirrored in
  `docs/CONTRACTS.md` as a reviewer checklist and cross-referenced from
  `docs/ARCHITECTURE.md`. Removes the "is this 2s?" guessing game on every PR.

### Fixed
- **Blade `tempnam()` 500 on fresh clones.** The php-fpm image now ships a
  self-healing entrypoint (`infra/php/docker-entrypoint.sh`, wired as
  `aegiscore-entrypoint`) that `chown`s `storage/` + `bootstrap/cache/` to
  `www-data` (UID 82) before handing off to php-fpm. Root cause: the host
  bind-mount carries host ownership (typically `root` from `git clone`) into
  the container, so www-data couldn't write Blade's compiled views and any
  request that rendered a view 500'd. Image tag bumped to
  `aegiscore/php-fpm:0.1.1` per the Dockerfile's "bump whenever this file
  changes" rule.

### Added
- `make laravel-fix-perms` — operator-facing belt fix that chowns
  `$(AEGISCORE_ROOT)/app/storage` + `bootstrap/cache` to UID 82 without
  restarting the container. Complements the container-side braces fix in
  `aegiscore-entrypoint`.

- **Landing page** at `/` (`app/resources/views/landing.blade.php`)
  replacing the stock Laravel welcome. Dark ops-aesthetic, mirrors the
  four-pillar domain layout, with an env badge and CTAs for Horizon
  + the GitHub repo. Self-contained: no external fonts, CDN, or Vite
  build step (inline `<style>` + system fonts). When we scaffold the
  Filament panel, `/` will redirect to it and the landing moves out
  of the default route.

- **Laravel 13 control-plane skeleton** under `app/`:
  - `laravel/framework ^13.0` + `laravel/horizon ^5.39` (queues/monitoring)
    + `laravel/sanctum ^4.0` (API auth) + `laravel/tinker`.
  - `filament/filament ^5.0` (admin panels) + `livewire/livewire ^4.1`
    (Livewire v4 required by Filament 5).
  - `spatie/laravel-data ^4.20` (typed DTOs) + `spatie/laravel-permission
    ^6.21` (RBAC).
  - Backend-store PHP clients:
    `opensearch-project/opensearch-php ^2.4`,
    `influxdata/influxdb-client-php ^3.8`,
    `laudis/neo4j-php-client ^3.3`.
- **4-pillar domain layout** under `app/app/Domains/`:
  `SpyDetection`, `BuyallDoctrines`, `KillmailsBattleTheaters`,
  `UsersCharacters`. Each pillar has `Actions/ Data/ Events/ Models/
  Projections/`. Rules documented in `app/app/Domains/README.md` —
  no cross-pillar Eloquent relations, no direct derived-store writes
  from Laravel (plane boundary).
- **Outbox plumbing** for the Laravel → Python plane boundary:
  - `database/migrations/…_create_outbox_events_table.php`: ULID
    `event_id`, indexed `(processed_at, id)` for the SKIP-LOCKED
    consumer loop, and `(aggregate_type, aggregate_id)` for replay.
  - `app/Outbox/DomainEvent.php`: abstract base, requires
    `EVENT_TYPE` constant + `aggregateType()` / `aggregateId()` /
    `payload()`.
  - `app/Outbox/OutboxEvent.php`: Eloquent model with
    `unprocessed()` scope.
  - `app/Outbox/OutboxRecorder.php`: single write path. Refuses to
    run outside a DB transaction so the outbox row and the
    control-plane mutation always commit atomically.
  - Reference event:
    `app/Domains/KillmailsBattleTheaters/Events/KillmailIngested.php`.
  - Feature test: `tests/Feature/Outbox/OutboxRecorderTest.php`.
- `config/aegiscore.php`: single source of truth for derived-store
  connection details + plane-boundary thresholds
  (`max_job_duration_seconds = 2`, `max_job_rows = 100`).
- Makefile targets: `laravel-install`, `laravel-key`,
  `laravel-migrate`, `horizon-install`, `horizon-publish`,
  `artisan CMD="…"`, `composer CMD="…"`, `test`, `lint`.
- `make update` — git-side "reconcile to latest": `git pull --ff-only`
  + `docker compose up -d` + `composer install` + `artisan migrate`.
  Does not restart containers (use `make restart` for that).
  Separate from `make pull`, which pulls Docker images.
- php-fpm service now receives Laravel-shaped env:
  `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`,
  `LOG_CHANNEL=stderr`, `DB_CONNECTION=mariadb` + `DB_*`,
  `REDIS_CLIENT=phpredis` + `REDIS_*`, `CACHE_STORE=redis`,
  `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`.
- Root `.env.example` declares `APP_KEY` and `APP_URL`. Generate
  `APP_KEY` once post-install with `make laravel-key` and paste.

### Changed
- **OpenSearch security plugin disabled** for phase 1
  (`DISABLE_SECURITY_PLUGIN=true`, `DISABLE_INSTALL_DEMO_CONFIG=true`). The
  demo config's self-signed TLS broke APOC → OpenSearch integration from
  Neo4j (cert validation failures) and added friction with zero security
  gain on an internal Docker network. Dashboards and PHP now talk plain
  `http://opensearch:9200` with no auth header. Trade-off and restore path
  are documented in `infra/notes.md` § OpenSearch security posture.
- Dropped `OPENSEARCH_ADMIN_PASSWORD` from `.env.example` and the compose
  file. Restore it when re-enabling the security plugin for prod.

### Fixed
- Nginx container no longer reports `unhealthy` while serving IPv4 traffic.
  Root cause: busybox `wget` resolves `localhost` to IPv6 `::1`, and the
  shipped nginx config only listened on IPv4 `0.0.0.0:80`. The healthcheck
  now uses `127.0.0.1` explicitly (belt), and `nginx/conf.d/aegiscore.conf`
  adds `listen [::]:80 default_server` (braces).
- `/health` response no longer carries a duplicate `Content-Type` header
  (`application/octet-stream` + `text/plain`). Switched the location from
  `add_header Content-Type` to `default_type text/plain`, which nginx
  treats as a content-negotiation hint instead of appending a second header.

### Changed
- `infra/notes.md` calls out that `AEGISCORE_ROOT` is case-sensitive and
  must match the on-disk project path exactly — the silent fallback to
  `/opt/aegiscore` on typo produces an empty bind-mount shadow and makes
  nginx/PHP serve nothing. Added a matching troubleshooting entry.

### Added
- GitHub Actions CI (`.github/workflows/ci.yml`):
  - `docker compose config` against `.env.example`
  - env-coverage check (fails if `${VAR}` in compose isn't in `.env.example`)
  - `hadolint` on `infra/php/Dockerfile` (error-level only)
  - `php -l` across `app/`
  - buildx build of the php-fpm image (no push) with GHA layer cache

### Fixed
- php-fpm service now declares `pull_policy: build` so Portainer and
  `docker compose pull` don't fail with `pull access denied for
  aegiscore/php-fpm`. `make pull` uses `--ignore-buildable` to skip locally
  built images.

### Added
- `php-fpm` container for the PHP control plane, now built locally from
  `infra/php/Dockerfile` (tag `aegiscore/php-fpm:0.1.0`) with the PHP extensions
  Laravel 12 + Horizon + Filament need (`pdo_mysql`, `redis`, `intl`, `bcmath`,
  `gd`, `mbstring`, `opcache`, `pcntl`, `sockets`, `zip`) + Composer 2.
- `redis:7-alpine` container for Laravel cache / sessions / queues / Horizon.
  Password-protected, AOF persistence, `allkeys-lru` at 512mb default, bound
  to `127.0.0.1:6379` only.
- Nginx now serves `app/public/` and proxies `*.php` to `php-fpm:9000`.
- Stub `app/public/index.php` front controller returning the `{data, meta}`
  envelope.
- `php/conf.d/aegiscore.ini` with sane PHP defaults + OPcache.
- Redis + backend-service env vars surfaced to PHP (`REDIS_HOST`, `REDIS_PORT`,
  `REDIS_PASSWORD`, plus `MARIADB_*`, `OPENSEARCH_*`, `INFLUXDB_*`, `NEO4J_*`).
- `make build`, `make php-shell`, `make redis-cli` targets.

### Changed
- Architecture + AGENTS.md codify the **Laravel ↔ Python plane boundary** as
  policy (not best-effort): Laravel queues are control-plane only, <2s / <100
  rows; cross-plane triggers go through the outbox pattern.
- `docs/CONTRACTS.md` adds the **outbox contract** — schema, consumer
  semantics, event naming, transport plan.

## [0.1.0] — 2026-04-13

### Added
- Initial infra bootstrap.
- `infra/docker-compose.yml` with pinned images:
  - `mariadb:lts`
  - `opensearchproject/opensearch:3.6.0`
  - `opensearchproject/opensearch-dashboards:3.6.0`
  - `influxdb:2.7`
  - `neo4j:2026.03-community`
  - `nginx:1.27-alpine`
- Healthchecks on every service + `depends_on: condition: service_healthy`.
- `AGENTS.md` as the project index for humans and agents.
- `docs/ARCHITECTURE.md`, `docs/ROADMAP.md`, `docs/CONTRACTS.md`.
- `.env.example` with `CHANGE_ME` placeholders + dev-friendly Neo4j memory defaults.
- `Makefile` with `up`, `down`, `restart`, `ps`, `logs`, `logs-<svc>`, `pull`,
  `bootstrap`, `clean-logs`.
- `infra/notes.md` with operator guidance + troubleshooting.
- `nginx/conf.d/aegiscore.conf` stub with `/health` and commented vhost examples.

### Security
- MariaDB (`3306`) and Neo4j Bolt (`7687`) bound to `127.0.0.1` only.
- Container state paths (`docker/`) and TLS material (`nginx/certs/`) gitignored.
