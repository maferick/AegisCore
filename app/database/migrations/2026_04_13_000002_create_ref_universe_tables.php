<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| ref_* — EVE SDE universe topology (phase 1, ADR-0001)
|--------------------------------------------------------------------------
|
| Canonical store for universe topology parsed from CCP's SDE JSONL zip
| by python/sde_importer. Every row here is derived — never hand-edited —
| and the whole set is truncated + reloaded on each snapshot import.
|
| Shape rules for all ref_* tables:
|
|   * PK is `id`, an unsigned int sourced from the SDE `_key` field.
|     No autoincrement — CCP owns the IDs.
|   * Hot scalar fields are extracted to typed columns with indexes on
|     FK-ish columns (region_id, constellation_id, solar_system_id, …).
|     No FK constraints — truncate-reload doesn't want the cascade bill,
|     and phase-1 reload happens in a maintenance window (ADR-0001 §4).
|   * Name is the English translation (`name.en` from the i18n dict).
|     The full i18n payload survives in the `data` JSON column.
|   * `data` LONGTEXT JSON catches the full original row so we never lose
|     fields. Future PRs can promote hot overflow fields into typed
|     columns without a reload.
|
*/
return new class extends Migration {
    public function up(): void
    {
        // Snapshot marker: one row per successful import. Lets the admin panel
        // and downstream consumers answer "which build is currently loaded?"
        // without peeking at ref_regions etc.
        Schema::create('ref_snapshot', function (Blueprint $t) {
            $t->unsignedBigInteger('build_number')->primary();
            $t->timestamp('release_date')->nullable();
            $t->string('etag', 128)->nullable();
            $t->string('last_modified', 64)->nullable();
            $t->timestamp('loaded_at')->useCurrent();
        });

        Schema::create('ref_regions', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100);
            $t->unsignedInteger('faction_id')->nullable();
            $t->unsignedInteger('nebula_id')->nullable();
            $t->unsignedTinyInteger('wormhole_class_id')->nullable();
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
            $t->index('faction_id');
            $t->index('name');
        });

        Schema::create('ref_constellations', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100);
            $t->unsignedInteger('region_id');
            $t->unsignedInteger('faction_id')->nullable();
            $t->unsignedTinyInteger('wormhole_class_id')->nullable();
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
            $t->index('region_id');
            $t->index('faction_id');
            $t->index('name');
        });

        Schema::create('ref_solar_systems', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100);
            $t->unsignedInteger('region_id');
            $t->unsignedInteger('constellation_id');
            $t->unsignedInteger('star_id')->nullable();
            $t->float('security_status')->nullable();
            $t->string('security_class', 8)->nullable();
            $t->boolean('hub')->default(false);
            $t->boolean('border')->default(false);
            $t->boolean('international')->default(false);
            $t->boolean('regional')->default(false);
            $t->double('luminosity')->nullable();
            $t->double('radius')->nullable();
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
            $t->index('region_id');
            $t->index('constellation_id');
            $t->index('security_status');
            $t->index('name');
        });

        Schema::create('ref_stargates', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedInteger('destination_system_id')->nullable();
            $t->unsignedInteger('destination_stargate_id')->nullable();
            $t->unsignedInteger('type_id')->nullable();
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
            $t->index('destination_system_id');
        });

        Schema::create('ref_stars', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedInteger('type_id')->nullable();
            $t->double('radius')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
        });

        Schema::create('ref_planets', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedInteger('type_id')->nullable();
            $t->unsignedTinyInteger('celestial_index')->nullable();
            $t->double('radius')->nullable();
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
        });

        Schema::create('ref_moons', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedInteger('orbit_id')->nullable();
            $t->unsignedInteger('type_id')->nullable();
            $t->unsignedTinyInteger('celestial_index')->nullable();
            $t->unsignedTinyInteger('orbit_index')->nullable();
            $t->double('radius')->nullable();
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
            $t->index('orbit_id');
        });

        Schema::create('ref_asteroid_belts', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedInteger('orbit_id')->nullable();
            $t->unsignedInteger('type_id')->nullable();
            $t->double('radius')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
            $t->index('orbit_id');
        });

        Schema::create('ref_secondary_suns', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('solar_system_id');
            $t->unsignedInteger('type_id')->nullable();
            $t->unsignedInteger('effect_beacon_type_id')->nullable();
            $t->json('data');
            $t->index('solar_system_id');
        });

        Schema::create('ref_landmarks', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 200)->nullable();
            $t->double('position_x')->nullable();
            $t->double('position_y')->nullable();
            $t->double('position_z')->nullable();
            $t->json('data');
        });
    }

    public function down(): void
    {
        foreach ([
            'ref_landmarks',
            'ref_secondary_suns',
            'ref_asteroid_belts',
            'ref_moons',
            'ref_planets',
            'ref_stars',
            'ref_stargates',
            'ref_solar_systems',
            'ref_constellations',
            'ref_regions',
            'ref_snapshot',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
