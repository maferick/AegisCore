<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend counter_intel_hypotheses.hypothesis_type enum with the
 * spec-listed types so the §18 fusion can persist defector/recruit
 * subjects as `suspicious_reactivation` rather than misclassifying
 * them as `single_pilot_high_priority`.
 *
 * Per §18 spec: hypothesis types include
 *   suspected_infiltration
 *   hostile_coordination
 *   suspicious_operational_overlap
 *   possible_same_operator_cluster
 *   anomalous_fleet_behavior
 *   hostile_overlap_cluster
 *   suspicious_reactivation
 *   operational_leak_pattern
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE counter_intel_hypotheses
              MODIFY COLUMN hypothesis_type ENUM(
                'single_pilot_high_priority',
                'correlated_cluster',
                'recurring_overlap',
                'temporal_correlation',
                'suspected_infiltration',
                'hostile_coordination',
                'suspicious_operational_overlap',
                'possible_same_operator_cluster',
                'anomalous_fleet_behavior',
                'hostile_overlap_cluster',
                'suspicious_reactivation',
                'operational_leak_pattern'
              ) NOT NULL DEFAULT 'single_pilot_high_priority'
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE counter_intel_hypotheses
              MODIFY COLUMN hypothesis_type ENUM(
                'single_pilot_high_priority',
                'correlated_cluster',
                'recurring_overlap',
                'temporal_correlation'
              ) NOT NULL DEFAULT 'single_pilot_high_priority'
        SQL);
    }
};
