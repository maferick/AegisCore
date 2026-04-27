<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Emergency v1.§stage1 — daily order-level aggregates so raw
 * market_orders can be retention-trimmed to 72 h.
 *
 * Grain: per (observed_date, region, location, type, is_buy).
 * One row captures the day's snapshot population for that key.
 *
 * Metrics:
 *  - min_price / max_price / avg_price / weighted_avg_price
 *  - best_price (best from buyer/seller perspective —
 *    MAX(price) for buy orders, MIN(price) for sell orders)
 *  - order_count            total snapshot rows
 *  - unique_order_count     COUNT(DISTINCT order_id)
 *  - total_volume_remain    SUM at last seen
 *  - first_seen_at / last_seen_at
 *
 * NOT a replacement for market_history (which is daily price
 * tickers per region per type). This table preserves
 * location-level granularity so DoctrineMarket / hub-comparison
 * style queries can shift off raw market_orders > 72h.
 *
 * Range-partitioned by observed_date so historical drops are
 * cheap.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE market_order_daily_aggregates (
                observed_date DATE NOT NULL,
                region_id INT UNSIGNED NOT NULL,
                location_id BIGINT UNSIGNED NOT NULL,
                type_id INT UNSIGNED NOT NULL,
                is_buy TINYINT(1) NOT NULL,
                min_price DECIMAL(20,2) NOT NULL,
                max_price DECIMAL(20,2) NOT NULL,
                avg_price DECIMAL(20,2) NOT NULL,
                weighted_avg_price DECIMAL(20,2) NOT NULL,
                best_price DECIMAL(20,2) NOT NULL,
                order_count INT UNSIGNED NOT NULL DEFAULT 0,
                unique_order_count INT UNSIGNED NOT NULL DEFAULT 0,
                total_volume_remain BIGINT UNSIGNED NOT NULL DEFAULT 0,
                first_seen_at DATETIME NOT NULL,
                last_seen_at DATETIME NOT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (observed_date, region_id, location_id, type_id, is_buy),
                INDEX idx_moda_region_type_date (region_id, type_id, observed_date),
                INDEX idx_moda_location_type_date (location_id, type_id, observed_date),
                INDEX idx_moda_type_date (type_id, observed_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            PARTITION BY RANGE COLUMNS(observed_date) (
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
        DB::statement('DROP TABLE IF EXISTS market_order_daily_aggregates');
    }
};
