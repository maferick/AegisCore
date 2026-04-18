<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Per-corp doctrine variants
|--------------------------------------------------------------------------
|
| One canonical fit per doctrine (the ≥80% core modules across every
| corp's losses of that hull+role) glues slightly-different corp
| variants together. Corps field the same Maelstrom doctrine but with
| minor nuance — different tank stack, different ewar, etc.
|
| This table stores each corp's own core module set within a
| doctrine: the ≥ corp-level-cutoff modules seen in THAT corp's
| killmails for that doctrine. Blade can compare adopter modules
| against the global core to surface "your corp's Maelstrom has
| these two extra modules".
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE auto_doctrine_adopter_modules (
                doctrine_id    BIGINT NOT NULL,
                corporation_id BIGINT NOT NULL,
                type_id        INT UNSIGNED NOT NULL,
                flag_category  VARCHAR(16) NOT NULL,
                quantity       INT NOT NULL,
                frequency      DECIMAL(5,4) NOT NULL,

                PRIMARY KEY (doctrine_id, corporation_id, type_id, flag_category),
                KEY idx_adam_corp_doctrine (corporation_id, doctrine_id),

                CONSTRAINT fk_adam_doctrine FOREIGN KEY (doctrine_id) REFERENCES auto_doctrines (id) ON DELETE CASCADE,
                CONSTRAINT fk_adam_type FOREIGN KEY (type_id) REFERENCES ref_item_types (id),
                CONSTRAINT chk_adam_frequency CHECK (frequency >= 0.0000 AND frequency <= 1.0000),
                CONSTRAINT chk_adam_quantity  CHECK (quantity >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS auto_doctrine_adopter_modules');
    }
};
