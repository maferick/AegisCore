<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Counter-Intel Dossier — Step 2 schema.
 *
 * Graph-structural features, viewer-relative (community + seed-anchored
 * similarity), sourced from Neo4j GDS on the internal subject subgraph
 * (characters currently affiliated with an alliance in the viewer's bloc).
 *
 * Keyed by (character_id, viewer_bloc_id, window_end_date, window_days)
 * so each viewer bloc gets its own community partition + seed set.
 *
 * These are review inputs, not verdicts (Neo4j fraud-triage guidance):
 * a high ring_size + many flagged neighbors means "investigate this
 * community", not "this person is guilty".
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ci_character_graph_features_rolling (
                character_id BIGINT UNSIGNED NOT NULL,
                viewer_bloc_id INT UNSIGNED NOT NULL,
                window_end_date DATE NOT NULL,
                window_days INT UNSIGNED NOT NULL DEFAULT 90,

                -- Community detection (Leiden over internal CI_CO_OCCURS_WITH,
                -- weighted by total_weight).
                ring_id BIGINT NULL,
                ring_size INT UNSIGNED NOT NULL DEFAULT 0,

                -- Internal-scoped betweenness (bridge within the bloc's own
                -- fleet-graph). Distinct from the global betweenness on the
                -- anomalies row, which includes hostiles as intermediate
                -- nodes.
                bridge_internal_score DECIMAL(14,4) NULL,
                bridge_internal_pct DECIMAL(5,4) NULL,

                -- Seed-anchored similarity: how close is this pilot to
                -- already-elevated review targets? Populated from the
                -- CI_SIMILAR_TO edges set written by gds.knn.
                similarity_to_flagged_max DECIMAL(5,4) NULL,
                similarity_to_flagged_count INT UNSIGNED NOT NULL DEFAULT 0,

                -- Is this pilot in the seed set itself? (Seeded by high
                -- hostile-history, high hostile-cooccurrence, or high
                -- opposing-side ratio.) Lets the dossier show "why flagged
                -- directly vs flagged via neighborhood".
                is_seed TINYINT(1) NOT NULL DEFAULT 0,

                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (character_id, viewer_bloc_id, window_end_date, window_days),
                KEY ix_cigf_bloc_window (viewer_bloc_id, window_end_date, window_days),
                KEY ix_cigf_ring (viewer_bloc_id, window_end_date, ring_id),
                KEY ix_cigf_similarity_to_flagged (viewer_bloc_id, window_end_date, similarity_to_flagged_count)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ci_character_graph_features_rolling');
    }
};
