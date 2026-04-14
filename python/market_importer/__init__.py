"""market_importer — EVE Ref daily market-history dump importer.

One-shot worker (`python -m market_importer`). Reconciles local
`market_history` rows against EVE Ref's published per-day totals,
downloads the bz2-compressed CSV for every day that's missing or
partial, and bulk-upserts it into MariaDB.

Ported from EVE Ref's Java/Flyway/PostgreSQL `import-market-history`
command (https://docs.everef.net/commands/import-market-history.html)
to MariaDB + Python — their importer doesn't support MariaDB yet and
we already have a Python execution plane.

See docs/adr/0004-market-data-ingest.md § Historical backfill.
"""
