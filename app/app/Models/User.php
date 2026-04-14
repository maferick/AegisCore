<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\EveDonorBenefit;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Characters linked to this user via EVE SSO login.
     *
     * Phase 1: 0 or 1 (the character the user logged in as).
     * Phase 2+: can grow when alt-linking lands.
     */
    public function characters(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    /**
     * Filament /admin access gate.
     *
     * Two ways in:
     *
     *   1. Any character linked to this user is listed in
     *      `EVE_SSO_ADMIN_CHARACTER_IDS` (config('eve.sso.admin_character_ids')).
     *      This is the normal path — EVE SSO login + env-based allow-list.
     *
     *   2. Fallback: email+password accounts seeded via `make filament-user`
     *      keep working. That's the only way to bootstrap the first admin
     *      before any EVE character is linked, and the escape hatch if SSO
     *      itself is broken.
     *
     * Fallback (2) is detected by "this user has no linked characters" —
     * a character-linked SSO user MUST pass the allow-list check. This
     * prevents a logged-in non-admin SSO user from inheriting the
     * operator-seed escape hatch.
     *
     * See docs/adr/0002-eve-sso-and-esi-client.md § Admin gate.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isAdmin();
    }

    /**
     * Pure-PHP admin predicate, shared by the Filament panel gate
     * (canAccessPanel) and feature-level policy services (e.g.
     * MarketHubAccessPolicy § ADR-0005).
     *
     * Two ways in, matching canAccessPanel's original logic:
     *
     *   1. Any linked character is listed in
     *      `EVE_SSO_ADMIN_CHARACTER_IDS`
     *      (config('eve.sso.admin_character_ids')).
     *   2. No linked characters at all — operator-seeded email+password
     *      escape hatch (phase-1). Allowed until we swap in DB roles.
     *
     * A character-linked SSO user MUST pass the allow-list check; the
     * "no characters" branch is reserved for bootstrap / break-glass
     * accounts, so a normal logged-in non-admin SSO user can never
     * inherit it.
     *
     * See docs/adr/0002-eve-sso-and-esi-client.md § Admin gate.
     */
    public function isAdmin(): bool
    {
        /** @var array<int, string> $adminIds */
        $adminIds = config('eve.sso.admin_character_ids', []);
        $characters = $this->characters()->pluck('character_id');

        if ($characters->isEmpty()) {
            return true;
        }

        $normalized = array_map('strval', $adminIds);

        return $characters->map(fn ($id) => (string) $id)
            ->intersect($normalized)
            ->isNotEmpty();
    }

    /**
     * Does this user currently have an active ad-free window from
     * donations?
     *
     * Returns true only while the donor's accumulated `ad_free_until`
     * (materialised in `eve_donor_benefits`) is still in the future.
     * Once that timestamp passes, the method flips back to false with
     * no cron or cleanup job — expiry is purely a query-time comparison
     * against `now()`.
     *
     * The donation → ad-free-days conversion rate lives in
     * `config('eve.donations.isk_per_day')` (env
     * `EVE_DONATIONS_ISK_PER_DAY`, default 100_000 ISK = 1 day).
     * `DonorBenefitCalculator` is the source of truth for the stacking
     * math; this method is only the read predicate.
     *
     * Implementation note: joins through `characters.character_id` →
     * `eve_donor_benefits.donor_character_id` rather than storing a
     * denormalised flag on `users`. Donors don't need an account to
     * donate — when they later log in via SSO the existing
     * upsertCharacterAndUser flow keys on the same character_id we
     * already stored in the benefit row, and this query starts
     * returning true automatically with no migration. Donor counts are
     * small (dozens), so the join is cheap.
     *
     * Ad-removal gate pattern (callers):
     *
     *     if (! $user->isDonor()) { renderAds(); }
     */
    public function isDonor(): bool
    {
        $characterIds = $this->characters()->pluck('character_id');
        if ($characterIds->isEmpty()) {
            return false;
        }

        return EveDonorBenefit::query()
            ->whereIn('donor_character_id', $characterIds)
            ->where('ad_free_until', '>', now())
            ->exists();
    }
}
