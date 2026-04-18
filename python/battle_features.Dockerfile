# syntax=docker/dockerfile:1.7
#
# battle_features — Python 3.12 slim image.
#
# Single subcommand:
#   run --battle-id X --alliance-id Y
#       [--partition-algo-version N]
#       [--edge-profile-version N --algo-profile-version N]
#       [--bucket-seconds N]
#       [--dry-run]
#
# Reads Spec 2 graph metrics + Spec 3 sub-fleet membership and writes
# battle_character_role_features (Spec 4 output). Shares MariaDB
# GET_LOCK keys with battle_graph and battle_partition.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-battle-features.txt ./
RUN pip install -r requirements-battle-features.txt

COPY battle_features ./battle_features

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "battle_features"]
