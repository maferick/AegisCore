<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Rekey auto_doctrines from alliance_id to corporation_id
|--------------------------------------------------------------------------
|
| Per operator direction (2026-04-18): corp is the correct doctrine
| unit. Alliances run multiple corps with divergent fit preferences;
| clustering at alliance granularity blurs distinct doctrines
| operated by member corps of the same alliance.
|
| Drops every existing auto_doctrines row (data from the alliance-keyed
| compute is stale anyway — next compute pass regenerates).
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // Wipe existing data — alliance-keyed rows can't be rekeyed to
        // corps without re-running the compute against the killmails.
        DB::statement('DELETE FROM auto_doctrine_pilots');
        DB::statement('DELETE FROM auto_doctrine_modules');
        DB::statement('DELETE FROM auto_doctrines');

        DB::statement('ALTER TABLE auto_doctrines DROP INDEX uk_auto_doctrine');
        DB::statement('ALTER TABLE auto_doctrines DROP INDEX idx_ad_alliance_role');
        DB::statement('ALTER TABLE auto_doctrines CHANGE alliance_id corporation_id BIGINT NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE auto_doctrines
                ADD UNIQUE KEY uk_auto_doctrine (corporation_id, hull_type_id, role_key, canonical_fingerprint),
                ADD KEY idx_ad_corp_role (corporation_id, role_key, confidence DESC)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DELETE FROM auto_doctrine_pilots');
        DB::statement('DELETE FROM auto_doctrine_modules');
        DB::statement('DELETE FROM auto_doctrines');
        DB::statement('ALTER TABLE auto_doctrines DROP INDEX uk_auto_doctrine');
        DB::statement('ALTER TABLE auto_doctrines DROP INDEX idx_ad_corp_role');
        DB::statement('ALTER TABLE auto_doctrines CHANGE corporation_id alliance_id BIGINT NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE auto_doctrines
                ADD UNIQUE KEY uk_auto_doctrine (alliance_id, hull_type_id, role_key, canonical_fingerprint),
                ADD KEY idx_ad_alliance_role (alliance_id, role_key, confidence DESC)
        SQL);
    }
};
