<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Add canonical_type_id to auto_doctrine_adopter_modules
|--------------------------------------------------------------------------
|
| Lets the Portal merge corp-variant modules against the global core
| via canonical id, so meta-variant swaps (Compact / Enduring / II)
| are recognised as "same module" across the global and corp layers.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE FROM auto_doctrine_adopter_modules');
        DB::statement(<<<'SQL'
            ALTER TABLE auto_doctrine_adopter_modules
                ADD COLUMN canonical_type_id INT UNSIGNED NOT NULL AFTER flag_category,
                ADD KEY idx_adam_canonical (doctrine_id, canonical_type_id)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auto_doctrine_adopter_modules DROP KEY idx_adam_canonical, DROP COLUMN canonical_type_id');
    }
};
