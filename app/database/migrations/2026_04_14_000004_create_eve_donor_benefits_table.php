<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| eve_donor_benefits — materialised per-donor ad-free state
|--------------------------------------------------------------------------
|
| One row per distinct `donor_character_id` in `eve_donations`. Stores the
| accumulated ad-free expiry computed by stacking each donation forward at
| the configured `EVE_DONATIONS_ISK_PER_DAY` rate.
|
| Why a separate table rather than computing on demand in isDonor()?
|
|   - `isDonor()` is called on every page render for logged-in users once
|     ad gating lands. The stacking algorithm has to process donations in
|     `donated_at` ASC order — cheap per donor (handful of rows), expensive
|     if a busy page calls it hundreds of times per minute against a donor
|     base that grows.
|   - Expiry is purely time-based ("is now < ad_free_until?"). The stored
|     `ad_free_until` doesn't need daily recomputes — it changes only when
|     a new donation arrives or the rate changes. The poller recomputes
|     for each affected donor on upsert; a dedicated
|     `eve:donations:recompute` artisan command rebuilds all rows (used
|     once after this migration, and whenever the operator changes
|     EVE_DONATIONS_ISK_PER_DAY).
|
| Stacking semantics (see DonorBenefitCalculator):
|
|     expiry[0] = donated_at[0]          + days_for(amount[0])
|     expiry[i] = max(donated_at[i],
|                     expiry[i-1])       + days_for(amount[i])
|
| i.e. donating while you're still covered extends the current window;
| donating after the window expired resets it from the arrival time.
|
| `isDonor()` → `EXISTS WHERE donor_character_id IN (…) AND ad_free_until > NOW()`.
| Once `ad_free_until` passes, the donor silently loses ad-free status —
| no cron needed.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eve_donor_benefits', function (Blueprint $table) {
            $table->id();

            // Donor's EVE character ID (same as eve_donations.donor_character_id).
            // Unique — one benefit row per donor.
            $table->unsignedBigInteger('donor_character_id')->unique();

            // Accumulated ad-free expiry. Computed as the stacked
            // forward-rolling end-of-window from all donations for this
            // character. NULL means "no donations yet" but those rows
            // shouldn't exist; the calculator never writes a null here.
            $table->timestamp('ad_free_until');

            // Aggregate display fields — kept alongside the derived
            // expiry so the donor card UI doesn't need a separate
            // aggregate query per donor. Recomputed whenever the row is
            // rebuilt (cheap because the calculator already iterates the
            // donations once to compute expiry).
            $table->decimal('total_isk_donated', 20, 2);
            $table->unsignedInteger('donations_count');
            $table->timestamp('first_donated_at');
            $table->timestamp('last_donated_at');

            // Which ISK-per-day rate the current `ad_free_until` was
            // computed against. When an operator changes the rate, a
            // stale-rate detector in the recompute command can spot
            // rows that need rebuilding. Null for the initial backfill
            // case only.
            $table->unsignedBigInteger('rate_isk_per_day')->nullable();

            // When the materialised row was last rebuilt. Useful for
            // spotting drift (rate changed but row not rebuilt).
            $table->timestamp('recomputed_at');

            $table->timestamps();

            // The isDonor() probe: "is this character currently covered?"
            // — composite index lets MySQL answer with a single range seek
            // on `ad_free_until` after the donor_character_id lookup.
            $table->index(['donor_character_id', 'ad_free_until']);
            // Sorting donor cards by who's covered longest — scanned on
            // the admin page when the donor base grows.
            $table->index('ad_free_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eve_donor_benefits');
    }
};
