# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
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
