<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| battle_role_ui_trust_gates — per-role UI release gates
|--------------------------------------------------------------------------
|
| Per Spec 1. Operator-facing threshold per role per calibration metric.
| When the calibration pipeline (later spec) proves a role beats its
| threshold, the UI lifts the role from beta → production via
| ui_state_on_pass. Individual roles can graduate independently.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE battle_role_ui_trust_gates (
                role_key                    VARCHAR(32) NOT NULL,
                metric_key                  VARCHAR(32) NOT NULL,
                threshold_value             DECIMAL(5,4) NOT NULL,
                ui_state_on_pass            VARCHAR(16) NOT NULL DEFAULT 'production',
                is_active                   TINYINT(1) NOT NULL DEFAULT 1,
                notes                       VARCHAR(255) NULL,
                created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (role_key, metric_key),
                CONSTRAINT fk_brutg_role
                    FOREIGN KEY (role_key)
                    REFERENCES battle_roles(role_key),
                CONSTRAINT chk_brutg_metric_key
                    CHECK (metric_key IN ('top1_accuracy', 'top2_accuracy', 'precision', 'recall')),
                CONSTRAINT chk_brutg_ui_state
                    CHECK (ui_state_on_pass IN ('beta', 'production', 'hidden'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS battle_role_ui_trust_gates');
    }
};
