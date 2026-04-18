# syntax=docker/dockerfile:1.7
#
# battle_role_scoring — Spec 5 v0 scoring worker.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-battle-role-scoring.txt ./
RUN pip install -r requirements-battle-role-scoring.txt

COPY battle_role_scoring ./battle_role_scoring

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "battle_role_scoring"]
