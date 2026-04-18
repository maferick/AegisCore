<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Alliance + bloc adoption tracking for auto-doctrines
|--------------------------------------------------------------------------
|
| Mirrors auto_doctrine_adopters but at alliance + bloc granularity.
| The Portal view shows three tabs: My Corp / My Alliance / My Bloc
| so viewers can see what their alliance and bloc are fielding even
| when their specific corp hasn't.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE auto_doctrine_alliance_adopters (
                doctrine_id    BIGINT NOT NULL,
                alliance_id    BIGINT NOT NULL,
                observation_count INT NOT NULL DEFAULT 0,
                first_seen_at  DATETIME NOT NULL,
                last_seen_at   DATETIME NOT NULL,

                PRIMARY KEY (doctrine_id, alliance_id),
                KEY idx_adaa_alliance (alliance_id, observation_count DESC),
                CONSTRAINT fk_adaa_doctrine FOREIGN KEY (doctrine_id) REFERENCES auto_doctrines (id) ON DELETE CASCADE,
                CONSTRAINT chk_adaa_count CHECK (observation_count >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE auto_doctrine_bloc_adopters (
                doctrine_id    BIGINT NOT NULL,
                bloc_id        BIGINT UNSIGNED NOT NULL,
                observation_count INT NOT NULL DEFAULT 0,
                first_seen_at  DATETIME NOT NULL,
                last_seen_at   DATETIME NOT NULL,

                PRIMARY KEY (doctrine_id, bloc_id),
                KEY idx_adba_bloc (bloc_id, observation_count DESC),
                CONSTRAINT fk_adba_doctrine FOREIGN KEY (doctrine_id) REFERENCES auto_doctrines (id) ON DELETE CASCADE,
                CONSTRAINT fk_adba_bloc FOREIGN KEY (bloc_id) REFERENCES coalition_blocs (id) ON DELETE CASCADE,
                CONSTRAINT chk_adba_count CHECK (observation_count >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS auto_doctrine_bloc_adopters');
        DB::statement('DROP TABLE IF EXISTS auto_doctrine_alliance_adopters');
    }
};
