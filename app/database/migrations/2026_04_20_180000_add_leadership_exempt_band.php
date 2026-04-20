<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a 'leadership_exempt' value to the ci_character_anomalies_rolling
 * review_priority_band enum. Used when the scored pilot is the
 * founding creator of their own alliance or sits in that alliance's
 * executor corporation — the "external heavy ties + recent join"
 * pattern is structurally expected for founders / leadership-corp
 * members and shouldn't count as insider-risk.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_anomalies_rolling
             MODIFY COLUMN review_priority_band ENUM(
                 'insufficient_history',
                 'cohort_unavailable',
                 'below_threshold',
                 'elevated',
                 'high',
                 'critical',
                 'leadership_exempt'
             ) NOT NULL DEFAULT 'below_threshold'
        SQL);
    }

    public function down(): void
    {
        // If any rows carry 'leadership_exempt', collapse them to
        // 'below_threshold' before shrinking the enum.
        DB::table('ci_character_anomalies_rolling')
            ->where('review_priority_band', 'leadership_exempt')
            ->update(['review_priority_band' => 'below_threshold']);

        DB::statement(<<<'SQL'
            ALTER TABLE ci_character_anomalies_rolling
             MODIFY COLUMN review_priority_band ENUM(
                 'insufficient_history',
                 'cohort_unavailable',
                 'below_threshold',
                 'elevated',
                 'high',
                 'critical'
             ) NOT NULL DEFAULT 'below_threshold'
        SQL);
    }
};
