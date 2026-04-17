# syntax=docker/dockerfile:1.7
#
# battle_graph — Python 3.12 slim image.
#
# Single subcommand:
#   run --battle-id X --alliance-id Y [--edge-profile / --algo-profile]
#
# Reads battle_theaters + killmails + killmail_attackers from MariaDB,
# projects a pilot graph into Neo4j via GDS, runs algorithms, writes
# raw metrics to battle_character_graph_metrics. See README.md in the
# module for invocation details.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-battle-graph.txt ./
RUN pip install -r requirements-battle-graph.txt

COPY battle_graph ./battle_graph

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "battle_graph"]
