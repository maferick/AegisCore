<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rename bloc_code 'cfc' → 'imperium'. Display_name was already
 * 'Imperium'; only the short code lagged behind. Updates derived
 * raw_label values so 'cfc.member' / 'cfc.associate' / etc. become
 * 'imperium.<kind>' to match.
 *
 * Idempotent: only acts when the old code is present.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('coalition_blocs')
            ->where('bloc_code', 'cfc')
            ->update(['bloc_code' => 'imperium', 'updated_at' => now()]);

        // Migrate any legacy raw_labels that were stamped with 'cfc.'.
        // Limit the rewrite to the bloc whose code we just changed —
        // belt and suspenders against unrelated rows that happen to
        // start with 'cfc.'.
        $blocId = DB::table('coalition_blocs')->where('bloc_code', 'imperium')->value('id');
        if ($blocId !== null) {
            DB::table('coalition_entity_labels')
                ->where('bloc_id', $blocId)
                ->where('raw_label', 'like', 'cfc.%')
                ->update([
                    'raw_label' => DB::raw("REPLACE(raw_label, 'cfc.', 'imperium.')"),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('coalition_blocs')
            ->where('bloc_code', 'imperium')
            ->update(['bloc_code' => 'cfc', 'updated_at' => now()]);

        $blocId = DB::table('coalition_blocs')->where('bloc_code', 'cfc')->value('id');
        if ($blocId !== null) {
            DB::table('coalition_entity_labels')
                ->where('bloc_id', $blocId)
                ->where('raw_label', 'like', 'imperium.%')
                ->update([
                    'raw_label' => DB::raw("REPLACE(raw_label, 'imperium.', 'cfc.')"),
                    'updated_at' => now(),
                ]);
        }
    }
};
