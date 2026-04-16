# CLAUDE.md — AegisCore

Session-start brief for Claude. Project docs in [`AGENTS.md`](AGENTS.md) and [`docs/adr/`](docs/adr/); this file = short list of things not in code/git history but still bite.

## Stack at a glance

- **Control plane:** Laravel 12 on php-fpm 8.4-alpine, Livewire, Filament 5, Horizon. Admin panel `/admin`, user portal `/portal`.
- **Execution plane:** Python workers — `market_poller`, `killmail_stream`, `killmail_backfill`, `outbox_relay`, `neo4j-sync`, `sde-importer`.
- **Data stores:** MariaDB (canonical + outbox), Redis (cache/queue/Horizon), InfluxDB (timeseries, Python-owned), OpenSearch (killmail search, Python-owned), Neo4j (graph, Python-owned).
- **Entry:** `make up`; full targets in `make help`.

## Plane boundary (review-blocker)

Laravel queue jobs: p95 < 2s, ≤ 100 rows default (≤ 500 with explicit chunking). Laravel **never** writes Neo4j / OpenSearch / InfluxDB — Python-owned derived stores. Cross-plane triggers go through MariaDB `outbox`. Full rules in [`AGENTS.md`](AGENTS.md) § Plane boundary.

## Critical don'ts (past incidents)

- **Never `docker compose restart mariadb` after InnoDB config change** (buffer pool, log file size, flush method). InnoDB resizes redo log on startup, can corrupt tables. Use `make safe-restart-mariadb`. Incident: 2026-04-16 wiped 7.7M killmails.
- **Never remove `force="true"` from `app/phpunit.xml` DB entries, never remove `<server>` entries.** `docker-compose` injects `DB_CONNECTION=mariadb` + `DB_DATABASE=aegiscore` into `$_SERVER`; Laravel `env()` reads first. Without force + server overrides, phpunit runs `migrate:fresh` against production. Wiped prod 2026-04-16 before guards in `app/tests/TestCase.php` + `app/tests/bootstrap.php` landed.
- **Never run `make test` on new env without `make test-db-setup` first.** Tests target separate `aegiscore_test` MariaDB schema (hardcoded `testing_mariadb` connection in `app/config/database.php`); schema + grants need one-time provision.
- **Migrations use MariaDB-specific SQL** (PARTITION on `market_history`). Do not suggest sqlite for tests — tried, failed.

## Commonly used make targets

- `make up` / `make down` / `make restart` / `make ps` / `make logs`
- `make update` — git pull + composer install + migrate (skips data-store containers by design)
- `make test` — phpunit against `aegiscore_test` schema
- `make test-db-setup` — one-time schema + grants for test DB
- `make killmail-backfill` — EVE Ref reconciliation; args via `KILLMAIL_BACKFILL_ARGS="--dry-run"` etc.
- `make backup` — mariadb-dump into `/opt/AegisCore/backups/mariadb/`
- `make safe-restart-mariadb` — stop writers → clean InnoDB shutdown → backup → restart → verify. Use for any InnoDB config change.
- Full list: `make help`

## Architectural decision records

All in [`docs/adr/`](docs/adr/). Read before proposing cross-cutting change.

1. [0001](docs/adr/0001-static-reference-data.md) — SDE ref_* tables
2. [0002](docs/adr/0002-eve-sso-and-esi-client.md) — EVE SSO + ESI client
3. [0003](docs/adr/0003-data-placement-freeze.md) — data placement (who owns what store)
4. [0004](docs/adr/0004-market-data-ingest.md) — market data ingest
5. [0005](docs/adr/0005-private-market-hub-overlay.md) — private market hub overlay

Add new ADR rather than mutate overlay of existing one.

## Conventions

- **No env() in any `testing_*` connection block** in `app/config/database.php`. Literals only — 2026-04-16 incident proved env-driven test connections = live wire.
- **ADR-0005 intersection rule enforced only in `MarketHubAccessPolicy`.** Every UI / Livewire / Filament / API path surfacing hub data routes through `canView()` / `visibleHubsFor()`. Reinventing check at call site = review-blocker.
- **`/account/settings` = legacy URL.** UX moved into Filament Portal at `/portal/account-settings`. Old URL 302s there; new code uses `route('filament.portal.pages.account-settings')` directly.
- **Outbox events = only cross-plane trigger.** Laravel never calls Python workers or pushes to Python queues. See `docs/CONTRACTS.md`.

## Auto-memory

Persistent notes in `/root/.claude/projects/-opt-AegisCore/memory/` (index at `MEMORY.md`). Feedback that survives sessions — user preferences, incident reasons, external system refs — goes there, not here.