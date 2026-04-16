<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Add asset metadata columns for first-wave enrichment classification
|--------------------------------------------------------------------------
|
| Resolved from ref_item_types → ref_item_groups → ref_item_categories
| during EnrichKillmail. Stored per-item so downstream consumers
| (battle reports, loss analytics, fit breakdown) can filter and
| aggregate without re-joining the ref tables every query.
|
| Hull-level classification on the killmails table gives the victim
| ship a resolved name, group, category, and strategic class without
| joining ref tables on every killmail list render.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('killmail_items', function (Blueprint $table) {
            // Resolved type name (e.g. "800mm Heavy Gallium Repeating Cannon I")
            $table->string('type_name', 200)->nullable()->after('type_id');

            // Group (e.g. "Projectile Weapon") — from ref_item_groups
            $table->unsignedInteger('group_id')->nullable()->after('type_name');
            $table->string('group_name', 100)->nullable()->after('group_id');

            // Category (e.g. "Module", "Charge", "Drone") — from ref_item_categories
            $table->unsignedInteger('category_id')->nullable()->after('group_name');
            $table->string('category_name', 100)->nullable()->after('category_id');

            // Meta group: 1=Tech I, 2=Tech II, 4=Storyline, 5=Faction,
            // 6=Officer, 14=Tech III, 15=Abyssal, etc.
            $table->unsignedInteger('meta_group_id')->nullable()->after('category_name');

            // Meta level within the meta group (0-14 typically).
            $table->integer('meta_level')->nullable()->after('meta_group_id');
        });

        Schema::table('killmails', function (Blueprint $table) {
            // Resolved hull metadata for the victim ship.
            $table->string('victim_ship_type_name', 200)->nullable()->after('victim_ship_type_id');
            $table->unsignedInteger('victim_ship_group_id')->nullable()->after('victim_ship_type_name');
            $table->string('victim_ship_group_name', 100)->nullable()->after('victim_ship_group_id');
            $table->unsignedInteger('victim_ship_category_id')->nullable()->after('victim_ship_group_name');
            $table->string('victim_ship_category_name', 100)->nullable()->after('victim_ship_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('killmail_items', function (Blueprint $table) {
            $table->dropColumn([
                'type_name', 'group_id', 'group_name',
                'category_id', 'category_name',
                'meta_group_id', 'meta_level',
            ]);
        });

        Schema::table('killmails', function (Blueprint $table) {
            $table->dropColumn([
                'victim_ship_type_name', 'victim_ship_group_id',
                'victim_ship_group_name', 'victim_ship_category_id',
                'victim_ship_category_name',
            ]);
        });
    }
};
