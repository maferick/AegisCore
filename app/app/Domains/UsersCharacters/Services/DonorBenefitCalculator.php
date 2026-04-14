<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Services;

use App\Domains\UsersCharacters\Models\EveDonation;
use App\Domains\UsersCharacters\Models\EveDonorBenefit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `eve_donor_benefits` rows from the raw `eve_donations` ledger.
 *
 * The conversion from ISK → ad-free-days is time-streaming, not a bulk
 * sum: the expiry timestamp depends on the order donations arrived and
 * whether each one landed inside or outside the previous window.
 *
 *     expiry[0] = donated_at[0]          + days_for(amount[0])
 *     expiry[i] = max(donated_at[i],
 *                     expiry[i-1])       + days_for(amount[i])
 *
 * Concretely:
 *
 *   - Donation inside an active window → extends the window by
 *     `amount / isk_per_day` days from the current expiry.
 *   - Donation after the window expired → starts a fresh window from
 *     the donation's own timestamp.
 *
 * `isk_per_day` is the operator-tunable rate from
 * `config('eve.donations.isk_per_day')` (env `EVE_DONATIONS_ISK_PER_DAY`,
 * default 100_000). Rate is captured on the row so a later rate change
 * can be detected and triggered to recompute.
 *
 * The calculator is always called per character (not batched). Donor
 * bases stay small (dozens), so a single-row recompute after each poll
 * tick is cheap enough that the complexity of a batched rebuild isn't
 * worth it.
 */
final class DonorBenefitCalculator
{
    public function __construct(
        private readonly int $iskPerDay,
    ) {}

    public static function fromConfig(): self
    {
        // Guard against an operator setting the rate to zero / negative —
        // would divide by zero or send expiry backwards. Fall back to the
        // documented default in that case so ad-free still works sanely.
        $configured = (int) config('eve.donations.isk_per_day', 100_000);

        return new self(
            iskPerDay: $configured > 0 ? $configured : 100_000,
        );
    }

    /**
     * Rebuild the benefit row for a single donor. Call this after any
     * upsert that touched this donor's donations — the poller does this
     * automatically for every character_id touched in a tick.
     *
     * Returns the resulting row, or null if the donor has no donations
     * (in which case any stale benefit row is deleted).
     */
    public function recomputeForCharacter(int $characterId): ?EveDonorBenefit
    {
        $donations = EveDonation::query()
            ->where('donor_character_id', $characterId)
            ->orderBy('donated_at')
            ->orderBy('journal_ref_id')
            ->get(['amount', 'donated_at', 'journal_ref_id']);

        if ($donations->isEmpty()) {
            // Donor might have been a row in the benefits table from a
            // previous run that has since been cleared out of donations
            // (manual cleanup). Keep the tables consistent.
            EveDonorBenefit::query()
                ->where('donor_character_id', $characterId)
                ->delete();

            return null;
        }

        /** @var CarbonImmutable|null $carry */
        $carry = null;
        $totalIsk = '0.00';

        foreach ($donations as $donation) {
            /** @var CarbonImmutable $donatedAt */
            $donatedAt = CarbonImmutable::parse($donation->donated_at);

            // Stack from whichever is later: the donation's own arrival
            // time, or the previous window's unused tail.
            $start = $carry !== null && $carry->greaterThan($donatedAt)
                ? $carry
                : $donatedAt;

            $carry = $start->addSeconds($this->donationToSeconds($donation->amount));
            $totalIsk = $this->addDecimal($totalIsk, (string) $donation->amount);
        }

        // $carry is guaranteed non-null here — $donations is non-empty.
        /** @var CarbonImmutable $adFreeUntil */
        $adFreeUntil = $carry;

        $first = $donations->first();
        $last = $donations->last();

        $row = EveDonorBenefit::query()->updateOrCreate(
            ['donor_character_id' => $characterId],
            [
                'ad_free_until' => $adFreeUntil,
                'total_isk_donated' => $totalIsk,
                'donations_count' => $donations->count(),
                'first_donated_at' => CarbonImmutable::parse($first->donated_at),
                'last_donated_at' => CarbonImmutable::parse($last->donated_at),
                'rate_isk_per_day' => $this->iskPerDay,
                'recomputed_at' => CarbonImmutable::now(),
            ],
        );

        return $row->fresh();
    }

    /**
     * Rebuild every benefit row from scratch. Used by the
     * `eve:donations:recompute` artisan command — after an operator
     * changes `EVE_DONATIONS_ISK_PER_DAY`, or as a one-time backfill
     * after this migration lands.
     *
     * Returns the number of donors processed.
     */
    public function recomputeAll(): int
    {
        $ids = EveDonation::query()
            ->select('donor_character_id')
            ->distinct()
            ->pluck('donor_character_id');

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $id) {
                $this->recomputeForCharacter((int) $id);
            }
        });

        return $ids->count();
    }

    /**
     * Convert a DECIMAL(20, 2) ISK amount to integer seconds of ad-free
     * time, at the configured rate.
     *
     *     seconds = (amount / isk_per_day) * 86400
     *
     * Integer-second precision is fine for "how long you're ad-free" —
     * clock-skew between donor/server timestamps is already in seconds.
     */
    private function donationToSeconds(string|float|int $amount): int
    {
        // Cast through float: EVE donation amounts are well under 2^53,
        // so the float round-trip is lossless in practice.
        $days = ((float) $amount) / (float) $this->iskPerDay;

        return (int) round($days * 86400.0);
    }

    /**
     * Add two DECIMAL(20,2) values as strings without losing precision
     * to float. Bcmath when available, fallback that handles the 2dp
     * case exactly otherwise.
     */
    private function addDecimal(string $a, string $b): string
    {
        if (function_exists('bcadd')) {
            return bcadd($a, $b, 2);
        }

        // Fallback: split on dot, add with integer math, recompose.
        [$intA, $fracA] = array_pad(explode('.', $a, 2), 2, '0');
        [$intB, $fracB] = array_pad(explode('.', $b, 2), 2, '0');
        $fracA = str_pad($fracA, 2, '0')[0] . str_pad($fracA, 2, '0')[1];
        $fracB = str_pad($fracB, 2, '0')[0] . str_pad($fracB, 2, '0')[1];
        $sum = ((int) $intA * 100 + (int) $fracA) + ((int) $intB * 100 + (int) $fracB);
        $whole = intdiv($sum, 100);
        $frac = str_pad((string) ($sum % 100), 2, '0', STR_PAD_LEFT);

        return "{$whole}.{$frac}";
    }
}
