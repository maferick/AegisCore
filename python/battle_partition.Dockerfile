# syntax=docker/dockerfile:1.7
#
# battle_partition — Python 3.12 slim image.
#
# Single subcommand:
#   run --battle-id X --alliance-id Y
#       [--partition-algo-version N | --partition-algo LABEL]
#       [--edge-profile-version N --algo-profile-version N]
#       [--dry-run]
#
# Reads battle_character_graph_metrics (Spec 2 output) and writes
# battle_sub_fleets + battle_character_sub_fleet_membership (Spec 3
# output). Shares a MariaDB GET_LOCK key with battle_graph so reads
# and writes never race.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-battle-partition.txt ./
RUN pip install -r requirements-battle-partition.txt

COPY battle_partition ./battle_partition

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "battle_partition"]
