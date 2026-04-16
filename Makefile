AEGISCORE_ROOT ?= /opt/aegiscore
COMPOSE := docker compose -f infra/docker-compose.yml --env-file .env

-include .env
export

.PHONY: help up down restart ps logs pull update bootstrap clean-logs laravel-fix-perms

help:
	@echo "AegisCore Makefile"
	@echo ""
	@echo "  make bootstrap    create $(AEGISCORE_ROOT)/docker/* with correct ownership (one-time)"
	@echo "  make up           start the full stack"
	@echo "  make down         stop the full stack"
	@echo "  make restart      restart all services"
	@echo "  make ps           list running services"
	@echo "  make logs         tail logs from all services"
	@echo "  make logs-<svc>   tail logs from one service (e.g. make logs-neo4j)"
	@echo "  make pull         pull latest pinned images"
	@echo "  make update       git pull + composer install + artisan migrate (no container restart)"
	@echo "  make build        build locally-built images (php-fpm)"
	@echo "  make php-shell    open a shell in the php-fpm container"
	@echo "  make redis-cli    open a redis-cli session (auth'd)"
	@echo ""
	@echo "  Laravel control plane:"
	@echo "    make laravel-install   composer install + print APP_KEY hint"
	@echo "    make laravel-key       generate a fresh APP_KEY (copy into .env)"
	@echo "    make laravel-migrate   run all pending migrations"
	@echo "    make horizon-install   one-time Horizon config + assets publish"
	@echo "    make filament-user     create an admin user for the /admin panel (interactive)"
	@echo "    make laravel-fix-perms chown storage/ + bootstrap/cache/ to www-data (UID 82)"
	@echo "    make artisan  CMD=\"…\"  run any artisan command"
	@echo "    make composer CMD=\"…\"  run any composer command"
	@echo "    make test              run phpunit via artisan test"
	@echo "    make lint              pint --test"
	@echo ""
	@echo "  make sde-check              run the SDE version-drift check now (inline)"
	@echo "  make sde-import             download CCP's SDE and load all ref_* tables (one-shot)"
	@echo "  make neo4j-sync-universe    project ref_* universe topology into Neo4j (one-shot)"
	@echo "  make market-poll            pull order-book snapshots into market_orders (one-shot)"
	@echo "  make market-import          import EVE Ref daily market-history CSVs (one-shot)"
	@echo "  make outbox-relay           drain MariaDB outbox into InfluxDB (one-shot)"
	@echo "  make market-status          show MariaDB + InfluxDB market-data coverage"
	@echo "  make outbox-status          show outbox backlog + dead letters"
	@echo ""
	@echo "  make clean-logs   truncate nginx access/error logs"

up:
	$(COMPOSE) up -d

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) restart

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f --tail=200

logs-%:
	$(COMPOSE) logs -f --tail=200 $*

pull:
	$(COMPOSE) pull --ignore-buildable

# Pull latest code + PHP deps + DB schema. Safe on dev and prod. Does NOT
# restart containers — run `make restart` separately if you need that
# (e.g. after a Dockerfile / PHP extension change).
#
# Uses --ff-only so divergent history fails loudly instead of silently
# creating merge commits. Uses --no-interaction / --force so prompts
# don't wedge unattended runs.
#
# `--build` on `compose up` is load-bearing: without it, long-lived
# containers (php-fpm, scheduler, horizon, market_poll_scheduler,
# market_import_scheduler) keep using the locally-cached image even
# when the source has changed under their feet. Compose only builds
# missing images by default; with --build it rebuilds when the
# Dockerfile context content changes (cheap thanks to layer caching
# when nothing changed). Without this, a `git pull` that updated
# Python source under `python/market_poller/` would land on disk but
# the running scheduler would still be on the old image — exactly
# the failure mode that produced PR-#59's "unrecognized arguments:
# --interval 300" runtime error.
# IMPORTANT: Never recreate data store containers (mariadb, redis,
# neo4j, opensearch, influxdb2) during a code deploy. Only rebuild
# application containers. Data store config changes go through
# `make safe-restart-mariadb` or manual `docker compose restart <svc>`.
update:
	git pull --ff-only
	./scripts/backup-mariadb.sh
	@echo ""
	@echo "Rebuilding application containers (data stores excluded)..."
	$(COMPOSE) build php-fpm
	$(COMPOSE) up -d php-fpm scheduler horizon
	$(COMPOSE) up -d --build killmail_stream killmail_backfill_scheduler \
		market_poll_scheduler market_import_scheduler \
		killmail_search_scheduler outbox_relay nginx
	$(COMPOSE) exec php-fpm composer install --optimize-autoloader --no-interaction
	$(COMPOSE) exec php-fpm php artisan migrate --force
	@echo ""
	@echo "Stack updated. Data stores were NOT touched."
	@echo "If you need to restart MariaDB (config change), use:"
	@echo "    make safe-restart-mariadb"

