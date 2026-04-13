# Infra Notes

## Project layout on the host

```
/opt/aegiscore/                  ← project root (git clone)
├── infra/docker-compose.yml
├── docker/                      ← container state (gitignored)
│   ├── mariadb/{data,config,logs}
│   ├── opensearch/{data,logs}
│   ├── influxdb2/{data,config}
│   ├── neo4j/{data,logs,import,plugins}
│   └── nginx/logs
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

## Troubleshooting
- **OpenSearch won't start, "config not found":** don't bind-mount
  `/usr/share/opensearch/config` unless you pre-seed it with the image's files.
  We intentionally do not mount that path.
- **Dashboards login loops:** confirm `OPENSEARCH_USERNAME` +
  `OPENSEARCH_PASSWORD` env vars are set on the dashboards service (they are,
  in the shipped compose file).
- **Neo4j OOM on small host:** lower `NEO4J_HEAP_*` and `NEO4J_PAGECACHE` in
  `.env`. Dev defaults target a laptop; prod defaults are in the comments.
- **`make bootstrap` fails with permission denied:** the target uses `sudo` on
  purpose because `/opt/aegiscore` is typically root-owned.
