<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| battle_theaters.public_slug — stable shareable identifier
|--------------------------------------------------------------------------
|
| The clustering worker nukes every unlocked theater on each 5-min pass
| (DELETE FROM battle_theaters WHERE locked_at IS NULL) and reinserts
| with a fresh auto-increment id. A share link captured mid-fight dies
| within minutes because the numeric id no longer exists by the time
| someone opens it.
|
| `public_slug` is derived from (primary_system_name, min killed_at
| bucketed to minute) and therefore stable across reclusters of the
| same fight: the underlying killmails don't change, so min(killed_at)
| doesn't change, so the slug doesn't change.
|
| Not UNIQUE — on the rare occasion that the clusterer splits one
| fight into two distinct clusters with identical system+minute
| buckets, both rows get the same slug and the newer row wins at
| resolve time (`ORDER BY id DESC LIMIT 1`). Acceptable trade: the
| shared link always lands on the freshest view of that fight.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('battle_theaters', function (Blueprint $table) {
            $table->string('public_slug', 80)->nullable()->after('id');
            $table->index('public_slug', 'idx_battle_theaters_public_slug');
        });

        // Backfill existing rows with a system-name + minute slug so
        // older (locked) theaters get shareable URLs too. Python
        // workers writing new rows will populate the column going
        // forward.
        DB::statement(<<<'SQL'
            UPDATE battle_theaters bt
            JOIN ref_solar_systems rs ON rs.id = bt.primary_system_id
            SET bt.public_slug = CONCAT(
                REPLACE(REPLACE(LOWER(rs.name), ' ', '-'), '.', ''),
                '-',
                DATE_FORMAT(bt.start_time, '%Y%m%d%H%i')
            )
            WHERE bt.public_slug IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('battle_theaters', function (Blueprint $table) {
            $table->dropIndex('idx_battle_theaters_public_slug');
            $table->dropColumn('public_slug');
        });
    }
};
