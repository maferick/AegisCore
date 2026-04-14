# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
    live ESI snapshots or future order-book dumps. `TIMESTAMP(6)`
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
