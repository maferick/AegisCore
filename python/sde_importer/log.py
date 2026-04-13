"""Structured-ish logging setup. No third-party structlog — keep deps small.

Output goes to stderr as one line per event, key=value pairs after the message.
Good enough for `docker compose logs sde_importer` and grep workflows; can
upgrade to JSON logging later if we ship these to OpenSearch.
"""

from __future__ import annotations

import logging
import sys
from typing import Any


class _KvFormatter(logging.Formatter):
    """Append `key=value` pairs from record.extras after the message."""

    _reserved = {
        "name", "msg", "args", "levelname", "levelno", "pathname", "filename",
        "module", "exc_info", "exc_text", "stack_info", "lineno", "funcName",
        "created", "msecs", "relativeCreated", "thread", "threadName",
        "processName", "process", "message", "asctime", "taskName",
    }

    def format(self, record: logging.LogRecord) -> str:
        base = super().format(record)
        extras = {
            k: v for k, v in record.__dict__.items()
            if k not in self._reserved and not k.startswith("_")
        }
        if extras:
            suffix = " ".join(f"{k}={_fmt(v)}" for k, v in extras.items())
            return f"{base} {suffix}"
        return base


def _fmt(v: Any) -> str:
    s = str(v)
    return f'"{s}"' if " " in s else s


def setup(level: str = "INFO") -> None:
    root = logging.getLogger()
    root.setLevel(level)
    # Clear any handlers set by imports (e.g. urllib3) so we own stderr.
    for h in list(root.handlers):
        root.removeHandler(h)

    handler = logging.StreamHandler(sys.stderr)
    handler.setFormatter(_KvFormatter("%(asctime)s %(levelname)-5s %(name)s: %(message)s"))
    root.addHandler(handler)


def get(name: str) -> logging.Logger:
    return logging.getLogger(name)
