<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-1 combat anomaly layer for counter-intel review.
 *
 * Stores per-pilot, per-window metrics measuring whether a
 * high-priority review candidate's on-grid behaviour is
 * statistically inconsistent with hull/doctrine/role peers:
 *
 *   - damage contribution (vs in-battle peers AND 90d cohort)
 *   - survival when same-hull peers die (conditional survival rate)
 *   - presence-in-ISK-losing battles (feeding proxy)
 *   - fit deviation from doctrine head for own losses
 *
 * Framing: review support, not verdict. Banding is coarse
 * (reinforces / neutral / weakens / insufficient_data) and a
 * dossier renders it alongside the existing graph + affiliation
 * signal — never as "this pilot is a spy".
 *
 * Populated nightly by counter-intel:compute-combat-anomalies
 * over ci_character_anomalies_rolling candidates in
 * band IN (critical, high, elevated). Row is keyed by
 * (character_id, viewer_bloc_id, window_end_date) so re-runs
 * against the same window upsert cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ci_combat_anomalies', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id');
            $table->unsignedInteger('viewer_bloc_id');
            $table->date('window_end_date');

            $table->unsignedInteger('battles_attended')->default(0);
            $table->unsignedInteger('battles_as_victim')->default(0);

            // Damage contribution.
            //   damage_share_median:
            //     median over window of (pilot damage in battle / median
            //     in-battle hull-peer damage). 1.0 = matches peers, <1 = low.
            //   damage_z_battle:
            //     peer-count-weighted avg of per-battle z-scores of
            //     pilot damage within same-side same-category peer set,
            //     peer_count capped at 40 to stop giant fights dominating.
            //   damage_z_cohort: 90d same-category cohort z-score.
            //   damage_z_self: z-score against pilot's own prior window
            //     (days 180-90 pre-window_end). Null when no baseline.
            $table->decimal('damage_share_median', 5, 3)->nullable();
            $table->decimal('damage_z_battle', 6, 3)->nullable();
            $table->decimal('damage_z_cohort', 6, 3)->nullable();
            $table->decimal('damage_z_self', 6, 3)->nullable();

            // Survival when peers die.
            //   survival_rate_peer_loss:
            //     fraction of qualifying battles (same-category peers ≥ 3,
            //     ≥ 50% of those peers died) where pilot survived.
            //   survival_z_cohort: z-score against 90d cohort.
            //   Requires battles_qualifying ≥ 5 to be non-null.
            $table->decimal('survival_rate_peer_loss', 5, 3)->nullable();
            $table->decimal('survival_z_cohort', 6, 3)->nullable();
            $table->unsignedInteger('survival_battles_qualifying')->default(0);

            // Feeding (presence-in-losses) bias.
            //   feed_rate: fraction of attended battles where pilot's
            //     alliance had net-negative ISK exchange.
            //   feeding_score: cohort-normalised z-score.
            $table->decimal('feed_rate', 5, 3)->nullable();
            $table->decimal('feeding_score', 6, 3)->nullable();

            // Fit deviation (pilot's own losses only).
            //   fit_deviation_median: median across pilot's losses in
            //     window of (count of fitted high/med/low module slots
            //     whose type_id differs from the dominant doctrine head
            //     for that role-variant + hull). Rigs excluded from
            //     headline metric; charges/cargo/drones ignored entirely.
            $table->unsignedTinyInteger('fit_deviation_median')->nullable();
            $table->unsignedInteger('fit_losses_counted')->default(0);

            // Cohort metadata.
            $table->unsignedInteger('cohort_size')->default(0);
            $table->boolean('has_self_baseline')->default(false);
            $table->enum('comparison_confidence', ['low', 'medium', 'high'])->default('low');

            // Banding output + persisted signal counts so the dossier
            // can explain the label without re-deriving the rule.
            $table->unsignedTinyInteger('signals_reinforcing_count')->default(0);
            $table->unsignedTinyInteger('signals_weakening_count')->default(0);
            $table->enum('combat_anomaly_band', [
                'reinforces', 'neutral', 'weakens', 'insufficient_data',
            ])->default('insufficient_data');

            $table->timestamp('computed_at')->useCurrent();

            $table->primary(['character_id', 'viewer_bloc_id', 'window_end_date'], 'ci_combat_anom_pk');
            $table->index(['viewer_bloc_id', 'window_end_date', 'combat_anomaly_band'], 'ci_combat_anom_browse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ci_combat_anomalies');
    }
};
