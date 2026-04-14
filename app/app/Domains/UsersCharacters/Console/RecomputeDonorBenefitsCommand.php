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
 * When to run:
 *
 *   - Once after the `eve_donor_benefits` migration lands, to backfill
 *     benefit rows for donors whose donations were recorded before the
 *     poller grew incremental recompute (i.e. all donations pre-dating
 *     this feature).
 *   - Any time an operator changes `EVE_DONATIONS_ISK_PER_DAY`. The new
 *     rate is retroactive by design: you don't want past donors stuck
 *     on the old (possibly stingier) curve. A run of this command
 *     reseeds every donor's `ad_free_until` at the new rate.
 *
 * Not scheduled. Running it periodically doesn't help — the poll job
 * already recomputes per-donor on upsert, and expiry is time-based so
 * "is this donor currently covered?" is answered at query time without
 * any cron.
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
