# syntax=docker/dockerfile:1.7
#
# bloc_intel — Python 3.12 slim image for the Bloc Intelligence pipeline.
# Single subcommand so far (extract); community/parallel-ops/avoidance
# passes + Neo4j projection arrive in later commits.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-bloc-intel.txt ./
RUN pip install -r requirements-bloc-intel.txt

COPY bloc_intel ./bloc_intel

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

ENTRYPOINT ["python", "-m", "bloc_intel"]
