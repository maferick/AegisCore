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
	@echo "  make sde-check    run the SDE version-drift check now (inline)"
	@echo "  make sde-import   download CCP's SDE and load all ref_* tables (one-shot)"
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
update:
	git pull --ff-only
	$(COMPOSE) up -d
	$(COMPOSE) exec php-fpm composer install --optimize-autoloader --no-interaction
	$(COMPOSE) exec php-fpm php artisan migrate --force
	@echo ""
	@echo "Stack updated. If Horizon is running and you touched jobs/config:"
	@echo "    make artisan CMD=\"config:clear\""
	@echo "    make artisan CMD=\"horizon:terminate\"   # supervisord/systemd will respawn it"

bootstrap:
	sudo mkdir -p \
		$(AEGISCORE_ROOT)/docker/mariadb/data \
		$(AEGISCORE_ROOT)/docker/mariadb/config \
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

.PHONY: build php-shell redis-cli composer artisan laravel-install laravel-migrate horizon-install horizon-publish laravel-key filament-user test lint sde-check sde-import
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

test:
	$(COMPOSE) exec php-fpm php artisan test

lint:
	$(COMPOSE) exec php-fpm ./vendor/bin/pint --test

clean-logs:
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/access.log 2>/dev/null || true
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/error.log  2>/dev/null || true
