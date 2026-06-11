<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0014 — advanced subtle-spy correlation signals (B-0 scaffold).
 *
 * Creates 5 materialised signal tables + 1 evidence-link table joining
 * signal fires to counter_intel_hypotheses rows. Extends the
 * hypothesis_type enum with 7 new pattern values (one per signal).
 *
 * No data is computed by this migration — the Python passes that fill
 * each table land in subsequent commits (B-1 through B-5). Schema lands
 * here so review can happen in isolation.
 */
return new class extends Migration {
    public function up(): void
    {
        // ---- 1. opposite-side correlation -----------------------------------
        Schema::create('ci_opposite_side_correlations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('viewer_bloc_id');
            $t->date('window_start');
            $t->date('window_end');
            $t->unsignedSmallInteger('window_days');
            $t->unsignedBigInteger('friendly_character_id');
            $t->unsignedBigInteger('hostile_character_id');
            $t->unsignedInteger('shared_battles');
            $t->unsignedInteger('friendly_total_battles');
            $t->unsignedInteger('hostile_total_battles');
            $t->decimal('friendly_to_hostile_ratio', 6, 4);
            $t->decimal('hostile_to_friendly_ratio', 6, 4);
            $t->decimal('asymmetry_score', 6, 4);
            $t->enum('confidence', ['low', 'medium', 'high'])->default('low');
            $t->json('evidence_json')->nullable();
            $t->dateTime('computed_at')->useCurrent();
            $t->timestamps();
            $t->unique(
                ['viewer_bloc_id', 'window_end', 'friendly_character_id', 'hostile_character_id'],
                'uniq_oss_window_pair',
            );
            $t->index(['friendly_character_id', 'window_end'], 'idx_oss_friendly');
            $t->index(['hostile_character_id', 'window_end'], 'idx_oss_hostile');
            $t->index(['asymmetry_score', 'shared_battles'], 'idx_oss_score');
        });

        // ---- 2. event-triggered activity ------------------------------------
        Schema::create('ci_event_triggered_activity', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('viewer_bloc_id');
            $t->date('window_start');
            $t->date('window_end');
            $t->unsignedSmallInteger('window_days');
            $t->unsignedBigInteger('character_id');
            $t->unsignedInteger('strategic_event_count');
            $t->unsignedInteger('character_active_near_event_count');
            $t->unsignedInteger('activity_outside_event_count');
            $t->decimal('event_selectivity_score', 6, 4);
            $t->enum('confidence', ['low', 'medium', 'high'])->default('low');
            $t->json('evidence_json')->nullable();
            $t->dateTime('computed_at')->useCurrent();
            $t->timestamps();
            $t->unique(['viewer_bloc_id', 'window_end', 'character_id'], 'uniq_eta_window_char');
            $t->index(['event_selectivity_score', 'strategic_event_count'], 'idx_eta_score');
        });

        // ---- 3. participation selectivity ----------------------------------
        Schema::create('ci_participation_selectivity', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('viewer_bloc_id');
            $t->date('window_start');
            $t->date('window_end');
            $t->unsignedSmallInteger('window_days');
            $t->unsignedBigInteger('character_id');
            $t->unsignedInteger('large_fleet_count');
            $t->unsignedInteger('medium_fleet_count');
            $t->unsignedInteger('small_gang_count');
            $t->unsignedInteger('solo_count');
            $t->unsignedInteger('total_fights');
            $t->decimal('large_fleet_ratio', 6, 4);
            $t->decimal('selectivity_score', 6, 4);
            $t->enum('confidence', ['low', 'medium', 'high'])->default('low');
            $t->json('evidence_json')->nullable();
            $t->dateTime('computed_at')->useCurrent();
            $t->timestamps();
            $t->unique(['viewer_bloc_id', 'window_end', 'character_id'], 'uniq_psel_window_char');
            $t->index(['large_fleet_ratio', 'total_fights'], 'idx_psel_score');
        });

        // ---- 4. reaction-timing correlation --------------------------------
        Schema::create('ci_reaction_timing_correlations', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('viewer_bloc_id');
            $t->date('window_start');
            $t->date('window_end');
            $t->unsignedSmallInteger('window_days');
            $t->unsignedBigInteger('character_id');
            $t->unsignedBigInteger('hostile_entity_id')->nullable();
            $t->enum('hostile_entity_type', ['character', 'corporation', 'alliance'])->nullable();
            $t->unsignedInteger('trigger_events');
            $t->unsignedInteger('hostile_responses_within_window');
            $t->decimal('median_response_minutes', 8, 2)->nullable();
            $t->decimal('reaction_score', 6, 4);
            $t->enum('confidence', ['low', 'medium', 'high'])->default('low');
            $t->json('evidence_json')->nullable();
            $t->dateTime('computed_at')->useCurrent();
            $t->timestamps();
            $t->unique(
                ['viewer_bloc_id', 'window_end', 'character_id', 'hostile_entity_id', 'hostile_entity_type'],
                'uniq_rtc_window_pair',
            );
            $t->index(['character_id', 'window_end'], 'idx_rtc_char');
            $t->index(['reaction_score', 'hostile_responses_within_window'], 'idx_rtc_score');
        });

        // ---- 5. cohort behavior deviation ----------------------------------
        Schema::create('ci_cohort_behavior_deviation', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedInteger('viewer_bloc_id');
            $t->date('window_start');
            $t->date('window_end');
            $t->unsignedSmallInteger('window_days');
            $t->unsignedBigInteger('character_id');
            $t->string('cohort_key', 120);
            $t->unsignedInteger('cohort_size');
            $t->decimal('doctrine_deviation_score', 6, 4)->nullable();
            $t->decimal('region_deviation_score', 6, 4)->nullable();
            $t->decimal('fleet_size_deviation_score', 6, 4)->nullable();
            $t->decimal('activity_time_deviation_score', 6, 4)->nullable();
            $t->decimal('combined_deviation_score', 6, 4);
            $t->enum('confidence', ['low', 'medium', 'high'])->default('low');
            $t->json('evidence_json')->nullable();
            $t->dateTime('computed_at')->useCurrent();
            $t->timestamps();
            $t->unique(['viewer_bloc_id', 'window_end', 'character_id'], 'uniq_cbd_window_char');
            $t->index(['combined_deviation_score'], 'idx_cbd_score');
            $t->index(['cohort_key', 'window_end'], 'idx_cbd_cohort');
        });

        // ---- 6. hypothesis evidence link table -----------------------------
        // Joins individual signal fires to counter_intel_hypotheses rows so
        // the hypothesis loop can count per-domain corroboration and apply
        // decay. (Phase B-6 will wire this into hypothesis recompute.)
        Schema::create('ci_hypothesis_evidence', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('hypothesis_id');
            $t->string('signal_table', 80);
            $t->unsignedBigInteger('signal_row_id');
            $t->string('signal_type', 60);
            $t->decimal('scoring_contribution', 8, 4);
            $t->enum('confidence_at_observation', ['low', 'medium', 'high'])->default('low');
            $t->enum('domain', ['graph', 'operational', 'timing', 'behavioural', 'cohort', 'presence', 'correlation']);
            $t->dateTime('observed_at');
            $t->decimal('decay_factor', 5, 4)->default(1.0);
            $t->json('evidence_payload_json')->nullable();
            $t->timestamps();
            $t->index(['hypothesis_id'], 'idx_che_hyp');
            $t->index(['signal_table', 'signal_row_id'], 'idx_che_signal');
            $t->index(['domain', 'observed_at'], 'idx_che_domain');
            $t->foreign('hypothesis_id', 'fk_che_hypothesis')
                ->references('id')->on('counter_intel_hypotheses')
                ->onDelete('cascade');
        });

        // ---- 7. extend hypothesis_type enum --------------------------------
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
                'operational_leak_pattern',
                'opposite_side_correlation',
                'asymmetric_handler_pattern',
                'event_triggered_activity_pattern',
                'participation_selectivity_pattern',
                'contribution_anomaly_pattern',
                'reaction_timing_correlation_pattern',
                'cohort_behavior_deviation_pattern'
              ) NOT NULL DEFAULT 'single_pilot_high_priority'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_hypothesis_evidence');
        Schema::dropIfExists('ci_cohort_behavior_deviation');
        Schema::dropIfExists('ci_reaction_timing_correlations');
        Schema::dropIfExists('ci_participation_selectivity');
        Schema::dropIfExists('ci_event_triggered_activity');
        Schema::dropIfExists('ci_opposite_side_correlations');

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
};
