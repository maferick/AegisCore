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
	@echo "  make killmail-backfill      reconcile local killmails against EVE Ref totals.json (one-shot)"
	@echo "                              overrides: KILLMAIL_BACKFILL_ARGS=\"--dry-run\" | --only-date=YYYY-MM-DD | --from=… --to=…"
	@echo "  make killmail-stream        ad-hoc R2Z2 live stream (runs until Ctrl-C)"
	@echo "  make killmail-search        backfill OpenSearch killmail index from MariaDB (one-shot)"
	@echo "  make theater-cluster        one-shot battle-theater clustering pass (see ADR-0006)"
	@echo "                              overrides: THEATER_WINDOW_HOURS=720 | THEATER_MIN_PARTICIPANTS=5"
	@echo ""
	@echo "  Battle role scoring (Specs 2-7):"
	@echo "    make battle-graph          BATTLE=<id> ALLIANCE=<id>   Spec 2 — Neo4j graph projection"
	@echo "    make battle-partition      BATTLE=<id> ALLIANCE=<id>   Spec 3 — sub-fleet partitioning"
	@echo "    make battle-features       BATTLE=<id> ALLIANCE=<id>   Spec 4 — feature extraction"
	@echo "    make battle-score          BATTLE=<id> ALLIANCE=<id>   Spec 5/7 — role scoring"
	@echo "    make battle-pipeline       BATTLE=<id> ALLIANCE=<id>   run 2→3→4→5 for one pair"
	@echo "    make battle-refresh-priors                              Spec 7 — nightly priors (runs now)"
	@echo "    make battle-evaluate      WEIGHT=<id>                   Spec 7 — calibration eval"
	@echo "    make battle-promote       WEIGHT=<id> ROLES=logi,tackle Spec 7 — promote roles to production"
	@echo "    make battle-seed-truth    USER=1                        seed 8-battle FC truth set"
	@echo "  make outbox-relay           drain MariaDB outbox into InfluxDB (one-shot)"
	@echo "  make market-status          show MariaDB + InfluxDB market-data coverage"
	@echo "  make outbox-status          show outbox backlog + dead letters"
	@echo ""
	@echo "  make test-db-setup          one-time: create aegiscore_test schema + grants for phpunit"
	@echo "  make seed-classification    idempotent: re-seed coalition blocs + relationship types + labels"
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
	@echo "Seeding classification reference data (idempotent)..."
	$(COMPOSE) exec php-fpm php artisan db:seed --class=CoalitionBlocSeeder --force
	$(COMPOSE) exec php-fpm php artisan db:seed --class=CoalitionRelationshipTypeSeeder --force
	$(COMPOSE) exec php-fpm php artisan db:seed --class=CoalitionEntityLabelSeeder --force
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

.PHONY: build php-shell redis-cli composer artisan laravel-install laravel-migrate horizon-install horizon-publish laravel-key filament-user test test-db-setup seed-classification theater-cluster lint sde-check sde-import neo4j-sync-universe market-poll market-import outbox-relay market-status outbox-status
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

# Targeted backfill of killmails.victim_faction_id from EVE Ref archives.
# Skips full attacker/item re-ingest — only updates the one column.
# Args: KILLMAIL_VF_ARGS="--days=90"
killmail-backfill-victim-faction:
	$(COMPOSE) --profile tools run --rm --build killmail_backfill backfill-victim-faction $(KILLMAIL_VF_ARGS)

# One-shot battle-theater clustering pass. Walks the last
# THEATER_WINDOW_HOURS of enriched killmails, rebuilds every unlocked
# theater, and locks any that aged past THEATER_LOCK_AFTER_HOURS. Safe
# to run at any time; the long-lived theater_clustering_scheduler
# service runs the same code on a 5-minute loop.
#
# Overrides (per-invocation):
#   THEATER_WINDOW_HOURS=720 make theater-cluster   # 30-day backfill
#   THEATER_MIN_PARTICIPANTS=5 make theater-cluster # looser threshold
theater-cluster:
	$(COMPOSE) --profile tools run --rm --build theater_cluster

# =============================================================
# Battle role scoring — Specs 2 through 7
#   BATTLE=<theater_id> ALLIANCE=<alliance_id> required for
#   per-pair targets; pipeline chains Specs 2→3→4→5 in order.
# =============================================================
battle-graph:
	$(COMPOSE) --profile tools run --rm --build battle_graph run --battle-id "$(BATTLE)" --alliance-id "$(ALLIANCE)"

battle-partition:
	$(COMPOSE) --profile tools run --rm --build battle_partition run --battle-id "$(BATTLE)" --alliance-id "$(ALLIANCE)"

battle-features:
	$(COMPOSE) --profile tools run --rm --build battle_features run --battle-id "$(BATTLE)" --alliance-id "$(ALLIANCE)"

