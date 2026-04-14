<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| alter eve_donor_benefits.ad_free_until → DATETIME
|--------------------------------------------------------------------------
|
| The original `2026_04_14_000004_create_eve_donor_benefits_table`
| migration declared `ad_free_until` as TIMESTAMP. That works at dev-test
| donation sizes but silently breaks once a single donation pushes the
| computed expiry past TIMESTAMP's hard ceiling of
| `2038-01-19 03:14:07 UTC` (the 32-bit Unix epoch).
|
| Example the bug surfaced on: a 1,000,000,000 ISK donation at the
| default `EVE_DONATIONS_ISK_PER_DAY=100000` rate stacks to ~10,000
| days of ad-free time — expiry ~2053-08. MySQL (strict mode) rejects
| the out-of-range value on insert, which
| `DonorBenefitCalculator::recomputeForCharacter()` propagates up; the
| poller's catch-all `Throwable` handler then logs a warning and moves
| on. Net effect: the donation lands in `eve_donations` but no
| matching `eve_donor_benefits` row gets written, the donor silently
| loses their ad-free status, and the admin page shows "No active
| donors yet" even though a donation just landed.
|
| Fix: widen the column to DATETIME (range up to `9999-12-31`). Matches
| the rationale we already used for `market_orders.observed_at` — both
| planes write UTC explicitly, so "naked" DATETIME semantics are the
| correct fit and also side-step TIMESTAMP's implicit session-tz
| conversion footgun.
|
| After this migration runs, operators should run
| `php artisan eve:donations:recompute` once so benefit rows that
| failed to land under the old schema get materialised at the current
| rate.
|
| Raw SQL `ALTER TABLE` rather than Blueprint `->change()` — same
| pattern the other migration fixes in this series use, and keeps us
| independent of doctrine/dbal.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE eve_donor_benefits
            MODIFY COLUMN ad_free_until DATETIME NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Reversal is lossy by design: any row with
        // `ad_free_until > '2038-01-19 03:14:07'` will fail to
        // migrate back. Guard with a conservative clamp so the
        // rollback at least succeeds — operators rolling back are
        // presumably re-building, not preserving, benefit rows.
        DB::statement(<<<'SQL'
            UPDATE eve_donor_benefits
            SET ad_free_until = '2038-01-19 03:14:07'
            WHERE ad_free_until > '2038-01-19 03:14:07'
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE eve_donor_benefits
            MODIFY COLUMN ad_free_until TIMESTAMP NOT NULL
        SQL);
    }
};
