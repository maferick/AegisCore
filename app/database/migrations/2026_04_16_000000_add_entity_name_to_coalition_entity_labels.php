<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Add entity_name to coalition_entity_labels
|--------------------------------------------------------------------------
|
| Cached display name for the CCP entity (corp or alliance). Resolved
| from ESI's /universe/names/ on create/edit in the admin panel and by
| the seeder. Nullable — labels work without it, the name is purely for
| human readability in the admin UI.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coalition_entity_labels', function (Blueprint $table) {
            $table->string('entity_name', 150)
                ->nullable()
                ->after('entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('coalition_entity_labels', function (Blueprint $table) {
            $table->dropColumn('entity_name');
        });
    }
};
