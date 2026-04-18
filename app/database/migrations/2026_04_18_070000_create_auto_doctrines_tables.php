<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Spec 8 — auto-doctrine detection, role-tied
|--------------------------------------------------------------------------
|
| Derives doctrines from killmail fits the way SupplyCore does
| (per-hull clustering + Jaccard merge + core-module extraction +
| exponential-decay confidence) with two hard upgrades:
|
|   1. Every doctrine is scoped to **one alliance** AND **one
|      battlefield role** (FC / logi / mainline_dps / tackle / bomber
|      / command). Same hull + same fit but shown in different
|      tactical seats = different doctrine. This reduces the
|      overlap problem SupplyCore hit where Muninn fits from DPS
|      line pilots and scout alts blurred into one cluster.
|
|   2. Only emit doctrines where confidence >= threshold AND the
|      cluster came from a single alliance. "Faintly-seen" clusters
|      stay hidden until data accumulates.
|
| The three tables mirror SupplyCore's layout so importing their
| pattern is trivial — but the PK semantics are different: the
| (alliance_id, hull_type_id, role_key, fingerprint) tuple is the
| doctrine identity, not the legacy (hull, fingerprint) pair.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE auto_doctrines (
                id                     BIGINT NOT NULL AUTO_INCREMENT,
                alliance_id            BIGINT NOT NULL,
                hull_type_id           INT UNSIGNED NOT NULL,
                role_key               VARCHAR(32) NOT NULL,
                canonical_fingerprint  CHAR(32) NOT NULL,
                canonical_name         VARCHAR(191) NOT NULL,
                observation_count      INT NOT NULL DEFAULT 0,
                confidence             DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
                is_active              TINYINT(1) NOT NULL DEFAULT 0,
                first_seen_at          DATETIME NOT NULL,
                last_seen_at           DATETIME NOT NULL,
                computed_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uk_auto_doctrine (alliance_id, hull_type_id, role_key, canonical_fingerprint),
                KEY idx_ad_alliance_role (alliance_id, role_key, confidence DESC),
                KEY idx_ad_active (is_active, confidence DESC),
                KEY idx_ad_last_seen (last_seen_at),

                CONSTRAINT fk_ad_hull FOREIGN KEY (hull_type_id) REFERENCES ref_item_types (id),
                CONSTRAINT fk_ad_role FOREIGN KEY (role_key) REFERENCES battle_roles (role_key),
                CONSTRAINT chk_ad_confidence CHECK (confidence >= 0.0000 AND confidence <= 1.0000),
                CONSTRAINT chk_ad_counts CHECK (observation_count >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE auto_doctrine_modules (
                doctrine_id    BIGINT NOT NULL,
                type_id        INT UNSIGNED NOT NULL,
                flag_category  VARCHAR(16) NOT NULL,
                quantity       INT NOT NULL,
                frequency      DECIMAL(5,4) NOT NULL,

                PRIMARY KEY (doctrine_id, type_id, flag_category),

                CONSTRAINT fk_adm_doctrine FOREIGN KEY (doctrine_id) REFERENCES auto_doctrines (id) ON DELETE CASCADE,
                CONSTRAINT fk_adm_type FOREIGN KEY (type_id) REFERENCES ref_item_types (id),
                CONSTRAINT chk_adm_frequency CHECK (frequency >= 0.0000 AND frequency <= 1.0000),
                CONSTRAINT chk_adm_quantity  CHECK (quantity >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE auto_doctrine_pilots (
                doctrine_id   BIGINT NOT NULL,
                character_id  BIGINT NOT NULL,
                battle_id     BIGINT NOT NULL,
                killmail_id   BIGINT NOT NULL,
                seen_at       DATETIME NOT NULL,

                PRIMARY KEY (doctrine_id, killmail_id),
                KEY idx_adp_character (character_id, seen_at DESC),

                CONSTRAINT fk_adp_doctrine FOREIGN KEY (doctrine_id) REFERENCES auto_doctrines (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS auto_doctrine_pilots');
        DB::statement('DROP TABLE IF EXISTS auto_doctrine_modules');
        DB::statement('DROP TABLE IF EXISTS auto_doctrines');
    }
};
