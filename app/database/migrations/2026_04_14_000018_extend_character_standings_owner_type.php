<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/*
|--------------------------------------------------------------------------
| Extend character_standings.owner_type with 'character'
|--------------------------------------------------------------------------
|
| The original table (see 2026_04_14_000016_create_character_standings_table.php)
| shipped with owner_type ENUM('corporation', 'alliance') because corp
| and alliance contacts were expected to cover every donor. In practice:
|
|   - A solo-NPC-corp donor has no corp contacts at all (NPC corps don't
|     expose a player-curated contact list).
|   - A corp not in an alliance has no alliance contacts.
|
| Both cases leave the standings table empty for that donor, and the
| battle-report downstream has no "who do I trust" signal to tag them.
| Extending the ENUM to also accept 'character' lets the fetcher fall
| back to the donor's personal contacts
| (GET /characters/{id}/contacts) so those donors still get something.
|
| The display filter on /account/settings continues to exclude
| `contact_type = 'character'` regardless of owner_type — individual-
| character grudges never render on the shared settings surface. This
| is an expansion of WHERE the standings come from, not WHICH contacts
| are shown.
|
| Why a separate migration rather than editing 0016 in-place: 0016 has
| already been run on every deployed database. The framework remembers
| it's complete and won't re-run it, so an in-place edit would be a
| silent schema drift. A new ALTER migration keeps production's schema
| in lock-step with the repo's migration history.
|
| Schema::table() doesn't have a portable ENUM-extension helper, and
| MariaDB requires MODIFY COLUMN with the full new definition. Raw SQL
| it is.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // MODIFY COLUMN rewrites the ENUM definition in place. Existing
        // rows keep their values (both old values remain valid); new
        // writes can now use 'character'. No data migration needed.
        DB::statement(
            "ALTER TABLE character_standings MODIFY COLUMN owner_type ENUM('corporation', 'alliance', 'character') NOT NULL"
        );
    }

    public function down(): void
    {
        // Rolling back is safe only if no rows use the new value. The
        // fetcher won't insert 'character' rows until the model's
        // OWNER_CHARACTER constant is also deployed, so this window is
        // narrow; in practice a rollback here would only precede a
        // rollback of this feature as a whole. Assert no stragglers to
        // prevent a silent truncation if rollback is run in a dirty
        // state.
        $stragglers = DB::table('character_standings')
            ->where('owner_type', 'character')
            ->count();

        if ($stragglers > 0) {
            throw new \RuntimeException(
                "Cannot rollback: {$stragglers} character_standings rows use owner_type='character'. "
                .'Delete them first or roll the feature back fully.'
            );
        }

        DB::statement(
            "ALTER TABLE character_standings MODIFY COLUMN owner_type ENUM('corporation', 'alliance') NOT NULL"
        );
    }
};
