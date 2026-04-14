<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| market_orders — raw order-book observations
|--------------------------------------------------------------------------
|
| Canonical store for live ESI order-book snapshots and any future
| bulk order-book dumps (ADR-0003, ADR-0004). One row per order
| observation. Kept separate from `market_history` because the shapes
| are genuinely different — orders have `order_id`, buy/sell split,
| per-order pricing; history is daily aggregates with none of those.
|
| Uniqueness contract is `(source, location_id, order_id, observed_at)`:
|
|   - `source` and `location_id` prefix the key so multi-source
|     provenance stays unambiguous. An `order_id` collision between a
|     live ESI snapshot and an EVE Ref dump (unlikely but not
|     structurally impossible) can't shadow-overwrite the other.
|   - `observed_at` is in the key because the same order is expected to
|     re-appear on every poll tick until it's filled/cancelled. The
|     "latest seen state of order X" query becomes a simple
|     `ORDER BY observed_at DESC LIMIT 1`.
|
| MariaDB requires the partition column to be part of every unique key
| including the PK — so `observed_at` is the first column of the PK,
| which also makes snapshot-batched inserts contiguous within a
| partition.
|
| `location_id` is BIGINT UNSIGNED because Upwell structure IDs are
| 64-bit (CCP's structure-ID allocation crossed the INT max long ago).
| NPC station IDs fit in 32 bits but we use one column for both.
|
| `observed_at` is TIMESTAMP(6) — microsecond precision. The poller
| batches many orders into a single snapshot with the same timestamp;
| the microseconds aren't about per-row resolution, they're about not
| losing information when CCP's `Last-Modified` header carries
| sub-second data and we occasionally want to align observations with
| ESI cache headers for debugging.
|
| `observation_kind` classifies ingestion provenance:
|   - `snapshot`          — point-in-time full book (live or dump).
|   - `incremental_poll`  — not currently used (ESI gives full pages,
|                           not deltas) but reserved for a future
|                           source that exposes deltas.
|   - `historical_dump`   — imported from a third-party order-book
|                           snapshot (not part of this ADR's rollout,
|                           but the enum reserves the value).
|
| See docs/adr/0004-market-data-ingest.md.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_orders', function () {
            // Raw SQL for the partition clause + composite PK — same
            // reasoning as market_history.
            DB::statement(<<<'SQL'
                CREATE TABLE market_orders (
                    observed_at         TIMESTAMP(6)    NOT NULL,
                    source              VARCHAR(64)     NOT NULL,
                    location_id         BIGINT UNSIGNED NOT NULL,
                    order_id            BIGINT UNSIGNED NOT NULL,
                    region_id           INT UNSIGNED    NOT NULL,
                    type_id             INT UNSIGNED    NOT NULL,
                    is_buy              TINYINT(1)      NOT NULL,
                    price               DECIMAL(20, 2)  NOT NULL,
                    volume_remain       INT UNSIGNED    NOT NULL,
                    volume_total        INT UNSIGNED    NOT NULL,
                    min_volume          INT UNSIGNED    NOT NULL,
                    `range`             VARCHAR(16)     NOT NULL,
                    duration            INT UNSIGNED    NOT NULL,
                    issued_at           TIMESTAMP       NOT NULL,
                    observation_kind    ENUM('snapshot','incremental_poll','historical_dump') NOT NULL,
                    http_last_modified  TIMESTAMP       NULL,
                    created_at          TIMESTAMP       NULL,
                    updated_at          TIMESTAMP       NULL,
                    PRIMARY KEY (observed_at, source, location_id, order_id),
                    KEY idx_market_orders_type_time      (type_id, observed_at),
                    KEY idx_market_orders_location_type  (location_id, type_id, observed_at),
                    KEY idx_market_orders_region_type    (region_id, type_id, observed_at)
                )
                ENGINE=InnoDB
                DEFAULT CHARSET=utf8mb4
                COLLATE=utf8mb4_unicode_ci
                PARTITION BY RANGE COLUMNS (observed_at) (
                    PARTITION p2025_01 VALUES LESS THAN ('2025-02-01'),
                    PARTITION p2025_02 VALUES LESS THAN ('2025-03-01'),
                    PARTITION p2025_03 VALUES LESS THAN ('2025-04-01'),
                    PARTITION p2025_04 VALUES LESS THAN ('2025-05-01'),
                    PARTITION p2025_05 VALUES LESS THAN ('2025-06-01'),
                    PARTITION p2025_06 VALUES LESS THAN ('2025-07-01'),
                    PARTITION p2025_07 VALUES LESS THAN ('2025-08-01'),
                    PARTITION p2025_08 VALUES LESS THAN ('2025-09-01'),
                    PARTITION p2025_09 VALUES LESS THAN ('2025-10-01'),
                    PARTITION p2025_10 VALUES LESS THAN ('2025-11-01'),
                    PARTITION p2025_11 VALUES LESS THAN ('2025-12-01'),
                    PARTITION p2025_12 VALUES LESS THAN ('2026-01-01'),
                    PARTITION p2026_01 VALUES LESS THAN ('2026-02-01'),
                    PARTITION p2026_02 VALUES LESS THAN ('2026-03-01'),
                    PARTITION p2026_03 VALUES LESS THAN ('2026-04-01'),
                    PARTITION p2026_04 VALUES LESS THAN ('2026-05-01'),
                    PARTITION p2026_05 VALUES LESS THAN ('2026-06-01'),
                    PARTITION p2026_06 VALUES LESS THAN ('2026-07-01'),
                    PARTITION p2026_07 VALUES LESS THAN ('2026-08-01'),
                    PARTITION p2026_08 VALUES LESS THAN ('2026-09-01'),
                    PARTITION p2026_09 VALUES LESS THAN ('2026-10-01'),
                    PARTITION p2026_10 VALUES LESS THAN ('2026-11-01'),
                    PARTITION p2026_11 VALUES LESS THAN ('2026-12-01'),
                    PARTITION p2026_12 VALUES LESS THAN ('2027-01-01'),
                    PARTITION p_future VALUES LESS THAN MAXVALUE
                )
            SQL);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_orders');
    }
};
