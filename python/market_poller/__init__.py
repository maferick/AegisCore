"""market_poller — Python execution-plane live market-data poller.

One-shot worker (`python -m market_poller`). Walks enabled rows in
`market_watched_locations`, pulls their current order book from ESI,
and bulk-inserts into `market_orders`. Designed to be invoked from a
cron / scheduler on a 5-minute cadence — each invocation is a single
pass; the cadence itself lives outside this package.

Phase 1 handles NPC stations only (region endpoint + location filter,
no auth). Admin-owned structures and donor-owned structures layer on
top in later steps of ADR-0004's rollout sequence without changing
this package's shape — the runner branches on `location_type`.

See docs/adr/0004-market-data-ingest.md § Live polling.
"""
