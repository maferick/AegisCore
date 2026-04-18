<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Ship-class category mapping (Spec 4)
|--------------------------------------------------------------------------
|
| Maps EVE ship_type_id → coarse role bucket used by feature extraction:
| logi | bomber | command | tackle | mainline. Anything not in this
| table is treated as "other" at compute time and does not block the
| feature row from being written.
|
| v1 scope: ~80 commonly-fielded hulls covering the categories that
| doctrine inference actually needs to disambiguate. Expanding the
| table later is additive and does not require a schema change.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE ship_class_category_mapping (
                ship_type_id INT UNSIGNED NOT NULL,
                category VARCHAR(16) NOT NULL,
                computed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (ship_type_id),
                KEY idx_sccm_category (category),
                CONSTRAINT fk_sccm_ship_type FOREIGN KEY (ship_type_id) REFERENCES ref_item_types(id),
                CONSTRAINT chk_sccm_category CHECK (category IN ('logi','bomber','command','tackle','mainline'))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        $rows = [
            // --- logi ---
            [11987, 'logi'],  // Guardian
            [11985, 'logi'],  // Basilisk
            [11989, 'logi'],  // Oneiros
            [11978, 'logi'],  // Scimitar
            [33472, 'logi'],  // Nestor
            [37457, 'logi'],  // Deacon
            [37458, 'logi'],  // Kirin
            [37460, 'logi'],  // Scalpel
            [37459, 'logi'],  // Thalia

            // --- bomber ---
            [12038, 'bomber'],  // Purifier
            [12034, 'bomber'],  // Hound
            [11377, 'bomber'],  // Nemesis
            [12032, 'bomber'],  // Manticore

            // --- command ---
            [22474, 'command'],  // Damnation
            [22448, 'command'],  // Absolution
            [22470, 'command'],  // Nighthawk
            [22446, 'command'],  // Vulture
            [22444, 'command'],  // Sleipnir
            [22468, 'command'],  // Claymore
            [22466, 'command'],  // Astarte
            [22442, 'command'],  // Eos
            [45534, 'command'],  // Monitor
            [37481, 'command'],  // Pontifex
            [37482, 'command'],  // Stork
            [37480, 'command'],  // Bifrost
            [37483, 'command'],  // Magus

            // --- tackle ---
            [11202, 'tackle'],  // Ares
            [11176, 'tackle'],  // Crow
            [11186, 'tackle'],  // Malediction
            [11198, 'tackle'],  // Stiletto
            [11196, 'tackle'],  // Claw
            [11184, 'tackle'],  // Crusader
            [11178, 'tackle'],  // Raptor
            [11200, 'tackle'],  // Taranis
            [12013, 'tackle'],  // Broadsword (HIC)
            [12017, 'tackle'],  // Devoter (HIC)
            [11995, 'tackle'],  // Onyx (HIC)
            [12021, 'tackle'],  // Phobos (HIC)
            [22456, 'tackle'],  // Sabre (dictor)
            [22460, 'tackle'],  // Eris (dictor)
            [22464, 'tackle'],  // Flycatcher (dictor)
            [22452, 'tackle'],  // Heretic (dictor)
            [17932, 'tackle'],  // Dramiel
            [33816, 'tackle'],  // Garmur
            [17924, 'tackle'],  // Succubus
            [17703, 'tackle'],  // Imperial Navy Slicer
            [608,   'tackle'],  // Atron
            [583,   'tackle'],  // Condor
            [589,   'tackle'],  // Executioner
            [603,   'tackle'],  // Merlin
            [587,   'tackle'],  // Rifter
            [585,   'tackle'],  // Slasher
            [591,   'tackle'],  // Tormentor

            // --- mainline ---
            [12015, 'mainline'],  // Muninn
            [11993, 'mainline'],  // Cerberus
            [12011, 'mainline'],  // Eagle
            [12023, 'mainline'],  // Deimos
            [12003, 'mainline'],  // Zealot
            [12005, 'mainline'],  // Ishtar
            [12019, 'mainline'],  // Sacrilege
            [11999, 'mainline'],  // Vagabond
            [638,   'mainline'],  // Raven
            [24688, 'mainline'],  // Rokh
            [644,   'mainline'],  // Typhoon
            [24694, 'mainline'],  // Maelstrom
            [17918, 'mainline'],  // Rattlesnake
            [17736, 'mainline'],  // Nightmare
            [32309, 'mainline'],  // Scorpion Navy Issue
            [17738, 'mainline'],  // Machariel
            [47466, 'mainline'],  // Praxis
            [641,   'mainline'],  // Megathron
            [642,   'mainline'],  // Apocalypse
            [24692, 'mainline'],  // Abaddon
            [24690, 'mainline'],  // Hyperion
            [639,   'mainline'],  // Tempest
            [24698, 'mainline'],  // Drake
            [16231, 'mainline'],  // Cyclone
            [621,   'mainline'],  // Caracal
            [29340, 'mainline'],  // Osprey Navy Issue
            [16242, 'mainline'],  // Thrasher
            [34562, 'mainline'],  // Svipul
            [34317, 'mainline'],  // Confessor
            [34828, 'mainline'],  // Jackdaw
            [35683, 'mainline'],  // Hecate
            [11381, 'mainline'],  // Harpy (AF)
            [12044, 'mainline'],  // Enyo (AF)
            [11393, 'mainline'],  // Retribution (AF)
            [11379, 'mainline'],  // Hawk (AF)
            [11371, 'mainline'],  // Wolf (AF)
            [11400, 'mainline'],  // Jaguar (AF)
            [12042, 'mainline'],  // Ishkur (AF)
            [11365, 'mainline'],  // Vengeance (AF)
        ];

        foreach (array_chunk($rows, 200) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?)'));
            $bind = [];
            foreach ($chunk as [$id, $cat]) {
                $bind[] = $id;
                $bind[] = $cat;
            }
            DB::statement(
                "INSERT INTO ship_class_category_mapping (ship_type_id, category) VALUES {$placeholders}",
                $bind
            );
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS ship_class_category_mapping');
    }
};
