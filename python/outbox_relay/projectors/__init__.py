"""Outbox event projectors.

Each projector module exposes a single `project(...)` callable that:

  - Takes (read_conn, influx_client, payload, log) — never the
    outbox connection. The relay framework owns claim/ack; the
    projector just transforms.
  - Returns the number of derived points written, for logging.
  - Raises on failure. The relay catches the exception and bumps
    `attempts` + `last_error` on the outbox row; do not swallow
    errors inside the projector.

Projectors are discovered + dispatched by event_type via the
`PROJECTOR_REGISTRY` in `dispatch.py`. Adding a new event type:

  1. Write `projectors/<event_type_short>.py` with a `project()` fn.
  2. Register it in `projectors.dispatch.PROJECTOR_REGISTRY`.

That's all — the relay framework is projector-agnostic.
"""
