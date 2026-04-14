<?php

declare(strict_types=1);

namespace App\Domains\Markets\Models;

use App\Domains\UsersCharacters\Models\EveMarketToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A token authorised to poll a given market hub.
 *
 * One-to-many against `market_hubs` — zero for public-reference hubs
 * (Jita), one-or-more for private (donor-registered) hubs. See
 * ADR-0005 § Failover for the operational model: the poller tries
 * the primary collector first, falls over to any other active
 * collector on failure, and only freezes the hub when zero active
 * collectors remain.
 *
 * Ownership binding mirrors eve_market_tokens:
 *
 *   - `user_id`      — the AegisCore user whose donation window
 *                      underwrites this collector. Accounting only;
 *                      does NOT confer viewer access.
 *   - `character_id` — the EVE character whose ACLs the token relies
 *                      on. Sourced from eve_market_tokens.character_id
 *                      (UNIQUE globally there).
 *   - `token_id`     — FK into eve_market_tokens, CASCADE on delete.
 *
 * @property int                  $id
 * @property int                  $hub_id
 * @property int                  $user_id
 * @property int                  $character_id
 * @property int                  $token_id
 * @property bool                 $is_primary
 * @property bool                 $is_active
 * @property CarbonInterface|null $last_verified_at
 * @property CarbonInterface|null $last_success_at
 * @property CarbonInterface|null $last_failure_at
 * @property string|null          $failure_reason
 * @property int                  $consecutive_failure_count
 * @property CarbonInterface      $created_at
 * @property CarbonInterface      $updated_at
 */
class MarketHubCollector extends Model
{
    protected $table = 'market_hub_collectors';

    protected $fillable = [
        'hub_id',
        'user_id',
        'character_id',
        'token_id',
        'is_primary',
        'is_active',
        'last_verified_at',
        'last_success_at',
        'last_failure_at',
        'failure_reason',
        'consecutive_failure_count',
    ];

    protected function casts(): array
    {
        return [
            'hub_id' => 'integer',
            'user_id' => 'integer',
            'character_id' => 'integer',
            'token_id' => 'integer',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
            'last_verified_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'consecutive_failure_count' => 'integer',
        ];
    }

    // -- relations --------------------------------------------------------

    public function hub(): BelongsTo
    {
        return $this->belongsTo(MarketHub::class, 'hub_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function token(): BelongsTo
    {
        return $this->belongsTo(EveMarketToken::class, 'token_id');
    }

    // -- scopes -----------------------------------------------------------

    /** Active collectors only — the poller's default filter. */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Ordering the poller uses when picking a collector:
     * primary first, then the one with the oldest last_failure_at
     * (i.e. freshest success / longest since a known problem).
     */
    public function scopePollOrder($query)
    {
        return $query->orderByDesc('is_primary')
            ->orderByRaw('last_failure_at IS NULL DESC')
            ->orderBy('last_failure_at');
    }
}
