<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Materialize per-killmail pilot role tags into `killmail_pilot_role`.
 *
 * For every attacker on every killmail: one row (killmail, character,
 * 'attacker', ship, category, role).  For every victim: same but
 * side='victim'. Role derives from the ship's hull category via
 * `ship_class_category_mapping`. Monitor (45534) is always 'fc'.
 *
 * Idempotent — uses INSERT … ON DUPLICATE KEY UPDATE so reruns refresh
 * the role_key if the category mapping changes.
 */
class ComputeKillmailPilotRolesCommand extends Command
{
    protected $signature = 'killmails:compute-pilot-roles
                            {--since= : Only process killmails after this date (YYYY-MM-DD or ISO)}
                            {--batch-size=50000}
                            {--reset : Truncate the table before rebuilding}';

    protected $description = 'Derive per-killmail pilot role tags from hull category.';

    public function handle(): int
    {
        if ((bool) $this->option('reset')) {
            $this->warn('Truncating killmail_pilot_role…');
            DB::statement('TRUNCATE TABLE killmail_pilot_role');
        }

        $sinceSql = $this->option('since') ? "AND k.killed_at >= '" . addslashes((string) $this->option('since')) . "'" : '';

        // Pass 1 — attackers.
        $this->info('Attacker pass…');
        $n = DB::affectingStatement(<<<SQL
            INSERT INTO killmail_pilot_role (killmail_id, character_id, side, ship_type_id, ship_class_category, role_key)
            SELECT
                ka.killmail_id,
                ka.character_id,
                'attacker' AS side,
                ka.ship_type_id,
                COALESCE(sccm.category, 'other') AS ship_class_category,
                CASE
                    WHEN ka.ship_type_id = 45534 THEN 'fc'
                    WHEN sccm.category = 'logi' THEN 'logi'
                    WHEN sccm.category = 'bomber' THEN 'bomber'
                    WHEN sccm.category = 'command' THEN 'command'
                    WHEN sccm.category = 'tackle' THEN 'tackle'
                    WHEN sccm.category = 'mainline' THEN 'mainline_dps'
                    ELSE 'other'
                END AS role_key
            FROM killmail_attackers ka
            JOIN killmails k ON k.killmail_id = ka.killmail_id
            LEFT JOIN ship_class_category_mapping sccm ON sccm.ship_type_id = ka.ship_type_id
            WHERE ka.character_id IS NOT NULL
              AND ka.ship_type_id IS NOT NULL
              {$sinceSql}
            ON DUPLICATE KEY UPDATE
                ship_type_id = VALUES(ship_type_id),
                ship_class_category = VALUES(ship_class_category),
                role_key = VALUES(role_key),
                computed_at = NOW()
        SQL);
        $this->info(sprintf('  attackers processed %s', number_format($n)));

        // Pass 2 — victims.
        $this->info('Victim pass…');
        $totalVic = DB::affectingStatement(<<<SQL
            INSERT INTO killmail_pilot_role (killmail_id, character_id, side, ship_type_id, ship_class_category, role_key)
            SELECT
                k.killmail_id,
                k.victim_character_id AS character_id,
                'victim' AS side,
                k.victim_ship_type_id AS ship_type_id,
                COALESCE(sccm.category, 'other') AS ship_class_category,
                CASE
                    WHEN k.victim_ship_type_id = 45534 THEN 'fc'
                    WHEN sccm.category = 'logi' THEN 'logi'
                    WHEN sccm.category = 'bomber' THEN 'bomber'
                    WHEN sccm.category = 'command' THEN 'command'
                    WHEN sccm.category = 'tackle' THEN 'tackle'
                    WHEN sccm.category = 'mainline' THEN 'mainline_dps'
                    ELSE 'other'
                END AS role_key
            FROM killmails k
            LEFT JOIN ship_class_category_mapping sccm ON sccm.ship_type_id = k.victim_ship_type_id
            WHERE k.victim_character_id IS NOT NULL
              AND k.victim_ship_type_id IS NOT NULL
              {$sinceSql}
            ON DUPLICATE KEY UPDATE
                ship_type_id = VALUES(ship_type_id),
                ship_class_category = VALUES(ship_class_category),
                role_key = VALUES(role_key),
                computed_at = NOW()
        SQL);
        $this->info(sprintf('  victims processed %s', number_format($totalVic)));

        $this->info('Done.');
        return self::SUCCESS;
    }
}
