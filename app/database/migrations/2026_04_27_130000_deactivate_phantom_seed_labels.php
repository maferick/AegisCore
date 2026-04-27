<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time cleanup: deactivate `seed`-sourced coalition_entity_labels
 * whose (entity_id, name) pair disagrees with esi_entity_names, plus
 * the row for Pandemic Horde (alliance #99005338, since disbanded).
 *
 * Background: the original CoalitionEntityLabelSeeder hard-coded
 * alliance ids that were guessed; nearly every Imperium / B2 / PanFam
 * row pointed at either a phantom id (no ESI entity) or an unrelated
 * alliance whose real name didn't match the seeded name. Result: the
 * hostility resolver double-classified some alliances (e.g. Brave
 * Collective in both B2 and Imperium) and mis-classified others.
 *
 * Wiki sync (commit d60ec4e) supersedes seed for any entity present
 * in a wiki. This migration cleans up the rest — entities present
 * only in seed where the seed itself is wrong.
 *
 * Idempotent: walks active seed rows, leaves correctly-paired ones
 * alone, deactivates the rest.
 */
return new class extends Migration {
    public function up(): void
    {
        $rows = DB::table('coalition_entity_labels AS l')
            ->leftJoin('esi_entity_names AS en', function ($join) {
                $join->on('en.entity_id', '=', 'l.entity_id')
                     ->whereColumn('en.category', 'l.entity_type');
            })
            ->where('l.source', 'seed')
            ->where('l.is_active', 1)
            ->where(function ($q) {
                $q->whereNull('en.name')
                  ->orWhereRaw('LOWER(en.name) <> LOWER(l.entity_name)');
            })
            ->select('l.id')
            ->pluck('l.id')
            ->all();
        if ($rows !== []) {
            DB::table('coalition_entity_labels')
                ->whereIn('id', $rows)
                ->update(['is_active' => 0, 'updated_at' => now()]);
        }

        // Pandemic Horde (alliance #99005338) — disbanded per operator
        // record. Deactivate any remaining active label.
        DB::table('coalition_entity_labels')
            ->where('entity_id', 99005338)
            ->where('entity_type', 'alliance')
            ->where('is_active', 1)
            ->update(['is_active' => 0, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // No automatic restore. The deactivated rows held wrong data;
        // re-activating would re-introduce the bug. Operator can
        // manually re-tag via Filament admin if needed.
    }
};
