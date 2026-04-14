<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialised per-donor ad-free state — one row per distinct
 * `donor_character_id` in `eve_donations`.
 *
 * See App\Domains\UsersCharacters\Services\DonorBenefitCalculator for the
 * stacking algorithm and 2026_04_14_000004_create_eve_donor_benefits_table
 * for the schema rationale.
 *
 * This row is always rebuilt wholesale — never incrementally updated —
 * because stacking donations is order-dependent and cheap at donor-base
 * sizes that fit in one page of the wallet journal.
 */
class EveDonorBenefit extends Model
{
    protected $table = 'eve_donor_benefits';

    protected $fillable = [
        'donor_character_id',
        'ad_free_until',
        'total_isk_donated',
        'donations_count',
        'first_donated_at',
        'last_donated_at',
        'rate_isk_per_day',
        'recomputed_at',
    ];

    protected function casts(): array
    {
        return [
            'donor_character_id' => 'integer',
            // total_isk_donated stays a string — DECIMAL(20,2) loses
            // precision if cast through float. Matches EveDonation::amount.
            'donations_count' => 'integer',
            'rate_isk_per_day' => 'integer',
            'ad_free_until' => 'immutable_datetime',
            'first_donated_at' => 'immutable_datetime',
            'last_donated_at' => 'immutable_datetime',
            'recomputed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Is the ad-free window still open right now?
     *
     * Pure predicate — no side effects, no DB reads. Use this for
     * render-time decisions ("is this donor 'active' in the UI?")
     * rather than re-running the DB query.
     */
    public function isActive(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        /** @var CarbonImmutable $until */
        $until = $this->ad_free_until;

        return $until->greaterThan($now);
    }

    /**
     * Relation back to the Character model when an AegisCore user has
     * linked this donor character via SSO. Nullable relation (donor may
     * have never logged in).
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(
            Character::class,
            'donor_character_id',
            'character_id',
        );
    }
}
