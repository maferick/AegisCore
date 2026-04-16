<?php

declare(strict_types=1);

namespace App\Domains\Markets\Models;

use App\Reference\Models\Region;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Driver-table row for the Python market poller.
 *
 * Each row is one polling lane — one physical market we pull orders
 * from. Post-ADR-0005, every row points at a canonical
 * {@see MarketHub} row, and classification (platform-default vs
 * donor-registered) lives on the hub rather than on this table.
 *
 * Two `location_type` values per the migration's ENUM:
 *
 *   - `npc_station`       → region endpoint + location_id client-side
 *                           filter, no auth.
 *   - `player_structure`  → structure endpoint with either the admin
 *                           service token (public-reference hubs) or
 *                           a donor collector's token (private hubs,
 *                           via `market_hub_collectors`).
 *
 * Ownership / viewing rules now go through the hub overlay:
 *
 *   - "Platform default" == `hub.is_public_reference = true`. Polled
 *     by the service token (player_structure) or unauth'd (NPC).
 *   - "Donor-registered" == `hub.is_public_reference = false`. Polled
 *     by walking `market_hub_collectors` with within-tick failover.
 *   - "This donor's structures" is derived from the collector join —
 *     see {@see self::scopeForCollector()}. A hub may have several
 *     collectors (one per donor with docking rights) but only one
 *     watched row.
 *
 * Writes here trigger work on the next Python poller tick — the poller
 * reads `enabled = 1` rows with non-frozen hubs only, does its own
 * work via `market_orders` + `outbox`. This model is pure Laravel-side
 * read/write for the admin UI; it never touches ESI directly.
 *
 * @property int                  $id
 * @property string               $location_type  'npc_station' | 'player_structure'
 * @property int                  $region_id
 * @property int                  $location_id
 * @property int                  $hub_id
 * @property string|null          $name
 * @property bool                 $enabled
 * @property CarbonInterface|null $last_polled_at
 * @property int                  $consecutive_failure_count
 * @property string|null          $last_error
 * @property CarbonInterface|null $last_error_at
 * @property string|null          $disabled_reason
 * @property CarbonInterface      $created_at
 * @property CarbonInterface      $updated_at
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
        'hub_id',
        'name',
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
            'hub_id' => 'integer',
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
     * Guarding at the model means a tinker, artisan, or other code
     * path that bypasses the resource's UI-level protection still
     * can't delete the row — it has to go out of its way by using
     * `withoutEvents()` or a raw query. The Jita hub row itself has
     * the same guard in {@see MarketHub::booted()}.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $model): void {
            if (
                (int) $model->region_id === self::JITA_REGION_ID
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
     * Canonical hub this watched row drives. Carries the
     * public-reference flag + collector / entitlement tables.
     */
    public function hub(): BelongsTo
    {
        return $this->belongsTo(MarketHub::class, 'hub_id');
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

    /** Rows whose hub is a public-reference (NPC / admin baseline) hub. */
    public function scopePublicReference(Builder $query): Builder
    {
        return $query->whereHas('hub', fn (Builder $q) => $q->where('is_public_reference', true));
    }

    /** Rows whose hub is a private (donor-registered) hub. */
    public function scopePrivate(Builder $query): Builder
    {
        return $query->whereHas('hub', fn (Builder $q) => $q->where('is_public_reference', false));
    }

    /**
     * Watched rows attributable to a given donor — any hub the user is
     * a collector for. Replaces the pre-ADR-0005 "owner_user_id = X"
     * scoping used by /account/settings for listing + row-level
     * authorisation on remove.
     *
     * Users with no collector rows get an empty result set.
     */
    public function scopeForCollector(Builder $query, int $userId): Builder
    {
        return $query->whereHas(
            'hub.collectors',
            fn (Builder $q) => $q->where('user_id', $userId),
        );
    }

    // -- predicates -------------------------------------------------------

    public function isJita(): bool
    {
        return (int) $this->region_id === self::JITA_REGION_ID
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
