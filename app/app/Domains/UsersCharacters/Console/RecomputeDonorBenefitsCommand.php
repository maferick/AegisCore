<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Console;

use App\Domains\UsersCharacters\Services\DonorBenefitCalculator;
use Illuminate\Console\Command;

/**
 * Rebuild every row in `eve_donor_benefits` from the raw
 * `eve_donations` ledger, against the current
 * `EVE_DONATIONS_ISK_PER_DAY` rate.
 *
 * When it runs:
 *
 *   - Hourly as a scheduled safety net (see routes/console.php). The
 *     poller recomputes per-donor in-line after each upsert, which is
 *     the primary path, but if anything throws between the donation
 *     upsert and the recompute loop the donation lands in
 *     `eve_donations` without a matching `eve_donor_benefits` row.
 *     The next poller tick doesn't retry because the journal_ref_id
 *     is no longer "fresh". An hourly full rebuild closes that gap.
 *   - On demand after an operator changes `EVE_DONATIONS_ISK_PER_DAY`.
 *     The new rate is retroactive by design: you don't want past
 *     donors stuck on the old (possibly stingier) curve. A manual
 *     run reseeds every donor's `ad_free_until` at the new rate
 *     without waiting for the hourly tick.
 *   - Once as a one-shot backfill after the `eve_donor_benefits`
 *     migration lands, to materialise rows for donors whose donations
 *     were recorded before the poller grew incremental recompute.
 *
 * Cost: donor base is dozens of characters; each recompute is
 * microseconds; the whole pass is idempotent (rewriting the same row
 * with the same values when nothing has changed). Hourly cadence is
 * comfortably inside the plane-boundary budget.
 */
final class RecomputeDonorBenefitsCommand extends Command
{
    protected $signature = 'eve:donations:recompute';

    protected $description = 'Rebuild eve_donor_benefits from eve_donations at the current ISK-per-day rate.';

    public function handle(DonorBenefitCalculator $calculator): int
    {
        $rate = (int) config('eve.donations.isk_per_day', 100_000);

        $this->info("Rebuilding donor benefits at {$rate} ISK / day…");

        $processed = $calculator->recomputeAll();

        $this->info("Done — recomputed {$processed} donor(s).");

        return self::SUCCESS;
    }
}
