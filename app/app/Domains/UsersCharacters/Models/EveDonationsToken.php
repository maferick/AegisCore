<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored EVE wallet-read token for the donations character.
 *
 * Mirrors EveServiceToken's encrypt-at-rest + $hidden pattern but uses
 * a separate table so the boundary is enforced at the schema level —
 * a buggy donations poller cannot reach for a service-character token
 * (or vice versa) even with a SQL typo. ADR-0002 § phase-2 amendment.
 *
 * Singleton: at most one row per stack. The donations SSO callback
 * upserts on `character_id` and rejects any character ID that doesn't
 * match `EVE_SSO_DONATIONS_CHARACTER_ID` from env, so even if the
 * authorising admin is logged into EVE as a different character, the
 * wrong-character token never lands here.
 *
 * Refresh: this is the first sustained-polling caller on the Laravel
 * plane. The donations poll command refreshes the access token via
 * the stored refresh token before each cycle when `isAccessTokenFresh()`
 * returns false. Refresh tokens themselves don't expire until CCP
 * rotates app secrets or the user revokes the app on
 * https://community.eveonline.com/support/third-party-applications/.
 *
 * Note: do NOT log $model->access_token / $model->refresh_token. The
 * cast hides the encryption from local code which makes accidental
 * structured-log inclusion very easy. If you find yourself needing
 * the raw value, ask why — usually you want a `usable()` predicate.
 *
 * @property int             $id
 * @property int             $character_id          EVE character ID; matches env locked value.
 * @property string          $character_name        Current EVE name; refreshed on re-auth.
 * @property array<string>   $scopes                JWT `scp` claim, normalised to string[].
 * @property string          $access_token          Encrypted at rest.
 * @property string          $refresh_token         Encrypted at rest.
 * @property CarbonInterface $expires_at            Absolute UTC instant the access token rolls over.
 * @property int|null        $authorized_by_user_id Audit trail; admin user who clicked Authorise.
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class EveDonationsToken extends Model
{
    protected $table = 'eve_donations_tokens';

    protected $fillable = [
        'character_id',
        'character_name',
        'scopes',
        'access_token',
        'refresh_token',
        'expires_at',
        'authorized_by_user_id',
    ];

    /**
     * Hide the encrypted columns from default JSON serialisation so a
     * stray ->toArray() in a controller / Filament page can't dump
     * tokens into a response. Caller has to ask for them by name.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'character_id' => 'integer',
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'authorized_by_user_id' => 'integer',
        ];
    }

    /**
     * Audit link — the admin who clicked "Authorise donations character"
     * to create (or rotate) this token.
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    /**
     * Predicate for the poll command — true when the access token has
     * enough lifetime left to use without a refresh round-trip. 60s
     * future-bias absorbs the time between this check and the actual
     * ESI call so we don't 401 mid-flight.
     */
    public function isAccessTokenFresh(): bool
    {
        return $this->expires_at?->isAfter(now()->addSeconds(60)) ?? false;
    }

    /**
     * Returns true when the token was granted the requested scope.
     * Cheaper than re-decoding the JWT for "do I have permission" checks.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
