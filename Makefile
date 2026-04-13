AEGISCORE_ROOT ?= /opt/aegiscore
COMPOSE := docker compose -f infra/docker-compose.yml --env-file .env

-include .env
export

.PHONY: help up down restart ps logs pull bootstrap clean-logs

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
	@echo "  make build        build locally-built images (php-fpm)"
	@echo "  make php-shell    open a shell in the php-fpm container"
	@echo "  make redis-cli    open a redis-cli session (auth'd)"
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
	$(COMPOSE) pull

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
	@echo "bootstrap complete at $(AEGISCORE_ROOT)"

.PHONY: build php-shell redis-cli
build:
	$(COMPOSE) build

php-shell:
	$(COMPOSE) exec php-fpm sh

redis-cli:
	$(COMPOSE) exec redis sh -c 'redis-cli -a "$$REDIS_PASSWORD"'

clean-logs:
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/access.log 2>/dev/null || true
	sudo truncate -s 0 $(AEGISCORE_ROOT)/docker/nginx/logs/error.log  2>/dev/null || true
