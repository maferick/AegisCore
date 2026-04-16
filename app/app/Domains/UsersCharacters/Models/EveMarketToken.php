<?php

declare(strict_types=1);

namespace App\Domains\UsersCharacters\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored EVE market-read token for a donor-owned structure.
 *
 * Fourth flavour of EVE token storage, completing the set ADR-0002 §
 * phase-2 amendment started:
 *
 *   1. Login token       — discarded after identity extraction.
 *   2. Service token     — eve_service_tokens, admin-authorised, broad
 *                          scope set for platform-default polling.
 *   3. Donations token   — eve_donations_tokens, single-character,
 *                          wallet-read scope only.
 *   4. Market token      — THIS table, donor-authorised, scope set
 *                          `publicData esi-search.search_structures.v1
 *                          esi-universe.read_structures.v1
 *                          esi-markets.structure_markets.v1`.
 *
 * Why a dedicated table (not a shared eve_tokens with a `kind`
 * column): same reason ADR-0002 gave for splitting donations from
 * service — schema-level boundaries catch SQL-typo-class bugs that a
 * WHERE clause cannot. The market poller queries this table by name;
 * it cannot accidentally reach for a service or donations token via
 * the wrong predicate.
 *
 * Ownership binding (ADR-0004 § Token ownership enforced at read/use):
 *
 *   - `user_id`     — the AegisCore user who authorised this token.
 *                     ON DELETE CASCADE at the DB level so the security
 *                     invariant "every market token traces to a live
 *                     user" holds even if an application code path
 *                     forgets.
 *   - `character_id` — the EVE character whose ACLs the token
 *                     embodies. UNIQUE so re-auth upserts in place.
 *
 * Before every ESI call, the Python poller asserts
 * `token.user_id == market_hub_collector.user_id` AND
 * `token.character_id ∈ user.characters` (ADR-0005). Mismatch is a
 * security violation: immediate collector disable, not routine
 * error handling.
 *
 * Access/refresh tokens ride Laravel's `'encrypted'` cast. APP_KEY is
 * the encryption key; a SELECT * leak is ciphertext. The Python
 * plane reads the envelope via `market_poller/laravel_encrypter.py`
 * (same AES-256-CBC + HMAC-SHA256 wire format).
 *
 * Refresh story: each donor's token refreshes independently. Phase 1
 * single-scheduler assumption means no distributed lock is needed yet
 * (same as donations — ADR-0002 § phase-2 amendment). When we scale
 * past one scheduler instance, add a row-level advisory lock on this
 * table's PK before the refresh call.
 *
 * @property int             $id
 * @property int             $user_id
 * @property int             $character_id
 * @property string          $character_name
 * @property array<int, string> $scopes
 * @property string          $access_token   Encrypted at rest.
 * @property string          $refresh_token  Encrypted at rest.
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class EveMarketToken extends Model
{
    protected $table = 'eve_market_tokens';

    protected $fillable = [
        'user_id',
        'character_id',
        'character_name',
        'scopes',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    /**
     * Hide the encrypted columns from default JSON serialisation so a
     * stray ->toArray() in a controller / Livewire view doesn't dump
     * tokens into a response. Caller has to ask for them by name.
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'character_id' => 'integer',
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The AegisCore user who authorised this token. Cascade-delete at
     * the DB level means this relation is always resolvable while the
     * row exists — enforced in the migration's foreign-key constraint.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Predicate for callers that want to know whether the access
     * token is fresh enough to use without a refresh round-trip. We
     * bias 60s into the future so a request that takes a moment to
     * fly to ESI doesn't 401 mid-flight.
     */
    public function isAccessTokenFresh(): bool
    {
        return $this->expires_at?->isAfter(now()->addSeconds(60)) ?? false;
    }

    /**
     * True when the granted scope set is a superset of the requested
     * scope. Cheaper than re-decoding the JWT for "do I have this
     * permission" checks.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
