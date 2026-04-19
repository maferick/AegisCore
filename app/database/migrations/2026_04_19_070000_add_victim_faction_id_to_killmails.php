<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Capture victim-side FW enlistment at time of kill.
 *
 * ESI + zKill both include `victim.faction_id` in the killmail payload
 * when the victim was enlisted in a militia at the moment of death.
 * We discarded that field on ingest previously; adding it now lets
 * bloc-intel use "FW pilots on BOTH sides" as the true taint gate
 * instead of the attacker-only heuristic.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE killmails
              ADD COLUMN victim_faction_id INT UNSIGNED NULL AFTER victim_alliance_id,
              ADD INDEX ix_km_victim_faction (victim_faction_id, killed_at)
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE killmails
              DROP INDEX ix_km_victim_faction,
              DROP COLUMN victim_faction_id
        SQL);
    }
};