battle-score:
	$(COMPOSE) --profile tools run --rm --build battle_role_scoring run --battle-id "$(BATTLE)" --alliance-id "$(ALLIANCE)" --weight-label "$(WEIGHT_LABEL)"

# Full pipeline for one (battle, alliance) pair. No parallelism —
# each stage must finish before the next reads its output.
battle-pipeline:
	@test -n "$(BATTLE)"   || (echo "BATTLE=<theater_id> required" && exit 1)
	@test -n "$(ALLIANCE)" || (echo "ALLIANCE=<alliance_id> required" && exit 1)
	$(MAKE) battle-graph     BATTLE=$(BATTLE) ALLIANCE=$(ALLIANCE)
	$(MAKE) battle-partition BATTLE=$(BATTLE) ALLIANCE=$(ALLIANCE)
	$(MAKE) battle-features  BATTLE=$(BATTLE) ALLIANCE=$(ALLIANCE)
	$(MAKE) battle-score     BATTLE=$(BATTLE) ALLIANCE=$(ALLIANCE) WEIGHT_LABEL=$(WEIGHT_LABEL)

# Auto-pipeline: every pending (theater, alliance) pair gets the
# Specs 2→3→4→5 chain. Invoke from HOST cron (scheduler container
# has no docker CLI).
#
# Steady-state cron (every 5 min to match theater_clustering):
#   */5 * * * * cd /opt/AegisCore && make battle-process-pending >> /var/log/aegiscore-battle-pipeline.log 2>&1
#
# Overrides: BATTLE_LIMIT=100 | BATTLE_MIN_MEMBERS=5
battle-process-pending:
	@bash scripts/battle-process-pending.sh

# Full backlog sweep with parallelism. One-shot; no limit cap.
# Run this on first bring-up or after a long gap.
# Overrides: BACKLOG_PARALLEL=4 | BATTLE_MIN_MEMBERS=5
battle-backlog:
	@bash scripts/battle-backlog-filler.sh

battle-refresh-priors:
	$(COMPOSE) exec -T php-fpm php artisan battle:refresh-priors $(BATTLE_PRIORS_ARGS)

battle-evaluate:
	@test -n "$(WEIGHT)" || (echo "WEIGHT=<weight_version_id> required" && exit 1)
	$(COMPOSE) exec -T php-fpm php artisan battle:evaluate-calibration --weight-version=$(WEIGHT)

battle-promote:
	@test -n "$(WEIGHT)" || (echo "WEIGHT=<weight_version_id> required" && exit 1)
	@test -n "$(ROLES)"  || (echo "ROLES=<csv> required (logi,tackle,bomber,…)" && exit 1)
	$(COMPOSE) exec -T php-fpm php artisan battle:promote-weight-version $(WEIGHT) --roles=$(ROLES)

battle-seed-truth:
	@test -n "$(USER)" || (echo "USER=<user_id> required" && exit 1)
	$(COMPOSE) exec -T php-fpm php artisan battle:seed-truth-attestations --user-id=$(USER)

# Spec 8 — role-tied auto-doctrine detection.
# Overrides: DOCTRINE_ARGS="--window-days=14 --strict-confidence=0.80 --dry-run"
battle-doctrines:
	$(COMPOSE) exec -T php-fpm php artisan battle:compute-auto-doctrines $(DOCTRINE_ARGS)

# Default weight label if caller doesn't set one
WEIGHT_LABEL ?= v1_calibrated_seed

# One-shot R2Z2 live stream (runs until Ctrl-C). For ad-hoc testing.
# The long-lived `killmail_stream` compose service handles production.
killmail-stream:
	$(COMPOSE) --profile tools run --rm --build killmail_backfill stream $(KILLMAIL_STREAM_ARGS)

# One-shot OpenSearch killmail index backfill. Indexes all enriched
# killmails that aren't in the index yet.
killmail-search:
	$(COMPOSE) --profile tools run --rm --build killmail_search $(KILLMAIL_SEARCH_ARGS)

# Counter-Intel Dossier — Commit 1: feature extraction.
# Args: CI_ARGS="--window-end=2026-04-18" to override
ci-features:
	$(COMPOSE) --profile tools run --rm --build counter_intel features $(CI_ARGS)

# Counter-Intel Dossier — Commit 2: MariaDB → Neo4j projection.
ci-projection:
	$(COMPOSE) --profile tools run --rm --build counter_intel projection $(CI_ARGS)

# Counter-Intel Dossier — Commit 3: GDS similarity + graph scores.
ci-similarity:
	$(COMPOSE) --profile tools run --rm --build counter_intel similarity $(CI_ARGS)