# Backup MariaDB — logical dump with 7-day retention.
backup:
	./scripts/backup-mariadb.sh

# Safe MariaDB restart for config changes (InnoDB settings, etc).
# Stops traffic → clean shutdown → backup → restart → verify.
safe-restart-mariadb:
	./scripts/safe-mariadb-restart.sh

bootstrap:
	sudo mkdir -p \
		$(AEGISCORE_ROOT)/docker/mariadb/data \
		$(AEGISCORE_ROOT)/docker/mariadb/logs \
		$(AEGISCORE_ROOT)/docker/opensearch/data \
		$(AEGISCORE_ROOT)/docker/opensearch/logs \
		$(AEGISCORE_ROOT)/docker/influxdb2/data \
		$(AEGISCORE_ROOT)/docker/influxdb2/config \
		$(AEGISCORE_ROOT)/docker/neo4j/data \
		$(AEGISCORE_ROOT)/docker/neo4j/logs \
		$(AEGISCORE_ROOT)/docker/neo4j/import \
		$(AEGISCORE_ROOT)/docker/neo4j/plugins \
		$(AEGISCORE_ROOT)/docker/redis/data \
		$(AEGISCORE_ROOT)/docker/nginx/logs \
		$(AEGISCORE_ROOT)/nginx/certs
	sudo chown -R 999:999   $(AEGISCORE_ROOT)/docker/mariadb $(AEGISCORE_ROOT)/docker/redis
	sudo chown -R 1000:1000 $(AEGISCORE_ROOT)/docker/opensearch $(AEGISCORE_ROOT)/docker/influxdb2
	sudo chown -R 7474:7474 $(AEGISCORE_ROOT)/docker/neo4j
	sudo chown -R 1000:1000 $(AEGISCORE_ROOT)/infra/sde
	@echo "bootstrap complete at $(AEGISCORE_ROOT)"

.PHONY: build php-shell redis-cli composer artisan laravel-install laravel-migrate horizon-install horizon-publish laravel-key filament-user test lint sde-check sde-import neo4j-sync-universe market-poll market-import outbox-relay market-status outbox-status
build:
	$(COMPOSE) build

php-shell:
	$(COMPOSE) exec php-fpm sh

redis-cli:
	$(COMPOSE) exec redis sh -c 'redis-cli -a "$$REDIS_PASSWORD"'

# Arbitrary composer invocation in the php-fpm container.
# Example: make composer CMD="require acme/thing:^1.2"
composer:
	$(COMPOSE) exec php-fpm composer $(CMD)

# Arbitrary artisan invocation in the php-fpm container.
# Example: make artisan CMD="migrate --force"
artisan:
	$(COMPOSE) exec php-fpm php artisan $(CMD)

# One-time (or after composer.json changes): install vendor/ + publish the
# Filament/Livewire asset bundle so /admin has CSS + JS on first hit.
#
# composer's `post-autoload-dump` hook already runs `filament:upgrade` (which
# publishes assets), but we repeat it here explicitly so that:
#   (a) a manual `make laravel-install` after a Filament-version bump is
#       self-sufficient even if composer didn't re-autoload, and
#   (b) operators get one target that does "make this deploy's PHP side sane"
#       end-to-end.
#
# Prefer --no-dev in prod deployments; dev stacks get the full tree.
laravel-install:
	$(COMPOSE) exec php-fpm composer install --optimize-autoloader
	$(COMPOSE) exec php-fpm php artisan filament:assets
	$(COMPOSE) exec php-fpm php artisan storage:link
	@echo ""
	@echo "If this is a fresh install, run:"
	@echo "  make laravel-key       # generates APP_KEY; copy it into .env"
	@echo "  make laravel-migrate   # creates tables, including outbox"
	@echo "  make horizon-install   # publishes Horizon config + assets"

