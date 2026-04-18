<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Meta-variant collapse: canonical_type_id + variants_json
|--------------------------------------------------------------------------
|
| Adds two columns to auto_doctrine_modules:
|   - canonical_type_id: the T1-stem type_id (ref_item_types.variation_parent_type_id ?? id).
|     Used by the clusterer for fingerprinting, so meta/T2/faction
|     variants of the same module collapse to one cluster identity.
|   - variants_json: JSON array of the specific variants observed
|     within the cluster, shape [{type_id, name, count, frequency},…]
|     so the UI can annotate "primary = Multi Hardener II (90%);
|     also seen: Compact (7%), Enduring (3%)".
|
| The existing type_id column keeps holding the MOST-COMMON specific
| variant (so EFT export + buyall lists continue to render a real
| module name, not the T1 stem). variants_json carries the rest.
|
| Existing doctrine rows are wiped; next compute regenerates with
| meta-collapsed clustering.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM auto_doctrine_adopter_modules');
        DB::statement('DELETE FROM auto_doctrine_alliance_adopters');
        DB::statement('DELETE FROM auto_doctrine_bloc_adopters');
        DB::statement('DELETE FROM auto_doctrine_pilots');
        DB::statement('DELETE FROM auto_doctrine_adopters');
        DB::statement('DELETE FROM auto_doctrine_modules');
        DB::statement('DELETE FROM auto_doctrines');

        DB::statement(<<<'SQL'
            ALTER TABLE auto_doctrine_modules
                ADD COLUMN canonical_type_id INT UNSIGNED NOT NULL AFTER flag_category,
                ADD COLUMN variants_json JSON NULL AFTER frequency,
                ADD KEY idx_adm_canonical (canonical_type_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auto_doctrine_modules DROP KEY idx_adm_canonical, DROP COLUMN variants_json, DROP COLUMN canonical_type_id');
    }
};
