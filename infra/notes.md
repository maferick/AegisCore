# Infra Notes

## Project layout on the host

```
/opt/aegiscore/                  ← project root (git clone)
├── infra/docker-compose.yml
├── docker/                      ← container state (gitignored)
│   ├── mariadb/{data,config,logs}
│   ├── redis/data
│   ├── opensearch/{data,logs}
│   ├── influxdb2/{data,config}
│   ├── neo4j/{data,logs,import,plugins}
│   └── nginx/logs
├── app/                         ← Laravel control plane source
│   └── public/index.php         ← front controller (served by nginx + php-fpm)
├── php/
│   └── conf.d/aegiscore.ini     ← custom php.ini overrides, read-only mount
├── infra/
│   ├── docker-compose.yml
│   └── php/Dockerfile           ← custom php-fpm image (extensions + composer)
├── nginx/
│   ├── conf.d/aegiscore.conf    ← mounted read-only into nginx container
│   └── certs/                   ← TLS material (gitignored)
└── docs/
```

All bind-mount paths in `infra/docker-compose.yml` resolve via
`${AEGISCORE_ROOT:-/opt/aegiscore}`, so you can point the stack at a different
root on dev laptops by setting `AEGISCORE_ROOT` in `.env`.

## First-time setup

Run `make bootstrap` to create the `docker/*` dirs with correct ownership:

| Service     | Path                                   | UID  |
|-------------|----------------------------------------|------|
| MariaDB     | `$AEGISCORE_ROOT/docker/mariadb`       | 999  |
| Redis       | `$AEGISCORE_ROOT/docker/redis`         | 999  |
| OpenSearch  | `$AEGISCORE_ROOT/docker/opensearch`    | 1000 |
| InfluxDB    | `$AEGISCORE_ROOT/docker/influxdb2`     | 1000 |
| Neo4j       | `$AEGISCORE_ROOT/docker/neo4j`         | 7474 |
| Nginx logs  | `$AEGISCORE_ROOT/docker/nginx/logs`    | root |

## Image pinning
- All images are pinned. Bump intentionally, one image per commit.
- Never commit `.env`.

## Portainer
- Git auto-update target: `infra/docker-compose.yml`.
- Set env vars in the Portainer stack config (or point to `.env`).

## Port exposure
- `80` / `443` — nginx, public.
- `3306` (MariaDB) and `7687` (Neo4j bolt) — bound to `127.0.0.1` only.
- `5601` / `8086` / `9200` / `7474` — exposed on all interfaces for dev. In
  prod, remove these from the compose file and route through nginx.

## Data ownership
MariaDB is canonical. Neo4j / OpenSearch / InfluxDB are derived stores — they
can be rebuilt from MariaDB + external sources. Don't add business logic that
only lives in a derived store.

## PHP control plane
- Image: built locally from `infra/php/Dockerfile` (tag
  `aegiscore/php-fpm:0.1.0`). Base is `php:8.4-fpm-alpine` + Laravel-required
  extensions (`pdo_mysql`, `redis`, `intl`, `bcmath`, `gd`, `mbstring`,
  `opcache`, `pcntl`, `sockets`, `zip`) + Composer.
- Bump the image tag in `infra/docker-compose.yml` whenever the Dockerfile
  changes; rebuild with `make build`.
- App source: `$AEGISCORE_ROOT/app/`, mounted **read-write** into php-fpm and
  **read-only** into nginx. PHP should not write to `app/` at runtime — any
  writable state goes under `docker/php/` (add a volume when needed).
- PHP overrides live in `$AEGISCORE_ROOT/php/conf.d/*.ini` and are mounted at
  `/usr/local/etc/php/conf.d/aegiscore/` inside the container.
- Backend credentials reach PHP via env vars in the compose file — service
  names (`mariadb`, `redis`, `opensearch`, `influxdb2`, `neo4j`) resolve
  inside the `aegiscore` network.

## Redis
- Image: `redis:7-alpine`, AOF persistence (`appendfsync everysec`).
- Password-protected via `REDIS_PASSWORD` in `.env`. Bound to `127.0.0.1:6379`.
- Memory cap: `REDIS_MAXMEMORY` (default `512mb`) with `allkeys-lru`.
- Used for Laravel cache, sessions, queues, and Horizon. **Not a system of
  record** — anything important lands in MariaDB.
- Password should avoid `$`, `` ` ``, `"`, `\` to stay shell-safe in the
  command + healthcheck.

## Troubleshooting
- **OpenSearch won't start, "config not found":** don't bind-mount
  `/usr/share/opensearch/config` unless you pre-seed it with the image's files.
  We intentionally do not mount that path.
- **Dashboards login loops:** confirm `OPENSEARCH_USERNAME` +
  `OPENSEARCH_PASSWORD` env vars are set on the dashboards service (they are,
  in the shipped compose file).
- **Neo4j OOM on small host:** lower `NEO4J_HEAP_*` and `NEO4J_PAGECACHE` in
  `.env`. Dev defaults target a laptop; prod defaults are in the comments.
- **Nginx returns 404 for `/`:** make sure `app/public/index.php` exists and
  `$AEGISCORE_ROOT/app` is mounted — both nginx and php-fpm need to see it.
- **PHP changes don't show up:** OPcache revalidates every 2 seconds in the
  shipped `aegiscore.ini`; wait a moment or `make restart` php-fpm.
- **Redis healthcheck flapping:** confirm `REDIS_PASSWORD` in `.env` doesn't
  contain shell metacharacters (`$`, `` ` ``, `"`, `\`). Rotate if needed.
- **`make bootstrap` fails with permission denied:** the target uses `sudo` on
  purpose because `/opt/aegiscore` is typically root-owned.
- **`make up` rebuilds php-fpm every time:** shouldn't — compose caches by
  image tag. Bump `aegiscore/php-fpm:<version>` in compose when the
  Dockerfile changes and run `make build` explicitly.
