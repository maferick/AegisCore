# syntax=docker/dockerfile:1.7
#
# graph_universe_sync — Python 3.12 slim image.
#
# One-shot container (`make neo4j-sync-universe`) that reads ref_*
# tables from MariaDB and projects them into Neo4j. Sibling to the
# sde_importer image; deliberately separate so the Neo4j wheel doesn't
# bloat the SDE importer (which doesn't talk to Neo4j).

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

# Install requirements first so the layer caches independently of code
# changes. neo4j wheel is ~5MB; total install ~25MB.
COPY requirements-graph.txt ./
RUN pip install -r requirements-graph.txt

# Then the package itself.
COPY graph_universe_sync ./graph_universe_sync

# Unprivileged runtime user. Compose service overrides `user:` to match
# the host UID when needed.
RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "graph_universe_sync"]
