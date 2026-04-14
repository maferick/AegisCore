<?php

declare(strict_types=1);

namespace App\Domains\Markets\Models;

use App\Models\User;
use App\Reference\Models\Region;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Driver-table row for the Python market poller.
 *
 * Each row is one place we're polling orders from. Two flavours of
 * ownership per ADR-0004:
 *
 *   - `owner_user_id = null`  → platform default, admin-managed from
 *                               /admin/market-watched-locations. Jita
 *                               is seeded here and is undeletable (see
 *                               `booted()` below).
 *   - `owner_user_id = <id>`  → donor-owned, managed from
 *                               /account/settings. Deferred to the
 *                               donor self-service rollout step.
 *
 * Two `location_type` values per the migration's ENUM:
 *
 *   - `npc_station`       → region endpoint + location_id client-side
 *                           filter, no auth.
 *   - `player_structure`  → structure endpoint with service-token
 *                           auth (admin-owned) or donor-token auth
 *                           (donor-owned). Admin path is live in the
 *                           Python poller as of step 4a.
 *
 * Writes here trigger work on the next Python poller tick — the poller
 * reads `enabled = 1` rows only and does its own work via
 * `market_orders` + `outbox`. This model is pure Laravel-side
 * read/write for the admin UI; it never touches ESI directly.
 *
 * @property int             $id
 * @property string          $location_type  'npc_station' | 'player_structure'
 * @property int             $region_id
 * @property int             $location_id
 * @property string|null     $name
 * @property int|null        $owner_user_id
 * @property bool            $enabled
 * @property CarbonInterface|null $last_polled_at
 * @property int             $consecutive_failure_count
 * @property string|null     $last_error
 * @property CarbonInterface|null $last_error_at
 * @property string|null     $disabled_reason
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
class MarketWatchedLocation extends Model
{
    public const LOCATION_TYPE_NPC_STATION = 'npc_station';

    public const LOCATION_TYPE_PLAYER_STRUCTURE = 'player_structure';

    /** The permanent Jita 4-4 baseline row — seeded migration, undeletable. */
    public const JITA_REGION_ID = 10000002;

    public const JITA_LOCATION_ID = 60003760;

    protected $table = 'market_watched_locations';

    protected $fillable = [
        'location_type',
        'region_id',
        'location_id',
        'name',
        'owner_user_id',
        'enabled',
        'last_polled_at',
        'consecutive_failure_count',
        'last_error',
        'last_error_at',
        'disabled_reason',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'location_id' => 'integer',
            'owner_user_id' => 'integer',
            'enabled' => 'boolean',
            'last_polled_at' => 'datetime',
            'consecutive_failure_count' => 'integer',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * Belt-and-braces: Jita 4-4 is the platform baseline polling row
     * (ADR-0004 § Jita always-on). An accidental delete would interrupt
     * the canonical price feed until re-seeded, which is not the kind
     * of mistake a Filament "Delete" button should be able to cause.
     *
     * Guarding in the model means a tinker, artisan, or other code path
     * that bypasses the resource's UI-level protection still can't
     * delete the row — it has to go out of its way by using
     * `withoutEvents()` or a raw query.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $model): void {
            if (
                $model->owner_user_id === null
                && (int) $model->region_id === self::JITA_REGION_ID
                && (int) $model->location_id === self::JITA_LOCATION_ID
            ) {
                throw new DomainException(
                    'Jita 4-4 is the platform baseline and cannot be deleted. '
                    .'Toggle `enabled` if you want to pause polling.'
                );
            }
        });
    }

    // -- relations --------------------------------------------------------

    /**
     * The admin user who originally added the row, if any. NULL means
     * "platform default" — admin-managed rows don't track an
     * individual owner because they survive admin turnover.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Region this location sits in. Resolved from `ref_regions` — the
     * SDE-truncate-reload pattern means rows here can still be
     * displayed even if the region row is temporarily absent during
     * an SDE import.
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class, 'region_id');
    }

    // -- scopes -----------------------------------------------------------

    /** Platform-default (admin-managed) rows only. */
    public function scopePlatformOwned($query)
    {
        return $query->whereNull('owner_user_id');
    }

    /** Donor-owned rows only. */
    public function scopeDonorOwned($query)
    {
        return $query->whereNotNull('owner_user_id');
    }

    // -- predicates -------------------------------------------------------

    public function isJita(): bool
    {
        return $this->owner_user_id === null
            && (int) $this->region_id === self::JITA_REGION_ID
            && (int) $this->location_id === self::JITA_LOCATION_ID;
    }

    public function isNpcStation(): bool
    {
        return $this->location_type === self::LOCATION_TYPE_NPC_STATION;
    }

    public function isPlayerStructure(): bool
    {
        return $this->location_type === self::LOCATION_TYPE_PLAYER_STRUCTURE;
    }
}