laravel-key:
	$(COMPOSE) exec php-fpm php artisan key:generate --show

laravel-migrate:
	$(COMPOSE) exec php-fpm php artisan migrate --force

horizon-install:
	$(COMPOSE) exec php-fpm php artisan horizon:install

# Create a new admin user for the Filament panel at /admin.
# Interactive — prompts for name, email, password. Only way to populate the
# panel's user pool in phase 1 (no public signup / EVE SSO yet).
# Needs a TTY, so `make filament-user` must be run from an interactive shell.
filament-user:
	$(COMPOSE) exec php-fpm php artisan make:filament-user

# Operator-facing belt fix for Blade's `tempnam()` 500 error.
#
# The container-side braces fix is the `aegiscore-entrypoint` in infra/php/
# that chowns these dirs on every php-fpm start. This target is for the
# case where an operator wants to fix an already-running container without
# restarting it (or wants to fix a host checkout before first `make up`).
#
# UID 82 is www-data inside the upstream php:8.4-fpm-alpine image.
laravel-fix-perms:
	sudo chown -R 82:82 $(AEGISCORE_ROOT)/app/storage $(AEGISCORE_ROOT)/app/bootstrap/cache
	@echo "storage/ + bootstrap/cache/ now owned by www-data (UID 82)"

horizon-publish:
	$(COMPOSE) exec php-fpm php artisan vendor:publish --tag=horizon-config --force
	$(COMPOSE) exec php-fpm php artisan vendor:publish --tag=horizon-assets --force

# Run the SDE version-drift check inline (bypasses Horizon, prints result).
# Scheduled version runs daily at 08:00 UTC via the `scheduler` container.
sde-check:
	$(COMPOSE) exec php-fpm php artisan reference:check-sde-version --sync

# Download CCP's SDE JSONL zip and load all ref_* tables in one transaction.
# One-shot container — the `tools` profile keeps it out of `docker compose up`.
#
# `--build` forces compose to rebuild the image from `python/` before running.
# Without it, compose reuses the locally-tagged `aegiscore/sde-importer:0.1.0`
# image as long as the tag exists, even if `python/sde_importer/*.py` has
# changed on disk — so a `git pull` that updates the importer wouldn't
# actually take effect on the next `make sde-import`. Rebuilds are cheap
# thanks to Docker's layer cache; only changed layers re-run.
#
# Overrides:
#   SDE_ARGS="--only-download"            # fetch + extract, skip DB load
#   SDE_ARGS="--skip-download --extract-dir=/tmp/sde/extracted"   # iterate on loaders
sde-import:
	$(COMPOSE) --profile tools run --rm --build sde_importer $(SDE_ARGS)

# Project SDE universe topology (regions / constellations / systems /
# stargates / NPC stations) from MariaDB ref_* tables into Neo4j as the
# graph-backed source for the map renderer module.
#
# Run order: `make sde-import` first (populates ref_*), then this target
# (mirrors the universe to Neo4j). Re-runs are idempotent under MERGE;
# pass GRAPH_ARGS="--rebuild" to DETACH DELETE the owned labels first.
#
# Overrides:
#   GRAPH_ARGS="--dry-run"                 # log counts; don't write
#   GRAPH_ARGS="--only=jumps"              # replay one stage
#   GRAPH_ARGS="--rebuild"                 # full wipe + re-merge
neo4j-sync-universe:
	$(COMPOSE) --profile tools run --rm --build graph_universe_sync $(GRAPH_ARGS)

# One pass of the market poller — walks enabled market_watched_locations,
# fetches each location's current order book from ESI, bulk-inserts into
# market_orders, emits one `market.orders_snapshot_ingested` outbox event
# per successful location. One-shot; the caller owns the cadence.
#
# `--build` forces compose to rebuild the image from `python/` before
# running, mirroring sde-import / neo4j-sync-universe for the same
# "git pull should take effect next run" reason.
#
# Overrides:
#   MARKET_ARGS="--dry-run"                         # fetch + log, don't insert
#   MARKET_ARGS="--only-location-id=60003760"       # only poll Jita 4-4
#   MARKET_ARGS="--log-level=DEBUG"                 # verbose per-page logs
market-poll:
	$(COMPOSE) --profile tools run --rm --build market_poller $(MARKET_ARGS)

