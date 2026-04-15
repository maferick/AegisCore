<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| corporation_affiliation_profiles — derived "where is this corp" summary
|--------------------------------------------------------------------------
|
| One row per corporation, summarising the resolver's current view of
| that corp's alliance situation:
|
|   - which alliance it's currently in (or null for corps that left)
|   - which alliance it was in immediately before the most recent change
|   - when the most recent change happened
|   - whether that change is recent enough to temper automatic trust
|
| This is a derived / denormalised helper table, not the system of
| record. The full ESI /corporations/{id}/alliancehistory/ chain goes
| into a separate raw-history table in a later migration — that table
| hasn't shipped yet, so the first population pass for this profile
| will write `current_alliance_id` only, from the affiliation sync
| job that already runs for viewer contexts. As the raw-history table
| comes online, the profile becomes a computed summary on top of it.
|
| Why this exists as a standalone table instead of three columns on a
| corporations ref table:
|
|   - There is no player-corporations ref table yet (deferred). We
|     need somewhere to hang this data.
|   - The resolver joins this table hot on every classification for
|     a corporation target. Keeping it narrow and indexed is worth
|     the denormalisation.
|   - `recently_changed_affiliation` is a first-class trust signal
|     surfaced in the donor UI ("this corp moved blocs last week —
|     double-check your classification"). It earns its own column
|     rather than being recomputed from timestamps per render.
|
| Freshness policy: the affiliation sync job stamps `observed_at`
| every run. The resolver downgrades confidence on any profile whose
| observed_at is older than the freshness threshold (see the resolver
| design notes — currently "fresh" ≤ 7 days, "stale-but-usable" 7–30
| days, "weak" > 30 days). The threshold lives in application config,
| not in this schema.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_affiliation_profiles', function (Blueprint $table) {
            // Keyed on the corp's CCP id — one row per corp, this is
            // the natural PK. No auto-increment `id` because there is
            // no need for a surrogate here.
            $table->unsignedBigInteger('corporation_id')->primary();

            // Current alliance, if any. Null means the corp is
            // unaffiliated right now (or never was — distinguishable
            // only via the history table once it ships).
            $table->unsignedBigInteger('current_alliance_id')->nullable();

            // Alliance immediately before the most recent change.
            // Null if the corp has never moved (still in its founding
            // alliance, or has always been independent). This column
            // is what powers "recently left X" signals in the UI.
            $table->unsignedBigInteger('previous_alliance_id')->nullable();

            // Timestamp of the most recent alliance change. Null for
            // corps with no change on record. Not a `TIMESTAMP` with
            // useCurrent — this is historical data, often pre-dates
            // the row's creation.
            $table->timestamp('last_alliance_change_at')->nullable();

            // True if last_alliance_change_at is within the recent-
            // change window (default 14 days, configured in the
            // resolver). Stored rather than computed-on-read so the
            // resolver doesn't recompute time arithmetic per entity
            // per classification. The affiliation sync job refreshes
            // this flag on each run.
            $table->boolean('recently_changed_affiliation')->default(false);

            // Ordinal confidence in the history-derived inferences.
            // 'high' = full history chain available and stable.
            // 'medium' = partial history or recent churn.
            // 'low' = current-only, no history loaded yet.
            // Before the raw-history table ships, every freshly-
            // populated row is 'low'.
            $table->enum('history_confidence_band', ['high', 'medium', 'low'])
                ->default('low');

            // Last successful sync of this profile from ESI. Separate
            // from updated_at (which changes on any write, including
            // a no-op idempotent upsert). observed_at is the
            // operational "how stale is this" signal the resolver
            // reads.
            $table->timestamp('observed_at')->useCurrent();

            $table->timestamps();

            // Primary resolver probe: "given this corp, tell me what
            // alliance it's in and whether to trust it right now".
            // The PK already covers corporation_id lookups; this
            // index supports the reverse direction.
            $table->index('current_alliance_id', 'idx_corp_affiliation_current');

            // UI probe: "show me the corps that changed affiliation
            // recently" for the admin trust-signal dashboard.
            $table->index(
                ['recently_changed_affiliation', 'last_alliance_change_at'],
                'idx_corp_affiliation_recent_change'
            );

            // Staleness probe for the nightly recompute sweep.
            $table->index('observed_at', 'idx_corp_affiliation_observed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_affiliation_profiles');
    }
};
