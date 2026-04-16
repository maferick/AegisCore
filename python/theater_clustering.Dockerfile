# syntax=docker/dockerfile:1.7
#
# theater_clustering — Python 3.12 slim image.
#
# Two subcommand modes:
#   run   — single clustering pass, then exit
#   loop  — scheduler: one pass every THEATER_SCHEDULER_INTERVAL_SECONDS
#
# Reads killmails + killmail_attackers, writes battle_theaters + child
# tables. Pure pymysql, no scientific dependencies.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-theater.txt ./
RUN pip install -r requirements-theater.txt

COPY theater_clustering ./theater_clustering

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "theater_clustering"]