# Counter-Intel Dossier — Commit 4: anomaly compute (per viewer bloc).
# Args: VIEWER_BLOC=1 CI_ARGS="--window-end=2026-04-18"
ci-anomalies:
	$(COMPOSE) --profile tools run --rm --build counter_intel anomalies --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Dossier — Step 2: graph features (community + seed-anchored similarity) per viewer bloc.
# Args: VIEWER_BLOC=1 CI_ARGS="--window-end=2026-04-18"
ci-graph-features:
	$(COMPOSE) --profile tools run --rm --build counter_intel graph-features --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 1 — bloc-agnostic signal expansion (dormancy,
# corp tenure cadence, loss profile, battle-only score). Run after
# ci-features so rows already exist to UPDATE.
# Args: CI_ARGS="--window-end=2026-04-18"
ci-phase1-agnostic:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase1-agnostic $(CI_ARGS)

# Counter-Intel Phase 1 — bloc-relative signal expansion (asymmetric
# mutual presence, community hostile %). Run after ci-anomalies so
# the per-bloc rows are present to UPDATE.
# Args: VIEWER_BLOC=1 CI_ARGS="--window-end=2026-04-18"
ci-phase1-relative:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase1-relative --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 2 — hostile micro-network triangulation per
# viewer bloc. Run after ci-phase1-relative.
# Args: VIEWER_BLOC=1 CI_ARGS="--window-end=2026-04-20"
ci-phase2-triangulation:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase2-triangulation --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 2 — alliance community baseline (per-alliance
# median/p90 community_hostile_pct). Used by the dossier renderer
# to convert absolute thresholds into "outlier within own alliance".
# Run after ci-phase1-relative.
# Args: VIEWER_BLOC=1 CI_ARGS="--window-end=2026-04-20"
ci-phase2-baseline:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase2-baseline --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 2.5 — k-NN cohort feature extension (TZ centroid).
# Run after ci-features. See docs/adr/0008-ci-knn-cohort-extension.md.
# Args: CI_ARGS="--window-end=2026-04-19" or "--force"
ci-phase2-cohort-features:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase2-cohort-features $(CI_ARGS)

# Counter-Intel Phase 4 — log-derived operational analytics. Each
# pass is idempotent (UPSERT). Run after eve-log-ingest is producing
# events.
# Args: VIEWER_BLOC=1 CI_ARGS="--since-hours=168" or "--window-end=2026-04-26"
ci-phase4-timelines:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-timelines --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase4-fleet-participation:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-fleet-participation --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase4-intel-reliability:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-intel-reliability --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase4-session-correlation:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-session-correlation --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.3A — hostile-report clustering. Group raw
# intel_report events into operational clusters keyed by primary
# system + 5-min proximity. Run after Phase 4.2A entity resolution.
# Args: VIEWER_BLOC=1 CI_ARGS="--since-hours=8760"
ci-phase4-hostile-clusters:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-hostile-clusters --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.3B/C/E — fuse clusters + timelines into
# operational incidents, link to battle_theaters where overlap
# allows. Run after ci-phase4-hostile-clusters + ci-phase4-timelines.
# Args: VIEWER_BLOC=1 CI_ARGS="--since-hours=8760"
ci-phase4-incidents:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-incidents --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.3D — daily per-system operational activity
# rollup. Feeds map overlays + threat-corridor analytics.
# Args: VIEWER_BLOC=1 CI_ARGS="--since-hours=8760"
ci-phase4-system-activity:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-system-activity --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.4C/E/F — strategic operational analytics.
# Run after ci-phase4-system-activity. Threat surface depends on
# corridors so order is corridors → response-times → threat-surface.
ci-phase4-corridors:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-corridors --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase4-response-times:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-response-times --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase4-threat-surface:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase4-threat-surface --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.5 — force composition + doctrine + transitions.
# Run after ci-phase4-incidents. Compositions before transitions.
# Args: VIEWER_BLOC=1 CI_ARGS="--since-hours=8760"
ci-phase45-force-compositions:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase45-force-compositions --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase45-force-transitions:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase45-force-transitions --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.6 — coalition + doctrine behavior intel.
# Order: alliance-profiles → coalition-comparisons → doctrine-evolution
# (doctrine-evolution needs prior-window profiles to diff against).
# route-pressure + operator-fingerprints are independent.
# Args: VIEWER_BLOC=1 CI_ARGS="--window-days 30"
ci-phase46-alliance-profiles:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase46-alliance-profiles --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase46-coalition-comparisons:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase46-coalition-comparisons --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase46-doctrine-evolution:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase46-doctrine-evolution --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase46-route-pressure:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase46-route-pressure --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase46-operator-fingerprints:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase46-operator-fingerprints --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.7 — analyst workflow + intelligence production.
# Args: VIEWER_BLOC=1 CI_ARGS="--digest-date 2026-04-26 --window last_7d"
ci-phase47-daily-digest:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase47-daily-digest --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase47-strategic-alerts:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase47-strategic-alerts --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase47-incident-narratives:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase47-incident-narratives --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.8 — governance + trust + analyst controls.
# Args: VIEWER_BLOC=1 CI_ARGS="--window-days 30"
ci-phase48-alert-suppression:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase48-alert-suppression --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase48-trust-metrics:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase48-trust-metrics --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase48-enrich-digest-trust:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase48-enrich-digest-trust --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

ci-phase48-enrich-narrative-sources:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase48-enrich-narrative-sources --viewer-bloc-id $(VIEWER_BLOC) $(CI_ARGS)

# Counter-Intel Phase 4.9 — intelligence freshness re-classification.
# No --viewer-bloc-id arg required (default: all blocs).
ci-phase49-freshness:
	$(COMPOSE) --profile tools run --rm --build counter_intel phase49-freshness $(CI_ARGS)

# Phase 3 — cross-compile the Windows EVE Log Uploader (.NET 8
# Worker Service) using the dotnet/sdk:8.0 container. Produces a
# single-file self-contained .exe in
#   windows-uploader/publish/win-x64/AegisCore.EveLogUploader.exe
# No local dotnet install required.
windows-uploader:
	@bash windows-uploader/build.sh

# Replay eve_log_parse_errors through the current parser. Use after
# parser changes that should newly classify previously-unknown lines.
# Args: ELOG_RETRY_ARGS="--limit=5000 --dry-run"
eve-log-retry-parse-errors:
	$(COMPOSE) exec -T php-fpm php artisan eve-log:retry-parse-errors $(ELOG_RETRY_ARGS)

# Re-parse existing event rows through the current parser so newly-
# added extraction (showinfo, dscan, reported_count, partial
# timestamps) backfills onto rows that predate the parser change.
# Args: ELOG_REPARSE_ARGS="--limit=50000 --types=intel_report"
eve-log-reparse-events:
	$(COMPOSE) exec -T php-fpm php artisan eve-log:reparse-events $(ELOG_REPARSE_ARGS)

# Backfill dscan.info URLs from existing events into the snapshot
# registry. One-shot — new ingest writes to the registry directly.
eve-log-dscan-backfill:
	$(COMPOSE) exec -T php-fpm php artisan eve-log:dscan-backfill

# Fetch + parse pending dscan snapshots, rate-limited.
# Args: ELOG_DSCAN_ARGS="--limit=50 --rate-per-min=6"
eve-log-fetch-dscan:
	$(COMPOSE) exec -T php-fpm php artisan eve-log:fetch-dscan $(ELOG_DSCAN_ARGS)

# Same as windows-uploader — builds the .exe and bundles the
# portable .zip with member README + install/uninstall scripts.
# Output:
#   windows-uploader/publish/win-x64/AegisCore.EveLogUploader.exe
#   windows-uploader/publish/AegisCore.EveLogUploader-portable-YYYYMMDD.zip
windows-uploader-portable: windows-uploader

# Bloc Intelligence — alliance-pair behavior extractor (viewer-agnostic).
# Args: BI_ARGS="--window-end=2026-04-18"
bloc-intel-extract:
	$(COMPOSE) --profile tools run --rm --build bloc_intel extract $(BI_ARGS)

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

# One-time (or post-DB-init) bootstrap for the phpunit test schema.
# Creates aegiscore_test + grants the aegiscore user access. See
# scripts/setup-test-db.sh for the full rationale — in short, the
# test suite runs against a physically separate MariaDB schema so
# migrate:fresh can never target production, closing the failure
# mode that caused the 2026-04-16 wipe.
test-db-setup:
	./scripts/setup-test-db.sh

# Idempotent seed of the classification reference data: coalition
# blocs, relationship types, and the seeded corp/alliance →
# bloc labels. Ships with `make update` too; this target is the
# manual lever for a post-restore / post-data-loss environment
# where you need the reference data back without running the full
# update cycle. Each seeder uses updateOrCreate on its unique key,
# so re-running is safe.
seed-classification:
	$(COMPOSE) exec php-fpm php artisan db:seed --class=CoalitionBlocSeeder --force
	$(COMPOSE) exec php-fpm php artisan db:seed --class=CoalitionRelationshipTypeSeeder --force
	$(COMPOSE) exec php-fpm php artisan db:seed --class=CoalitionEntityLabelSeeder --force

lint:
	$(COMPOSE) exec php-fpm ./vendor/bin/pint --test

clean-logs:
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/access.log 2>/dev/null || true
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/error.log  2>/dev/null || true
