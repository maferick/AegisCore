<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Rework auto_doctrines: global identity + separate adopters table
|--------------------------------------------------------------------------
|
| The corp-keyed identity fragmented every cross-corp doctrine. Fix:
|
|   - auto_doctrines.corporation_id dropped. Identity is now just
|     (hull_type_id, role_key, canonical_fingerprint) — one
|     canonical doctrine per unique fit.
|
|   - new auto_doctrine_adopters table: one row per
|     (doctrine_id, corporation_id) tuple, tracking how many times
|     that corp has fielded the doctrine + when it was last seen.
|     The Portal "My Doctrines" view reads via this table, scoped
|     to the viewer's own corp.
|
| Existing rows wiped — next compute regenerates under the new
| identity.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM auto_doctrine_pilots');
        DB::statement('DELETE FROM auto_doctrine_modules');
        DB::statement('DELETE FROM auto_doctrines');

        DB::statement('ALTER TABLE auto_doctrines DROP INDEX uk_auto_doctrine');
        DB::statement('ALTER TABLE auto_doctrines DROP INDEX idx_ad_corp_role');
        DB::statement('ALTER TABLE auto_doctrines DROP COLUMN corporation_id');
        DB::statement(<<<'SQL'
            ALTER TABLE auto_doctrines
                ADD UNIQUE KEY uk_auto_doctrine (hull_type_id, role_key, canonical_fingerprint),
                ADD KEY idx_ad_role (role_key, confidence DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE auto_doctrine_adopters (
                doctrine_id    BIGINT NOT NULL,
                corporation_id BIGINT NOT NULL,
                observation_count INT NOT NULL DEFAULT 0,
                first_seen_at  DATETIME NOT NULL,
                last_seen_at   DATETIME NOT NULL,

                PRIMARY KEY (doctrine_id, corporation_id),
                KEY idx_ada_corp (corporation_id, observation_count DESC),

                CONSTRAINT fk_ada_doctrine FOREIGN KEY (doctrine_id) REFERENCES auto_doctrines (id) ON DELETE CASCADE,
                CONSTRAINT chk_ada_count CHECK (observation_count >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS auto_doctrine_adopters');
        DB::statement('DELETE FROM auto_doctrine_pilots');
        DB::statement('DELETE FROM auto_doctrine_modules');
        DB::statement('DELETE FROM auto_doctrines');
        DB::statement('ALTER TABLE auto_doctrines DROP INDEX uk_auto_doctrine');
        DB::statement('ALTER TABLE auto_doctrines DROP INDEX idx_ad_role');
        DB::statement('ALTER TABLE auto_doctrines ADD COLUMN corporation_id BIGINT NOT NULL AFTER id');
        DB::statement(<<<'SQL'
            ALTER TABLE auto_doctrines
                ADD UNIQUE KEY uk_auto_doctrine (corporation_id, hull_type_id, role_key, canonical_fingerprint),
                ADD KEY idx_ad_corp_role (corporation_id, role_key, confidence DESC)
        SQL);
    }
};
