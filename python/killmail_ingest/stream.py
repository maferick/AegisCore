"""R2Z2 live killmail stream runner.

Sequence-based cursor: fetches killmails one-at-a-time by incrementing
sequence ID. Persists each killmail + emits outbox event, then advances
the cursor in killmail_ingest_state.

Poll contract: minimum 6 seconds between requests after a 404 (caught
up to head). No sleep needed on successful fetches — R2Z2 allows 20
req/s/IP.
"""

from __future__ import annotations

import logging
import time

from killmail_ingest.config import Config
from killmail_ingest.db import connect
from killmail_ingest.log import get
from killmail_ingest.outbox import emit_killmail_ingested
from killmail_ingest.parse import parse_esi_killmail
from killmail_ingest.persist import ingest_killmail
from killmail_ingest.r2z2 import (
    R2z2Transient,
    extract_esi_killmail,
    fetch_killmail,
    fetch_latest_sequence,
)
from killmail_ingest.state import get_state, set_state


log = get(__name__)


def run_stream(cfg: Config) -> int:
    """Run the R2Z2 live stream. Blocks indefinitely until interrupted."""
    # Quiet httpx's per-request INFO logs — they're mostly 404s when
    # caught up and just noise. Warnings and errors still show.
    logging.getLogger("httpx").setLevel(logging.WARNING)

    with connect(cfg) as conn:
        # Resume from last known sequence, or start from current head.
        cursor_str = get_state(conn, "r2z2", "last_sequence")

        if cursor_str is not None:
            cursor = int(cursor_str)
            log.info("resuming from saved cursor", sequence=cursor)
        else:
            try:
                cursor = fetch_latest_sequence(cfg)
                log.info("starting from current head", sequence=cursor)
            except R2z2Transient as exc:
                log.error("failed to fetch initial sequence", error=str(exc))
                return 1

        ingested = 0
        duplicates = 0
        errors = 0

        try:
            while True:
                try:
                    data = fetch_killmail(cfg, cursor)
                except R2z2Transient as exc:
                    errors += 1
                    log.warning("transient error, retrying after sleep", sequence=cursor, error=str(exc))
                    time.sleep(cfg.r2z2_poll_interval_seconds)
                    continue

                if data is None:
                    # Caught up — wait before polling again.
                    time.sleep(cfg.r2z2_poll_interval_seconds)
                    cursor += 1
                    continue

                try:
                    esi_payload, killmail_hash = extract_esi_killmail(data)

                    if "killmail_id" not in esi_payload:
                        log.warning("no killmail_id in R2Z2 response", sequence=cursor)
                        cursor += 1
                        continue

                    km = parse_esi_killmail(esi_payload, killmail_hash=killmail_hash)

                    was_new = ingest_killmail(conn, km)
                    emit_killmail_ingested(conn, km=km)

                    set_state(conn, "r2z2", "last_sequence", str(cursor))

                    if cfg.dry_run:
                        conn.rollback()
                    else:
                        conn.commit()

                    if was_new:
                        ingested += 1
                    else:
                        duplicates += 1

                    if ingested % 100 == 0 and ingested > 0:
                        log.info(
                            "stream progress",
                            ingested=ingested,
                            duplicates=duplicates,
                            errors=errors,
                            sequence=cursor,
                        )

                except Exception as exc:
                    errors += 1
                    log.warning(
                        "failed to process killmail",
                        sequence=cursor,
                        error=str(exc),
                    )
                    try:
                        conn.rollback()
                    except Exception:
                        pass

                cursor += 1

        except KeyboardInterrupt:
            log.info(
                "stream stopped by user",
                ingested=ingested,
                duplicates=duplicates,
                errors=errors,
                last_sequence=cursor,
            )

    return 0
