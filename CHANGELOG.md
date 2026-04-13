# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
