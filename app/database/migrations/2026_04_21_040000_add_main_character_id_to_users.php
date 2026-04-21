<?php

declare(strict_types=1);

use App\Domains\UsersCharacters\Models\Character;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add main-character designation on users.
 *
 * Existing model: users.hasMany(characters). Each character has a
 * single user_id. Prior to this migration every character was
 * indistinguishable — no "this one is my main, the rest are alts"
 * concept. Needed for:
 *   - record keeping (one human, one main, N alts, clean roll-ups)
 *   - market order aggregation (show alt orders under main donor)
 *
 * Nullable FK so bootstrap / legacy users keep functioning. The
 * migration also seeds main_character_id for any user who already
 * has exactly one linked character (defaulting to that one as main).
 * Multi-character users need a manual pick from /portal/account-
 * settings, which is fine — those are operator / long-time donor
 * accounts and the picker is a one-click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('main_character_id')->nullable()->after('default_private_market_hub_id');
            $t->index('main_character_id', 'idx_users_main_character');
            $t->foreign('main_character_id', 'fk_users_main_character')
                ->references('id')->on('characters')
                ->nullOnDelete();
        });

        // Backfill: any user with exactly one character gets that
        // character as their main. Multi-character users are left
        // null — they'll pick from the portal.
        User::query()
            ->whereNull('main_character_id')
            ->whereHas('characters')
            ->withCount('characters')
            ->having('characters_count', 1)
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $char = $user->characters()->first();
                    if ($char) {
                        $user->main_character_id = $char->id;
                        $user->save();
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign('fk_users_main_character');
            $t->dropIndex('idx_users_main_character');
            $t->dropColumn('main_character_id');
        });
    }
};
