# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
