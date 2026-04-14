"""outbox_relay — claim → project → ack consumer for the MariaDB outbox.

Reads `outbox` rows that the Laravel + Python writers have produced
(per docs/CONTRACTS.md § Plane boundary), routes each to a typed
projector, writes the projection to the appropriate derived store,
and marks the row processed.

Phase-1 routing surface (this package):

  - `market.history_snapshot_loaded`   → InfluxDB measurement `market_history`
  - `market.orders_snapshot_ingested`  → InfluxDB measurement `market_orderbook`

Future event types add a projector module under `projectors/` and
register it in the dispatch map; the relay framework itself is
projector-agnostic.

See ADR-0003 § InfluxDB and ADR-0004 § Live polling for the
"derived store, Python writes, no canonical ownership" placement.
"""
