<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Retire market_watched_locations.owner_user_id (ADR-0005 follow-up)
|--------------------------------------------------------------------------
|
| ADR-0005 moved classification + donor identity off the watched row
| onto the canonical hub + collector tables. Follow-up #3 (the Python
| poller rewrite) stopped reading the column; this migration closes
| the loop by retiring the column from the schema entirely and
| enforcing the "one polling lane per physical market" invariant at
| the DB level.
|
| Four steps, atomic in one transaction:
|
|   1. **Deduplicate**. Any watched row whose hub already has another
|      watched row gets archived via DELETE. We keep the row with the
|      smallest `id` (oldest) so the seeded Jita row always wins.
|      In the current production state this is a no-op — we have
|      exactly one row per hub. Included for migration portability.
|
|   2. **Backfill remaining NULLs**. Any watched row with hub_id=NULL
|      (shouldn't exist after the ADR-0005 § Transition backfill +
|      the `AccountSettings::addStructure` fix, but belt-and-braces)
|      raises an explicit error. Silent DELETE would hide a real bug.
|
|   3. **Swap the unique key**. Drop the old
|      `uniq_watched_owner_location (owner_user_id, location_id)` +
|      `idx_watched_owner` index. Add `uniq_watched_hub (hub_id)` so
|      the "one polling lane per hub" invariant is enforced by the
|      schema, not just by application code.
|
|   4. **Drop the column**. `dropConstrainedForeignId('owner_user_id')`
|      drops the FK + the column in one call.
|
| The `hub_id` column is promoted from nullable to NOT NULL here too
| — now that every row must point at a hub, a NULL would be an
| integrity bug waiting to happen.
|
| Rollback is intentionally destructive of the post-migration
| invariants: we can restore the column, but we can't recover the
| per-donor uniqueness semantics the old unique key enforced. Backup
| before rolling back.
|
| See docs/adr/0005-private-market-hub-overlay.md § Follow-ups.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        // We deliberately don't wrap up() in DB::transaction here:
        // MariaDB auto-commits each DDL statement, so a transaction
        // doesn't actually buy us rollback for schema changes. The
        // idempotent checks below handle partially-applied states
        // cleanly — this migration can be safely re-run if an earlier
        // attempt left the schema half-converted.

        // 1. Dedup any hub with multiple watched rows. Keep oldest.
        $duplicates = DB::table('market_watched_locations')
            ->select('hub_id', DB::raw('MIN(id) AS keep_id'), DB::raw('COUNT(*) AS n'))
            ->whereNotNull('hub_id')
            ->groupBy('hub_id')
            ->having('n', '>', 1)
            ->get();

        foreach ($duplicates as $dup) {
            DB::table('market_watched_locations')
                ->where('hub_id', $dup->hub_id)
                ->where('id', '!=', $dup->keep_id)
                ->delete();
        }

        // 2. Any row with hub_id=NULL is a pre-migration gap the
        //    backfill should have caught. Fail loudly rather than
        //    silently orphan.
        $unattached = DB::table('market_watched_locations')
            ->whereNull('hub_id')
            ->count();
        if ($unattached > 0) {
            throw new RuntimeException(
                "Refusing to retire owner_user_id: {$unattached} watched row(s) "
                .'have NULL hub_id. Re-run the ADR-0005 backfill migration '
                .'(2026_04_14_000015) or manually attach these rows to a hub '
                .'before running this migration.'
            );
        }

        // 3. Drop legacy indexes if present. Both are no-ops on a
        //    stack where migration 000007's unique was never created
        //    (some older installs), and skipped if a prior run of this
        //    migration already dropped them.
        $this->dropIndexIfExists('market_watched_locations', 'uniq_watched_owner_location');

        // 4. Drop the FK + column. MariaDB auto-drops the owner-
        //    supporting `idx_watched_owner` as part of the column
        //    drop; we must not drop it explicitly beforehand (would
        //    fail with 1553 — needed by the FK). Skipped if the
        //    column is already gone (partial prior run).
        if ($this->columnExists('market_watched_locations', 'owner_user_id')) {
            Schema::table('market_watched_locations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('owner_user_id');
            });
        }

        // 5. Promote hub_id to NOT NULL if it's still nullable.
        if ($this->columnIsNullable('market_watched_locations', 'hub_id')) {
            Schema::table('market_watched_locations', function (Blueprint $table): void {
                $table->unsignedBigInteger('hub_id')->nullable(false)->change();
            });
        }

        // 6. Add the new UNIQUE(hub_id) if it's not already there.
        //    Enforces the "one polling lane per hub" invariant at
        //    the schema level — any future code path that tries to
        //    insert a second watched row for the same hub fails
        //    loudly instead of silently duplicating polling work.
        if (! $this->indexExists('market_watched_locations', 'uniq_watched_hub')) {
            Schema::table('market_watched_locations', function (Blueprint $table): void {
                $table->unique('hub_id', 'uniq_watched_hub');
            });
        }
    }

    public function down(): void
    {
        Schema::table('market_watched_locations', function (Blueprint $table): void {
            // Drop the new unique first (it references hub_id, which
            // is fine to keep) — we're restoring the pre-migration
            // shape, where hub_id was nullable and
            // (owner_user_id, location_id) was the uniqueness rule.
            $table->dropUnique('uniq_watched_hub');
        });

        Schema::table('market_watched_locations', function (Blueprint $table): void {
            $table->unsignedBigInteger('hub_id')->nullable()->change();

            // Re-add owner_user_id. Values are NOT recoverable — the
            // old contents lived in the dropped column, and we
            // don't snapshot it before dropping. Every row comes
            // back with NULL, which is the safest default ("platform
            // default") for every rehydrated row.
            $table->foreignId('owner_user_id')
                ->nullable()
                ->after('location_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->unique(['owner_user_id', 'location_id'], 'uniq_watched_owner_location');
            $table->index('owner_user_id', 'idx_watched_owner');
        });
    }

    /**
     * Drop an index only if it exists. MariaDB / MySQL < 8 don't
     * support `DROP INDEX IF EXISTS`, and Laravel's schema builder
     * has no equivalent helper. We check information_schema directly
     * and fall through silently when the index is absent.
     */
    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName): void {
            // `dropIndex` is name-based when given a string, which is
            // what we want here — the caller may be dropping a unique
            // or a plain index, and from the information_schema
            // perspective they're the same thing.
            $blueprint->dropIndex($indexName);
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        $row = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->first(['IS_NULLABLE']);

        return $row !== null && strtoupper((string) $row->IS_NULLABLE) === 'YES';
    }
};
