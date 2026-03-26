"""JSON-structured log emitter for AegisCore job execution.

Outputs structured logs to stdout in JSON format, compatible with
log aggregation systems (ELK, Datadog, CloudWatch, etc.).
"""

import json
import logging
import sys
from datetime import datetime, timezone
from typing import Any

from supplycore.contracts import LogEnvelope


class JsonLogFormatter(logging.Formatter):
    """Formats log records as single-line JSON objects."""

    def format(self, record: logging.LogRecord) -> str:
        log_data: dict[str, Any] = {
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "level": record.levelname,
            "logger": record.name,
            "message": record.getMessage(),
        }
        if hasattr(record, "envelope"):
            log_data["envelope"] = record.envelope  # type: ignore[attr-defined]
        if record.exc_info and record.exc_info[1]:
            log_data["exception"] = str(record.exc_info[1])
        return json.dumps(log_data, default=str, separators=(",", ":"))


def setup_structured_logging(level: int = logging.INFO) -> logging.Logger:
    """Configure the root supplycore logger with JSON output to stdout."""
    logger = logging.getLogger("supplycore")
    if not logger.handlers:
        handler = logging.StreamHandler(sys.stdout)
        handler.setFormatter(JsonLogFormatter())
        logger.addHandler(handler)
    logger.setLevel(level)
    return logger


def emit_job_log(envelope: LogEnvelope) -> None:
    """Emit a structured job execution log envelope."""
    logger = logging.getLogger("supplycore.jobs")
    record = logger.makeRecord(
        name="supplycore.jobs",
        level=logging.INFO if envelope.outcome == "success" else logging.ERROR,
        fn="",
        lno=0,
        msg=f"Job {envelope.job_key} {envelope.outcome}",
        args=(),
        exc_info=None,
    )
    record.envelope = envelope.model_dump(mode="json")  # type: ignore[attr-defined]
    logger.handle(record)
