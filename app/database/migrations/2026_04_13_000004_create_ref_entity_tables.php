<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| ref_* — EVE SDE entities: factions, corps, stations, characters, skills,
| visual assets, PI, and miscellany (phase 1, ADR-0001)
|--------------------------------------------------------------------------
|
| Everything that isn't universe topology or item taxonomy. Shape rules
| documented in 2026_04_13_000002_create_ref_universe_tables.php.
|
*/
return new class extends Migration {
    public function up(): void
    {
        // ---- Factions / races / bloodlines ---------------------------------

        Schema::create('ref_factions', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->unsignedInteger('corporation_id')->nullable();
            $t->unsignedInteger('militia_corporation_id')->nullable();
            $t->unsignedInteger('solar_system_id')->nullable();
            $t->unsignedInteger('icon_id')->nullable();
            $t->double('size_factor')->nullable();
            $t->json('data');
            $t->index('corporation_id');
            $t->index('solar_system_id');
            $t->index('name');
        });

        Schema::create('ref_races', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->unsignedInteger('ship_type_id')->nullable();
            $t->unsignedInteger('icon_id')->nullable();
            $t->json('data');
        });

        Schema::create('ref_bloodlines', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->unsignedInteger('race_id');
            $t->unsignedInteger('corporation_id')->nullable();
            $t->unsignedInteger('icon_id')->nullable();
            $t->unsignedTinyInteger('charisma')->nullable();
            $t->unsignedTinyInteger('intelligence')->nullable();
            $t->unsignedTinyInteger('memory')->nullable();
            $t->unsignedTinyInteger('perception')->nullable();
            $t->unsignedTinyInteger('willpower')->nullable();
            $t->json('data');
            $t->index('race_id');
        });

        Schema::create('ref_ancestries', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->unsignedInteger('bloodline_id');
            $t->unsignedInteger('icon_id')->nullable();
            $t->unsignedTinyInteger('charisma')->nullable();
            $t->unsignedTinyInteger('intelligence')->nullable();
            $t->unsignedTinyInteger('memory')->nullable();
            $t->unsignedTinyInteger('perception')->nullable();
            $t->unsignedTinyInteger('willpower')->nullable();
            $t->json('data');
            $t->index('bloodline_id');
        });

        // ---- NPC corps / stations / characters -----------------------------

        Schema::create('ref_npc_corporations', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 200)->nullable();
            $t->string('ticker_name', 16)->nullable();
            $t->unsignedInteger('ceo_id')->nullable();
            $t->unsignedInteger('station_id')->nullable();
            $t->string('size', 16)->nullable();
            $t->string('extent', 16)->nullable();
            $t->float('tax_rate')->nullable();
            $t->float('min_security')->nullable();
            $t->boolean('deleted')->default(false);
            $t->boolean('has_player_personnel_manager')->default(false);
            $t->json('data');
            $t->index('station_id');
            $t->index('deleted');
            $t->index('name');
        });

        Schema::create('ref_npc_corporation_divisions', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->string('internal_name', 100)->nullable();
            $t->string('display_name', 100)->nullable();
            $t->json('data');
        });

        Schema::create('ref_npc_stations', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedInteger('owner_id')->nullable();
            $t->unsignedInteger('operation_id')->nullable();
            $t->unsignedInteger('type_id')->nullable();
            $t->unsignedInteger('orbit_id')->nullable();
            $t->float('reprocessing_efficiency')->nullable();
            $t->float('reprocessing_stations_take')->nullable();
            $t->unsignedTinyInteger('reprocessing_hangar_flag')->nullable();
            $t->boolean('use_operation_name')->default(true);
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
            $t->index('owner_id');
            $t->index('operation_id');
        });

        Schema::create('ref_npc_characters', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 200)->nullable();
            $t->unsignedInteger('corporation_id')->nullable();
            $t->unsignedInteger('bloodline_id')->nullable();
            $t->unsignedInteger('race_id')->nullable();
            $t->unsignedInteger('location_id')->nullable();
            $t->boolean('ceo')->default(false);
            $t->boolean('gender')->default(false);
            $t->json('data');
            $t->index('corporation_id');
            $t->index('location_id');
        });

        Schema::create('ref_station_operations', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('operation_name', 100)->nullable();
            $t->unsignedInteger('activity_id')->nullable();
            $t->float('border')->nullable();
            $t->float('corridor')->nullable();
            $t->float('fringe')->nullable();
            $t->float('hub')->nullable();
            $t->float('manufacturing_factor')->nullable();
            $t->float('research_factor')->nullable();
            $t->float('ratio')->nullable();
            $t->json('data');
            $t->index('activity_id');
        });

        Schema::create('ref_station_services', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('service_name', 100)->nullable();
            $t->json('data');
        });

        Schema::create('ref_agent_types', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->json('data');
        });

        Schema::create('ref_agents_in_space', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id')->nullable();
            $t->unsignedInteger('dungeon_id')->nullable();
            $t->unsignedInteger('spawn_point_id')->nullable();
            $t->unsignedInteger('type_id')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
        });

        // ---- Skills / certs / character --------------------------------------

        Schema::create('ref_certificates', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 200)->nullable();
            $t->unsignedInteger('group_id')->nullable();
            $t->json('data');
            $t->index('group_id');
        });

        Schema::create('ref_masteries', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // type_id
            $t->json('data');
        });

        Schema::create('ref_character_attributes', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->unsignedInteger('icon_id')->nullable();
            $t->json('data');
        });

        Schema::create('ref_clone_grades', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->json('data');
        });

        // ---- Visual assets --------------------------------------------------

        Schema::create('ref_icons', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('icon_file', 255)->nullable();
            $t->json('data');
        });

        Schema::create('ref_graphics', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('graphic_file', 255)->nullable();
            $t->json('data');
        });

        Schema::create('ref_skins', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('internal_name', 200)->nullable();
            $t->unsignedInteger('skin_material_id')->nullable();
            $t->boolean('visible_tranquility')->default(false);
            $t->boolean('visible_serenity')->default(false);
            $t->boolean('allow_ccp_devs')->default(false);
            $t->json('data');
            $t->index('skin_material_id');
        });

        Schema::create('ref_skin_materials', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('display_name', 200)->nullable();
            $t->unsignedInteger('material_set_id')->nullable();
            $t->json('data');
        });

        Schema::create('ref_skin_licenses', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('license_type_id')->nullable();
            $t->unsignedInteger('skin_id')->nullable();
            $t->integer('duration')->nullable();
            $t->json('data');
            $t->index('skin_id');
        });

        // ---- Planetary / PI -------------------------------------------------

        Schema::create('ref_planet_resources', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // solar_system_id
            $t->integer('power')->nullable();
            $t->json('data');
        });

        Schema::create('ref_planet_schematics', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->integer('cycle_time')->nullable();
            $t->json('data');
        });

        Schema::create('ref_control_tower_resources', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->json('data');
        });

        // ---- Misc -----------------------------------------------------------

        Schema::create('ref_translation_languages', function (Blueprint $t) {
            // _key is a string here (e.g. "en"), not an int.
            $t->string('id', 8)->primary();
            $t->string('name', 64)->nullable();
            $t->json('data');
        });

        Schema::create('ref_corporation_activities', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->json('data');
        });

        Schema::create('ref_sovereignty_upgrades', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('mutually_exclusive_group', 100)->nullable();
            $t->integer('power_allocation')->nullable();
            $t->integer('workforce_allocation')->nullable();
            $t->json('data');
        });

        Schema::create('ref_mercenary_tactical_operations', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 150)->nullable();
            $t->integer('anarchy_impact')->nullable();
            $t->integer('development_impact')->nullable();
            $t->integer('infomorph_bonus')->nullable();
            $t->json('data');
        });

        Schema::create('ref_freelance_job_schemas', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->json('data');
        });
    }

    public function down(): void
    {
        foreach ([
            'ref_freelance_job_schemas',
            'ref_mercenary_tactical_operations',
            'ref_sovereignty_upgrades',
            'ref_corporation_activities',
            'ref_translation_languages',
            'ref_control_tower_resources',
            'ref_planet_schematics',
            'ref_planet_resources',
            'ref_skin_licenses',
            'ref_skin_materials',
            'ref_skins',
            'ref_graphics',
            'ref_icons',
            'ref_clone_grades',
            'ref_character_attributes',
            'ref_masteries',
            'ref_certificates',
            'ref_agents_in_space',
            'ref_agent_types',
            'ref_station_services',
            'ref_station_operations',
            'ref_npc_characters',
            'ref_npc_stations',
            'ref_npc_corporation_divisions',
            'ref_npc_corporations',
            'ref_ancestries',
            'ref_bloodlines',
            'ref_races',
            'ref_factions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
