"""killmail_ingest — two-phase killmail data acquisition.

Phase 1: Historical backfill from EVE Ref daily tar.bz2 archives.
Phase 2: Real-time ingestion from R2Z2 (zKillboard sequence stream).

Both phases converge on the same persist.ingest_killmail() entry point
which writes killmails + killmail_attackers + killmail_items and emits
a killmail.ingested outbox event per kill.

Usage:
    python -m killmail_ingest backfill          # one-shot EVE Ref backfill
    python -m killmail_ingest backfill --interval 21600  # loop mode (6h)
    python -m killmail_ingest stream            # R2Z2 live stream
"""