# Import EVE Ref daily market-history CSV dumps into `market_history`.
# Reconciles against totals.json — only (re)downloads days that are
# missing locally or have fewer rows than the published total.
# Idempotent on re-run: once a day is complete locally, the reconcile
# check skips it.
#
# First-run backfill from 2025-01-01 → yesterday UTC takes a while
# (~470 days × ~700 KB per download) but each day is its own
# transaction so interrupting + restarting loses only the in-flight
# day. Subsequent runs are quick — reconcile + 1-2 new days.
#
# Overrides:
#   MARKET_IMPORT_ARGS="--dry-run"                          # fetch + count, don't commit
#   MARKET_IMPORT_ARGS="--only-date=2026-04-14"             # single day only
#   MARKET_IMPORT_ARGS="--from=2024-06-01 --to=2024-12-31"  # custom window
#   MARKET_IMPORT_ARGS="--force-redownload"                 # bypass reconcile
#   MARKET_IMPORT_ARGS="--log-level=DEBUG"
market-import:
	$(COMPOSE) --profile tools run --rm --build market_importer $(MARKET_IMPORT_ARGS)

# Drain the MariaDB outbox into InfluxDB once + exit. Useful for
# "process the backlog now" or testing a projector change without
# bouncing the long-lived `outbox_relay` service. Each tick within
# the drain claims OUTBOX_RELAY_BATCH_SIZE rows; exits when a pass
# returns zero claims.
#
# Overrides:
#   OUTBOX_RELAY_ARGS="--log-level=DEBUG"
#   OUTBOX_RELAY_ARGS="--batch-size=200"
#   OUTBOX_RELAY_ARGS="--max-attempts=10"
outbox-relay:
	$(COMPOSE) --profile tools run --rm --build outbox_relay_oneshot $(OUTBOX_RELAY_ARGS)

# One-shot EVE Ref killmail backfill. Reconciles local state against
# totals.json and downloads + ingests missing or updated days.
#
# Overrides:
#   KILLMAIL_BACKFILL_ARGS="--dry-run"
#   KILLMAIL_BACKFILL_ARGS="--only-date=2026-04-01"
#   KILLMAIL_BACKFILL_ARGS="--from=2025-01-01 --to=2025-12-31"
killmail-backfill:
	$(COMPOSE) --profile tools run --rm --build killmail_backfill $(KILLMAIL_BACKFILL_ARGS)

# One-shot R2Z2 live stream (runs until Ctrl-C). For ad-hoc testing.
# The long-lived `killmail_stream` compose service handles production.
killmail-stream:
	$(COMPOSE) --profile tools run --rm --build killmail_backfill stream $(KILLMAIL_STREAM_ARGS)

# One-shot OpenSearch killmail index backfill. Indexes all enriched
# killmails that aren't in the index yet.
killmail-search:
	$(COMPOSE) --profile tools run --rm --build killmail_search $(KILLMAIL_SEARCH_ARGS)

