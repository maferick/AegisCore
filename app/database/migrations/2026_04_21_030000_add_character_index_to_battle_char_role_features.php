<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Speed up per-character lookups on battle_character_role_features.
 *
 * Existing idx_bcrf_battle_character is (battle_id, character_id) —
 * great for battle-scoped queries but not for the reverse direction.
 * The combat-anomaly sweep runs `WHERE character_id = ?` ~5000 times
 * per night; without a leading-character index the query planner
 * falls back to primary-key scan cost.
 *
 * Adds (character_id, battle_id) covering index so
 * counter-intel:compute-combat-anomalies stays inside its nightly
 * window even as candidate volume grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->indexExists('battle_character_role_features', 'idx_bcrf_character_battle')) {
            return;
        }
        Schema::table('battle_character_role_features', function ($t) {
            $t->index(['character_id', 'battle_id'], 'idx_bcrf_character_battle');
        });
    }

    public function down(): void
    {
        if (! $this->indexExists('battle_character_role_features', 'idx_bcrf_character_battle')) {
            return;
        }
        Schema::table('battle_character_role_features', function ($t) {
            $t->dropIndex('idx_bcrf_character_battle');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select('SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?', [$index]);
        return ! empty($rows);
    }
};
