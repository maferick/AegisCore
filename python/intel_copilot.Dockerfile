# syntax=docker/dockerfile:1.7
#
# intel_copilot — natural-language → QueryPlan broker (ADR-0007).
# Runs the stdlib HTTP server so Laravel can proxy NL questions over
# an internal-network POST.

FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

WORKDIR /app

COPY requirements-intel-copilot.txt ./
RUN pip install -r requirements-intel-copilot.txt

COPY intel_copilot ./intel_copilot

RUN useradd --create-home --uid 1000 app \
 && chown -R app:app /app
USER app

EXPOSE 8000

ENTRYPOINT ["python", "-m", "intel_copilot.server"]
