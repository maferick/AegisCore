<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domains\UsersCharacters\Models\Character;
use App\Domains\UsersCharacters\Models\EveDonation;
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
        /** @var array<int, string> $adminIds */
        $adminIds = config('eve.sso.admin_character_ids', []);
        $characters = $this->characters()->pluck('character_id');

        if ($characters->isEmpty()) {
            // No linked character → operator-seeded account (phase-1
            // escape hatch). Allowed until we swap in DB roles.
            return true;
        }

        // At least one linked character must be on the allow-list.
        // `character_id` is bigint in the DB, env values arrive as
        // strings — compare both sides as strings to be safe.
        $normalized = array_map('strval', $adminIds);

        return $characters->map(fn ($id) => (string) $id)
            ->intersect($normalized)
            ->isNotEmpty();
    }

    /**
     * Has any of this user's linked characters ever donated ISK in-game
     * to the AegisCore donations character?
     *
     * The single side-effect of donating: the future ad-removal logic
     * gates on this predicate. There's no ad system yet, but recording
     * the linkage now means when ads land it's a one-line gate
     * (`if (! $user->isDonor()) { renderAds(); }`) instead of a
     * cross-cutting refactor.
     *
     * Implementation note: joins through `characters.character_id` →
     * `eve_donations.donor_character_id` rather than storing a
     * denormalised flag on `users`. Donors don't need an account to
     * donate — when they later log in via SSO the existing
     * upsertCharacterAndUser flow keys on the same character_id we
     * already stored in eve_donations, and this query starts returning
     * true automatically with no migration. Donor counts are small
     * (dozens), so the join is cheap.
     */
    public function isDonor(): bool
    {
        $characterIds = $this->characters()->pluck('character_id');
        if ($characterIds->isEmpty()) {
            return false;
        }

        return EveDonation::query()
            ->whereIn('donor_character_id', $characterIds)
            ->exists();
    }
}
