<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| ref_* — EVE SDE item taxonomy, dogma, industry (phase 1, ADR-0001)
|--------------------------------------------------------------------------
|
| Canonical store for item types, groups, categories, market groups, dogma
| attributes/effects, blueprints, and adjacent item-scoped reference data.
| Truncated + reloaded by python/sde_importer on each SDE snapshot.
|
| Shape conventions documented in
| 2026_04_13_000002_create_ref_universe_tables.php.
|
*/
return new class extends Migration {
    public function up(): void
    {
        // ---- Item taxonomy --------------------------------------------------

        Schema::create('ref_item_categories', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100);
            $t->unsignedInteger('icon_id')->nullable();
            $t->boolean('published')->default(false);
            $t->json('data');
            $t->index('published');
            $t->index('name');
        });

        Schema::create('ref_item_groups', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100);
            $t->unsignedInteger('category_id');
            $t->unsignedInteger('icon_id')->nullable();
            $t->boolean('anchorable')->default(false);
            $t->boolean('anchored')->default(false);
            $t->boolean('fittable_non_singleton')->default(false);
            $t->boolean('use_base_price')->default(false);
            $t->boolean('published')->default(false);
            $t->json('data');
            $t->index('category_id');
            $t->index('published');
            $t->index('name');
        });

        Schema::create('ref_market_groups', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->unsignedInteger('parent_group_id')->nullable();
            $t->unsignedInteger('icon_id')->nullable();
            $t->boolean('has_types')->default(false);
            $t->json('data');
            $t->index('parent_group_id');
            $t->index('name');
        });

        Schema::create('ref_meta_groups', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->json('data');
        });

        Schema::create('ref_item_types', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 200);
            $t->unsignedInteger('group_id');
            $t->unsignedInteger('market_group_id')->nullable();
            $t->unsignedInteger('meta_group_id')->nullable();
            $t->unsignedInteger('faction_id')->nullable();
            $t->unsignedInteger('race_id')->nullable();
            $t->unsignedInteger('icon_id')->nullable();
            $t->unsignedInteger('graphic_id')->nullable();
            $t->unsignedInteger('sound_id')->nullable();
            $t->unsignedInteger('variation_parent_type_id')->nullable();
            $t->integer('meta_level')->nullable();
            $t->decimal('base_price', 20, 2)->nullable();
            $t->double('mass')->nullable();
            $t->double('radius')->nullable();
            $t->double('volume')->nullable();
            $t->double('capacity')->nullable();
            $t->integer('portion_size')->nullable();
            $t->boolean('published')->default(false);
            $t->json('data');
            $t->index('group_id');
            $t->index('market_group_id');
            $t->index('meta_group_id');
            $t->index('published');
            $t->index('name');
            // Fulltext on name: lets the PHP resolver do cheap "find a type
            // called 'Rifter'" lookups without a LIKE scan over 50k rows.
            // Deferred — requires MyISAM/InnoDB fulltext on VARCHAR, nicer
            // to add in a focused PR once we know the usage pattern.
        });

        Schema::create('ref_compressible_types', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('compressed_type_id');
            $t->json('data');
            $t->index('compressed_type_id');
        });

        Schema::create('ref_contraband_types', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->json('data');
        });

        Schema::create('ref_dynamic_item_attributes', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->json('data');
        });

        Schema::create('ref_type_materials', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // type_id
            $t->json('data');
        });

        Schema::create('ref_type_dogma', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // type_id
            $t->json('data');
        });

        Schema::create('ref_type_bonus', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary(); // type_id
            $t->json('data');
        });

        // ---- Dogma ----------------------------------------------------------

        Schema::create('ref_dogma_attributes', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->unsignedInteger('attribute_category_id')->nullable();
            $t->double('default_value')->nullable();
            $t->unsignedTinyInteger('data_type')->nullable();
            $t->boolean('high_is_good')->default(true);
            $t->boolean('stackable')->default(true);
            $t->boolean('display_when_zero')->default(false);
            $t->boolean('published')->default(false);
            $t->json('data');
            $t->index('attribute_category_id');
            $t->index('published');
            $t->index('name');
        });

        Schema::create('ref_dogma_effects', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 150)->nullable();
            $t->string('guid', 128)->nullable();
            $t->integer('effect_category_id')->nullable();
            $t->unsignedInteger('discharge_attribute_id')->nullable();
            $t->unsignedInteger('duration_attribute_id')->nullable();
            $t->boolean('is_offensive')->default(false);
            $t->boolean('is_assistance')->default(false);
            $t->boolean('is_warp_safe')->default(true);
            $t->boolean('disallow_auto_repeat')->default(false);
            $t->boolean('published')->default(false);
            $t->json('data');
            $t->index('effect_category_id');
            $t->index('published');
            $t->index('guid');
        });

        Schema::create('ref_dogma_attribute_categories', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->json('data');
        });

        Schema::create('ref_dogma_units', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->string('name', 100)->nullable();
            $t->json('data');
        });

        Schema::create('ref_dbuff_collections', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->json('data');
        });

        // ---- Industry -------------------------------------------------------

        Schema::create('ref_blueprints', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedInteger('blueprint_type_id');
            $t->integer('max_production_limit')->nullable();
            $t->json('data');
            $t->index('blueprint_type_id');
        });
    }

    public function down(): void
    {
        foreach ([
            'ref_blueprints',
            'ref_dbuff_collections',
            'ref_dogma_units',
            'ref_dogma_attribute_categories',
            'ref_dogma_effects',
            'ref_dogma_attributes',
            'ref_type_bonus',
            'ref_type_dogma',
            'ref_type_materials',
            'ref_dynamic_item_attributes',
            'ref_contraband_types',
            'ref_compressible_types',
            'ref_item_types',
            'ref_meta_groups',
            'ref_market_groups',
            'ref_item_groups',
            'ref_item_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
