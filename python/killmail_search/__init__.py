"""killmail_search — OpenSearch killmail index projection.

Indexes enriched killmails from MariaDB into OpenSearch for fast
full-text search, faceted filtering, and sub-second aggregations.

Two modes:
    python -m killmail_search backfill     # bulk index all enriched killmails
    python -m killmail_search backfill --interval 300  # loop mode (5 min)

The index is a derived store (ADR-0003) — rebuildable from MariaDB at
any time by dropping the index and re-running the backfill.
"""
