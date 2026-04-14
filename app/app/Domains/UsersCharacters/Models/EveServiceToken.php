<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored EVE access + refresh token for a service character.
 *
 * Phase-2 work landing early — see ADR-0002 § Token kinds and the
 * phase-2 amendment. One row per authorised service character;
 * `character_id` is unique so re-auth upserts.
 *
 * The two token columns ride Laravel's `'encrypted'` cast — APP_KEY is
 * the encryption key. Reading either property transparently decrypts;
 * writing transparently re-encrypts before persisting. There is no
 * code path that writes plaintext into these columns, so a `SELECT *`
 * leak is encrypted ciphertext, not bearer tokens.
 *
 * Note: do NOT log $model->access_token / $model->refresh_token. The
 * cast hides the encryption from local code, which makes accidental
 * structured-log inclusion very easy. If you find yourself needing the
 * raw value, ask why — usually you want a `usable()` predicate or to
 * pass the model to a service that calls ESI on your behalf.
 *
 * @property int            $id
 * @property int            $character_id          EVE character ID (CCP's permanent ID).
 * @property string         $character_name        Current EVE name; refreshed on re-auth.
 * @property array<string>  $scopes                JWT `scp` claim, normalised to string[].
 * @property string         $access_token          Encrypted at rest.
 * @property string         $refresh_token         Encrypted at rest.
 * @property CarbonInterface $expires_at           Absolute UTC instant the access token rolls over.
 * @property int|null       $authorized_by_user_id Audit trail; admin user who clicked authorize.
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class EveServiceToken extends Model
{
    protected $table = 'eve_service_tokens';

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
     * stray ->toArray() in a controller / Filament resource doesn't dump
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
     * Audit link — the admin who clicked "Authorise" in /admin to create
     * (or rotate) this token.
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    /**
     * Predicate for callers that want to know whether the access token
     * is fresh enough to use without a refresh round-trip. We bias 60s
     * into the future so a request that takes a moment to fly to ESI
     * doesn't 401 mid-flight.
     */
    public function isAccessTokenFresh(): bool
    {
        return $this->expires_at?->isAfter(now()->addSeconds(60)) ?? false;
    }

    /**
     * Returns true when the granted scope set is a superset of the
     * requested scope. Cheaper than re-decoding the JWT for "do I have
     * this permission" checks.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
