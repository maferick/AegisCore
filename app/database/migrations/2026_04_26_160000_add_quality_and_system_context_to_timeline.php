<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4.2C + 4.2D — quality scoring + system context.
 *
 * 4.2C — quality is separate from confidence:
 *   confidence  → "how much do I trust this row exists at all"
 *   quality     → "how operationally meaningful is this row"
 * Example: a high-confidence MOTD line is low-quality (noise);
 * a medium-confidence escalation is high-quality (operational).
 *
 * 4.2D — system context lets the dossier + map overlays show
 * where each event fired:
 *   solar_system_id  → ref_solar_systems.id
 *   region_id        → ref_solar_systems.region_id (denormalised)
 *   battle_id        → optional FK to battle_theaters when the
 *                      timeline window overlaps a known theater
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE operational_timeline_events
              ADD COLUMN quality ENUM(
                'noisy','weak','normal','strong','strategic'
              ) NOT NULL DEFAULT 'normal' AFTER confidence,
              ADD COLUMN region_id INT UNSIGNED NULL AFTER solar_system_id,
              ADD INDEX idx_optl_quality (quality, event_timestamp),
              ADD INDEX idx_optl_region (region_id, event_timestamp)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE operational_timeline_events
              DROP INDEX idx_optl_quality,
              DROP INDEX idx_optl_region,
              DROP COLUMN quality,
              DROP COLUMN region_id
        SQL);
    }
};
