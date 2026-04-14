<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| market_history — per-day, per-region, per-type market aggregates
|--------------------------------------------------------------------------
|
| Canonical store for ESI market-history data (ADR-0003, ADR-0004). One
| row per (trade_date, region_id, type_id) — matches the shape of the
| underlying ESI endpoint /markets/{region_id}/history/?type_id={...}
| and of EVE Ref's published dumps at data.everef.net/market-history/.
|
| `trade_date` rather than `date` because `date` is a reserved-word
| foot-gun in MariaDB and reads ambiguously in joins.
|
| Partition strategy is load-bearing, not optimisation garnish:
| retention is "drop an old partition" not "DELETE scan", and query
| pruning keeps reads bounded as the table grows. MariaDB requires the
| partition column to be part of every unique key, including the PK —
| so `trade_date` is the first column of the PK. Partitions are
| pre-created monthly from 2025-01 forward; new months are added by
| the retention-job PR (see ADR-0004 § Follow-ups).
|
| `observation_kind` classifies ingestion provenance:
|   - `historical_dump`   — imported from an EVE Ref daily CSV.
|   - `incremental_poll`  — pulled directly from ESI between dump runs.
|
| `source` carries the human-readable provenance string the importer /
| poller sets (e.g. `everef_market_history`, `esi_region_history`). The
| ENUM exists to classify retention/aggregation rules downstream without
| string-matching the free-form column.
|
| See docs/adr/0004-market-data-ingest.md.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // Raw DDL — not wrapped in `Schema::create()` because the
        // Blueprint builder doesn't model partitions, composite PKs,
        // or ENUM literals the way this schema needs. Wrapping it was
        // the first attempt; Laravel then ran an empty `create table
        // market_history ()` from the Blueprint BEFORE the `DB::statement`
        // inside the closure, blowing up on the empty column list.
        // Dropping the Blueprint wrapper is the fix. Partition clause +
        // composite PK land in one CREATE TABLE statement below, which
        // is where MariaDB wants them (ALTER TABLE ... PARTITION BY
        // after-the-fact requires the table to be empty, which is fine
        // now but not on re-runs in branched envs).
        DB::statement(<<<'SQL'
            CREATE TABLE market_history (
                trade_date          DATE            NOT NULL,
                region_id           INT UNSIGNED    NOT NULL,
                type_id             INT UNSIGNED    NOT NULL,
                average             DECIMAL(20, 2)  NOT NULL,
                highest             DECIMAL(20, 2)  NOT NULL,
                lowest              DECIMAL(20, 2)  NOT NULL,
                volume              BIGINT UNSIGNED NOT NULL,
                order_count         INT UNSIGNED    NOT NULL,
                http_last_modified  TIMESTAMP       NULL,
                source              VARCHAR(64)     NOT NULL,
                observation_kind    ENUM('historical_dump','incremental_poll') NOT NULL,
                created_at          TIMESTAMP       NULL,
                updated_at          TIMESTAMP       NULL,
                PRIMARY KEY (trade_date, region_id, type_id),
                KEY idx_market_history_region_type (region_id, type_id),
                KEY idx_market_history_type       (type_id)
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
            PARTITION BY RANGE COLUMNS (trade_date) (
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
    }

    public function down(): void
    {
        Schema::dropIfExists('market_history');
    }
};