# Quick read-only check that market data is landing in BOTH planes.
# Hits MariaDB for raw row counts + date ranges of market_history /
# market_orders, then InfluxDB for point counts + latest timestamps
# of the corresponding measurements (market_history, market_orderbook).
#
# Use after `make update` to verify the schedulers + outbox_relay
# are end-to-end happy. Should NOT be run hot — it's a snapshot,
# not a watcher.
market-status:
	@echo "== MariaDB =="
	@$(COMPOSE) exec -T mariadb mariadb \
	    -u"$${MARIADB_USER:-aegiscore}" \
	    -p"$${MARIADB_PASSWORD}" \
	    "$${MARIADB_DATABASE:-aegiscore}" \
	    -e "SELECT 'market_history' AS source, COUNT(*) AS row_count, MIN(trade_date) AS earliest, MAX(trade_date) AS latest FROM market_history; \
	        SELECT 'market_orders' AS source, COUNT(*) AS row_count, MIN(observed_at) AS earliest, MAX(observed_at) AS latest FROM market_orders;"
	@echo ""
	@echo "== InfluxDB (point counts) =="
	@$(COMPOSE) exec -T influxdb2 influx query \
	    --token "$${INFLUXDB_ADMIN_TOKEN}" \
	    --org "$${INFLUXDB_ORG:-aegiscore}" \
	    'from(bucket: "primary") |> range(start: 2000-01-01T00:00:00Z) |> filter(fn: (r) => r._measurement == "market_history" or r._measurement == "market_orderbook") |> filter(fn: (r) => r._field == "average" or r._field == "best_price") |> group(columns: ["_measurement"]) |> count() |> keep(columns: ["_measurement", "_value"]) |> rename(columns: {_value: "points"})'
	@echo ""
	@echo "== InfluxDB (latest timestamps) =="
	@$(COMPOSE) exec -T influxdb2 influx query \
	    --token "$${INFLUXDB_ADMIN_TOKEN}" \
	    --org "$${INFLUXDB_ORG:-aegiscore}" \
	    'from(bucket: "primary") |> range(start: 2000-01-01T00:00:00Z) |> filter(fn: (r) => r._measurement == "market_history" or r._measurement == "market_orderbook") |> filter(fn: (r) => r._field == "average" or r._field == "best_price") |> group(columns: ["_measurement"]) |> last() |> keep(columns: ["_measurement", "_time"])'

# Outbox health snapshot. Three blocks:
#
#   1. Backlog summary: unprocessed (claimable) vs dead-lettered
#      (attempts >= 5, won't claim) vs processed.
#   2. Per-event-type unprocessed counts — answers "what's piling
#      up?" without grepping logs.
#   3. Dead-letter detail (up to 10): id, event_type, attempts,
#      and a 200-char excerpt of last_error so the operator can
#      decide whether to fix-and-reset or investigate further.
#
# Pairs with the dead-letter recovery snippet documented in
# python/outbox_relay/README.md.
outbox-status:
	@echo "== Backlog summary =="
	@$(COMPOSE) exec -T mariadb mariadb \
	    -u"$${MARIADB_USER:-aegiscore}" \
	    -p"$${MARIADB_PASSWORD}" \
	    "$${MARIADB_DATABASE:-aegiscore}" \
	    -e "SELECT 'unprocessed (claimable)' AS status, COUNT(*) AS row_count FROM outbox WHERE processed_at IS NULL AND attempts < 5; \
	        SELECT 'dead_letters (attempts >= 5)' AS status, COUNT(*) AS row_count FROM outbox WHERE processed_at IS NULL AND attempts >= 5; \
	        SELECT 'processed' AS status, COUNT(*) AS row_count FROM outbox WHERE processed_at IS NOT NULL;"
	@echo ""
	@echo "== Unprocessed by event_type =="
	@$(COMPOSE) exec -T mariadb mariadb \
	    -u"$${MARIADB_USER:-aegiscore}" \
	    -p"$${MARIADB_PASSWORD}" \
	    "$${MARIADB_DATABASE:-aegiscore}" \
	    -e "SELECT event_type, COUNT(*) AS row_count, MIN(created_at) AS oldest FROM outbox WHERE processed_at IS NULL GROUP BY event_type ORDER BY row_count DESC;"
	@echo ""
	@echo "== Dead letters (up to 10) =="
	@$(COMPOSE) exec -T mariadb mariadb \
	    -u"$${MARIADB_USER:-aegiscore}" \
	    -p"$${MARIADB_PASSWORD}" \
	    "$${MARIADB_DATABASE:-aegiscore}" \
	    -e "SELECT id, event_type, attempts, LEFT(COALESCE(last_error, ''), 200) AS error_excerpt FROM outbox WHERE processed_at IS NULL AND attempts >= 5 ORDER BY id DESC LIMIT 10;"

test:
	$(COMPOSE) exec php-fpm php artisan test

lint:
	$(COMPOSE) exec php-fpm ./vendor/bin/pint --test

clean-logs:
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/access.log 2>/dev/null || true
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/error.log  2>/dev/null || true
